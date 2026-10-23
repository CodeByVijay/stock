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

$confirmed = isset($_POST['confirmed']) && (string) $_POST['confirmed'] === '1';
if (!$confirmed) {
    json_response(['ok' => false, 'error' => 'Confirmation required before sending test email.'], 422);
}

$result = settings_mail_send_test((string) ($_POST['test_recipient'] ?? ''));
$public = settings_public_mail(settings_mail_full());

json_response([
    'ok' => $result['ok'],
    'error' => $result['error'],
    'settings' => $public,
]);
