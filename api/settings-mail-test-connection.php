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

$result = settings_mail_test_connection();
$public = settings_public_mail(settings_mail_full());

json_response([
    'ok' => $result['ok'],
    'error' => $result['error'],
    'settings' => $public,
]);
