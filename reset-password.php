<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/account.php';
require_once __DIR__ . '/includes/auth_page.php';

if (is_logged_in()) {
    redirect('index.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = flash_get('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $result = account_reset_password(
        db(),
        $token,
        (string) ($_POST['new_password'] ?? ''),
        (string) ($_POST['confirm_password'] ?? '')
    );
    if (!$result['ok']) {
        flash_set('error', $result['error'] ?? 'Reset failed.');
        redirect('reset-password.php?token=' . urlencode($token));
    }
    flash_set('success', 'Password updated. You can sign in now.');
    redirect('login.php');
}

portal_auth_head('Reset password');
portal_auth_card_open(
    'Choose a new password',
    'Use at least 10 characters. You will sign in with this password next.'
);
?>
        <?php if ($token === ''): ?>
            <p class="portal-auth-msg portal-alert portal-alert--error" role="alert">
                Missing reset token. Request a new link from the forgot password page.
            </p>
            <p class="portal-auth-foot">
                <a href="forgot-password.php" class="portal-link">Forgot password</a>
                · <a href="login.php" class="portal-link">Sign in</a>
            </p>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="portal-alert portal-alert--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" class="portal-auth-form">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="portal-auth-field">
                    <label for="new_password">New password</label>
                    <input type="password" name="new_password" id="new_password" required minlength="10"
                           autocomplete="new-password" class="portal-form-input">
                </div>
                <div class="portal-auth-field">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="10"
                           autocomplete="new-password" class="portal-form-input">
                </div>
                <button type="submit" class="portal-btn portal-btn--primary">
                    Reset password
                </button>
                <p class="portal-auth-foot">
                    <a href="login.php" class="portal-link">Back to sign in</a>
                </p>
            </form>
        <?php endif; ?>
<?php
portal_auth_card_close();
