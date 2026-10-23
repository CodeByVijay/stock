<?php

declare(strict_types=1);

require_once APP_ROOT . '/includes/csrf.php';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;

function is_logged_in(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, display_name, email, session_version, is_active
             FROM admin_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $_SESSION['admin_id']]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }

    if (!$user || !(int) $user['is_active']) {
        return false;
    }

    $sessionVersion = (int) ($_SESSION['session_version'] ?? 0);
    if ($sessionVersion !== (int) $user['session_version']) {
        return false;
    }

    if (($_SESSION['admin_display_name'] ?? '') !== $user['display_name']) {
        $_SESSION['admin_display_name'] = $user['display_name'];
    }
    if (($_SESSION['admin_email'] ?? '') !== $user['email']) {
        $_SESSION['admin_email'] = $user['email'];
    }

    return true;
}

function auth_release_session_lock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('error', 'Please log in to continue.');
        redirect('login.php');
    }
}

/** Use on JSON API endpoints so polling does not block other tabs (session lock). */
function require_login_api(): void
{
    require_login();
    auth_release_session_lock();
}

function attempt_login(string $email, string $password): bool
{
    if (login_rate_limited()) {
        flash_set('error', 'Too many failed login attempts. Please try again later.');
        return false;
    }

    try {
        $pdo = db();
    } catch (Throwable $e) {
        flash_set('error', 'The application cannot reach the database. Check configuration and schema import.');
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, password_hash, session_version, is_active FROM admin_users WHERE email = :email LIMIT 1'
    );
    $stmt->execute(['email' => mb_strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
        log_security_event('login_failed', ['email' => mb_strtolower(trim($email))]);
        flash_set('error', 'Invalid email or password.');
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
    log_security_event('login_success', ['admin_id' => (int) $user['id']]);

    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function login_rate_limited(): bool
{
    $ip = client_ip();
    if ($ip === null) {
        return false;
    }

    $stmt = db()->prepare(
        "SELECT COUNT(*) AS cnt FROM security_logs
         WHERE event_type = 'login_failed'
           AND ip_address = :ip
           AND created_at > (NOW() - INTERVAL :mins MINUTE)"
    );
    $stmt->bindValue('ip', $ip);
    $stmt->bindValue('mins', LOGIN_WINDOW_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    return $row && (int) $row['cnt'] >= LOGIN_MAX_ATTEMPTS;
}

function current_admin_display_name(): string
{
    return (string) ($_SESSION['admin_display_name'] ?? 'Admin');
}
