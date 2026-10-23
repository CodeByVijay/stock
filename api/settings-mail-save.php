<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once PRIVATE_ROOT . '/services/settings_mail.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();

$result = settings_mail_save([
    'enabled' => !empty($_POST['enabled']),
    'host' => $_POST['host'] ?? '',
    'port' => $_POST['port'] ?? 587,
    'username' => $_POST['username'] ?? '',
    'password' => $_POST['password'] ?? '',
    'encryption' => $_POST['encryption'] ?? 'tls',
    'from_email' => $_POST['from_email'] ?? '',
    'from_name' => $_POST['from_name'] ?? '',
    'reply_to' => $_POST['reply_to'] ?? '',
]);

json_response([
    'ok' => $result['ok'],
    'error' => $result['error'],
    'settings' => $result['public'],
]);
