<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/settings_store.php';
require_once APP_ROOT . '/includes/mail.php';

/** @return array<string,mixed> */
function settings_mail_defaults(): array
{
    $path = PRIVATE_ROOT . '/config/mail.php';
    if (!is_readable($path)) {
        return ['enabled' => false];
    }

    return require $path;
}

/** @return array<string,mixed> */
function settings_mail_full(): array
{
    return settings_load_merged('mail', settings_mail_defaults());
}

function settings_mail_reload(): void
{
    mail_config_reload();
}

/** @return array{ok:bool,error:?string,public:array<string,mixed>} */
function settings_mail_save(array $input): array
{
    $existing = settings_mail_full();
    $encryption = strtolower(trim((string) ($input['encryption'] ?? 'tls')));
    if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
        return ['ok' => false, 'error' => 'Invalid encryption type.', 'public' => settings_public_mail($existing)];
    }

    $port = (int) ($input['port'] ?? 587);
    if ($port < 1 || $port > 65535) {
        return ['ok' => false, 'error' => 'SMTP port must be between 1 and 65535.', 'public' => settings_public_mail($existing)];
    }

    $fromEmail = trim((string) ($input['from_email'] ?? ''));
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Sender email is not valid.', 'public' => settings_public_mail($existing)];
    }

    $replyTo = trim((string) ($input['reply_to'] ?? ''));
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Reply-To email is not valid.', 'public' => settings_public_mail($existing)];
    }

    $next = settings_preserve_secrets([
        'enabled' => !empty($input['enabled']),
        'host' => trim((string) ($input['host'] ?? '')),
        'port' => $port,
        'username' => trim((string) ($input['username'] ?? '')),
        'password' => trim((string) ($input['password'] ?? '')),
        'encryption' => $encryption,
        'from_email' => $fromEmail,
        'from_name' => trim((string) ($input['from_name'] ?? '')),
        'reply_to' => $replyTo,
        'meta' => is_array($existing['meta'] ?? null) ? $existing['meta'] : [],
    ], $existing, ['password']);

    if (!settings_write_local('mail', $next)) {
        return ['ok' => false, 'error' => 'Could not save settings file.', 'public' => settings_public_mail($existing)];
    }

    settings_mail_reload();
    $saved = settings_mail_full();

    return ['ok' => true, 'error' => null, 'public' => settings_public_mail($saved)];
}

/** @return array{ok:bool,error:?string} */
function settings_mail_test_connection(): array
{
    if (!mail_is_configured()) {
        return ['ok' => false, 'error' => 'SMTP is not fully configured (host and sender email required).'];
    }

    $cfg = settings_mail_full();
    $host = (string) $cfg['host'];
    $port = (int) ($cfg['port'] ?? 587);
    $encryption = strtolower((string) ($cfg['encryption'] ?? 'tls'));
    $user = (string) ($cfg['username'] ?? '');
    $pass = (string) ($cfg['password'] ?? '');

    $result = smtp_test_connection($host, $port, $encryption, $user, $pass);
    $meta = is_array($cfg['meta'] ?? null) ? $cfg['meta'] : [];
    $meta['last_connection_test_at'] = gmdate('c');
    $meta['last_connection_test_ok'] = $result['ok'];
    $meta['last_connection_test_message'] = $result['ok'] ? 'Connection and authentication succeeded.' : ($result['error'] ?? 'Connection failed.');
    $cfg['meta'] = $meta;
    settings_write_local('mail', $cfg);
    settings_mail_reload();

    return $result;
}

/** @return array{ok:bool,error:?string} */
function settings_mail_send_test(string $toEmail): array
{
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid test recipient email.'];
    }

    if (!mail_is_configured()) {
        return ['ok' => false, 'error' => 'SMTP is not fully configured.'];
    }

    $subject = 'Stock Research Portal — SMTP test';
    $body = "This is a test message sent at " . gmdate('c') . " UTC.\nIf you received this, SMTP settings are working.\n";

    $sent = mail_send($toEmail, $subject, $body);
    $cfg = settings_mail_full();
    $meta = is_array($cfg['meta'] ?? null) ? $cfg['meta'] : [];
    $meta['last_test_email_at'] = gmdate('c');
    $meta['last_test_email_to'] = $toEmail;
    $meta['last_test_email_ok'] = $sent;
    $cfg['meta'] = $meta;
    settings_write_local('mail', $cfg);
    settings_mail_reload();

    if (!$sent) {
        return ['ok' => false, 'error' => 'Test email could not be sent. Check SMTP host, credentials, and encryption.'];
    }

    return ['ok' => true, 'error' => null];
}
