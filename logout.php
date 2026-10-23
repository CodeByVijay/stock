<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    log_security_event('logout', ['admin_id' => (int) $_SESSION['admin_id']]);
}

logout_user();
flash_set('success', 'You have been logged out.');
redirect('login.php');
