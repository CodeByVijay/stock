<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/account.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/auth_page.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = flash_get('error');
$info = flash_get('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email address.');
        redirect('forgot-password.php');
    }

    try {
        account_request_password_reset(db(), $email);
    } catch (Throwable $e) {
        // Do not expose internals.
    }

    if (!mail_is_configured()) {
        flash_set(
            'success',
            'If an account exists for that email, a reset message would be sent — but SMTP is not configured. Sign in and change your password under Account, or configure private/config/mail.php.'
        );
    } else {
        flash_set(
            'success',
            'If an account exists for that email, you will receive reset instructions shortly.'
        );
    }
    redirect('forgot-password.php');
}

portal_auth_head('Forgot password');
portal_auth_card_open(
    'Forgot password',
    'Enter your login email. We do not reveal whether an account exists.'
);
?>
        <?php if ($error): ?>
            <div class="portal-alert portal-alert--error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($info): ?>
            <div class="portal-alert portal-alert--info" role="status"><?= e($info) ?></div>
        <?php endif; ?>
        <form method="post" class="portal-auth-form">
            <?= csrf_field() ?>
            <div class="portal-auth-field">
                <label for="email">Email address</label>
                <input type="email" name="email" id="email" required autocomplete="email"
                       class="portal-form-input" placeholder="you@company.com">
            </div>
            <button type="submit" class="portal-btn portal-btn--primary">
                Send reset link
            </button>
            <p class="portal-auth-foot">
                <a href="login.php" class="portal-link">Back to sign in</a>
            </p>
        </form>
<?php
portal_auth_card_close();
