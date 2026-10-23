<?php

declare(strict_types=1);

require_once APP_ROOT . '/includes/mail.php';

const RESET_TOKEN_TTL_HOURS = 2;
const RESET_REQUEST_MAX_PER_HOUR = 5;
const EMAIL_CHANGE_TTL_HOURS = 24;

function account_current(PDO $pdo): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['admin_id']]);

    return $stmt->fetch() ?: null;
}

function account_bump_session(PDO $pdo, int $adminId): void
{
    $pdo->prepare('UPDATE admin_users SET session_version = session_version + 1 WHERE id = :id')
        ->execute(['id' => $adminId]);
    if (!empty($_SESSION['admin_id']) && (int) $_SESSION['admin_id'] === $adminId) {
        $stmt = $pdo->prepare('SELECT session_version FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $adminId]);
        $_SESSION['session_version'] = (int) $stmt->fetchColumn();
    }
}

function account_update_display_name(PDO $pdo, int $adminId, string $name): void
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) {
        throw new InvalidArgumentException('Display name must be 1–100 characters.');
    }
    $pdo->prepare('UPDATE admin_users SET display_name = :name WHERE id = :id')
        ->execute(['name' => $name, 'id' => $adminId]);
    log_security_event('profile_name_updated', ['admin_id' => $adminId]);
}

function account_change_password(PDO $pdo, int $adminId, string $current, string $new, string $confirm): void
{
    if ($new !== $confirm) {
        throw new InvalidArgumentException('New passwords do not match.');
    }
    if (strlen($new) < 10) {
        throw new InvalidArgumentException('New password must be at least 10 characters.');
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $adminId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        log_security_event('password_change_failed', ['admin_id' => $adminId]);
        throw new InvalidArgumentException('Current password is incorrect.');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id')
        ->execute(['hash' => $hash, 'id' => $adminId]);

    $pdo->prepare(
        'UPDATE password_reset_tokens SET used_at = NOW()
         WHERE admin_user_id = :id AND used_at IS NULL'
    )->execute(['id' => $adminId]);

    account_bump_session($pdo, $adminId);
    log_security_event('password_changed', ['admin_id' => $adminId]);
}

function account_reset_rate_limited(): bool
{
    $ip = client_ip();
    if ($ip === null) {
        return false;
    }
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM security_logs
         WHERE event_type = 'password_reset_requested'
           AND ip_address = :ip
           AND created_at > (NOW() - INTERVAL 1 HOUR)"
    );
    $stmt->execute(['ip' => $ip]);

    return (int) $stmt->fetchColumn() >= RESET_REQUEST_MAX_PER_HOUR;
}

function account_request_password_reset(PDO $pdo, string $email): void
{
    if (account_reset_rate_limited()) {
        log_security_event('password_reset_rate_limited', []);
        return;
    }

    $email = mb_strtolower(trim($email));
    log_security_event('password_reset_requested', ['email' => $email]);

    $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email AND is_active = 1 LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = (new DateTimeImmutable('now'))->modify('+' . RESET_TOKEN_TTL_HOURS . ' hours');

    $pdo->prepare(
        'INSERT INTO password_reset_tokens (admin_user_id, token_hash, expires_at)
         VALUES (:uid, :hash, :exp)'
    )->execute([
        'uid' => (int) $user['id'],
        'hash' => $hash,
        'exp' => $expires->format('Y-m-d H:i:s'),
    ]);

    if (!mail_is_configured()) {
        mail_log_failure('reset', 'Password reset requested but SMTP is not configured.');
        return;
    }

    $link = app_base_url() . '/reset-password.php?token=' . urlencode($token);
    $sent = mail_send(
        $email,
        'Password reset — Stock Research Portal',
        "Use this link within " . RESET_TOKEN_TTL_HOURS . " hours to reset your password:\n\n" . $link . "\n\nIf you did not request this, ignore this email."
    );
    if (!$sent) {
        mail_log_failure('reset', 'Failed to send password reset email.');
    }
}

