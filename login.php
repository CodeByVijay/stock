<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_page.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = flash_get('error');
$success = flash_get('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        flash_set('error', 'Please enter both email and password.');
        redirect('login.php');
    }
    if (attempt_login($email, $password)) {
        redirect('index.php');
    }
    redirect('login.php');
}

portal_auth_head('Sign in');
portal_auth_card_open(
    'Sign in',
    'Access your market data workspace with the email and password from your administrator.'
);
?>
        <?php if ($error): ?>
            <div class="portal-alert portal-alert--error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="portal-alert portal-alert--info" role="status"><?= e($success) ?></div>
        <?php endif; ?>
        <form method="post" action="login.php" class="portal-auth-form">
            <?= csrf_field() ?>
            <div class="portal-auth-field">
                <label for="email">Email address</label>
                <input type="email" name="email" id="email" required autocomplete="username"
                       class="portal-form-input" placeholder="you@company.com">
            </div>
            <div class="portal-auth-field">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required autocomplete="current-password"
                       class="portal-form-input" placeholder="Your password">
            </div>
            <button type="submit" class="portal-btn portal-btn--primary">
                Sign in
            </button>
            <p class="portal-auth-foot">
                <a href="forgot-password.php" class="portal-link">Forgot password?</a>
            </p>
        </form>
<?php
portal_auth_card_close();