/** @return array{ok:bool,error:?string} */
function account_reset_password(PDO $pdo, string $token, string $newPassword, string $confirm): array
{
    if ($newPassword !== $confirm) {
        return ['ok' => false, 'error' => 'Passwords do not match.'];
    }
    if (strlen($newPassword) < 10) {
        return ['ok' => false, 'error' => 'Password must be at least 10 characters.'];
    }
    if ($token === '') {
        return ['ok' => false, 'error' => 'Invalid reset token.'];
    }

    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT prt.id AS token_id, prt.admin_user_id, prt.expires_at, prt.used_at
         FROM password_reset_tokens prt
         WHERE prt.token_hash = :hash LIMIT 1'
    );
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch();
    if (!$row || $row['used_at'] !== null) {
        return ['ok' => false, 'error' => 'This reset link is invalid or already used.'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'This reset link has expired.'];
    }

    $adminId = (int) $row['admin_user_id'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $adminId]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $row['token_id']]);
        account_bump_session($pdo, $adminId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Could not reset password.'];
    }

    log_security_event('password_reset_completed', ['admin_id' => $adminId]);

    return ['ok' => true, 'error' => null];
}

function account_request_email_change(PDO $pdo, int $adminId, string $currentPassword, string $newEmail): void
{
    if (!mail_is_configured()) {
        throw new RuntimeException('Email change requires SMTP. Configure private/config/mail.php.');
    }

    $newEmail = mb_strtolower(trim($newEmail));
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email address.');
    }

    $user = account_current($pdo);
    if (!$user || (int) $user['id'] !== $adminId) {
        throw new RuntimeException('Account not found.');
    }
    if (!password_verify($currentPassword, $user['password_hash'])) {
        log_security_event('email_change_failed', ['admin_id' => $adminId]);
        throw new InvalidArgumentException('Current password is incorrect.');
    }

    $exists = $pdo->prepare('SELECT id FROM admin_users WHERE email = :email AND id != :id LIMIT 1');
    $exists->execute(['email' => $newEmail, 'id' => $adminId]);
    if ($exists->fetch()) {
        throw new InvalidArgumentException('That email is already in use.');
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = (new DateTimeImmutable('now'))->modify('+' . EMAIL_CHANGE_TTL_HOURS . ' hours');

    $pdo->prepare(
        'UPDATE admin_users SET pending_email = :pe, pending_email_token_hash = :th, pending_email_expires_at = :exp WHERE id = :id'
    )->execute([
        'pe' => $newEmail,
        'th' => $tokenHash,
        'exp' => $expires->format('Y-m-d H:i:s'),
        'id' => $adminId,
    ]);

    $link = app_base_url() . '/verify-email-change.php?token=' . urlencode($token);
    $sent = mail_send(
        $newEmail,
        'Confirm email change — Stock Research Portal',
        "Confirm your new login email by opening this link within " . EMAIL_CHANGE_TTL_HOURS . " hours:\n\n" . $link
    );
    if (!$sent) {
        throw new RuntimeException('Verification email could not be sent. Check SMTP settings and mail.log.');
    }

    log_security_event('email_change_requested', ['admin_id' => $adminId]);
}

/** @return array{ok:bool,error:?string} */
function account_confirm_email_change(PDO $pdo, string $token): array
{
    if ($token === '') {
        return ['ok' => false, 'error' => 'Invalid verification link.'];
    }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT id, pending_email, pending_email_expires_at FROM admin_users
         WHERE pending_email_token_hash = :hash LIMIT 1'
    );
    $stmt->execute(['hash' => $hash]);
    $user = $stmt->fetch();
    if (!$user || empty($user['pending_email'])) {
        return ['ok' => false, 'error' => 'Invalid or expired verification link.'];
    }
    if (strtotime((string) $user['pending_email_expires_at']) < time()) {
        return ['ok' => false, 'error' => 'Verification link has expired.'];
    }

    $adminId = (int) $user['id'];
    $newEmail = (string) $user['pending_email'];

    try {
        $pdo->prepare(
            'UPDATE admin_users SET email = :email, email_verified_at = NOW(),
             pending_email = NULL, pending_email_token_hash = NULL, pending_email_expires_at = NULL
             WHERE id = :id'
        )->execute(['email' => $newEmail, 'id' => $adminId]);
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Could not update email. It may already be registered.'];
    }

    account_bump_session($pdo, $adminId);
    log_security_event('email_change_confirmed', ['admin_id' => $adminId]);

    return ['ok' => true, 'error' => null];
}

/** @return list<array<string,mixed>> */
function account_recent_security_logs(PDO $pdo, int $limit = 25): array
{
    $stmt = $pdo->prepare(
        'SELECT event_type, created_at, ip_address FROM security_logs
         ORDER BY id DESC LIMIT :lim'
    );
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}
