<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function mail_config(): array
{
    if (isset($GLOBALS['_mail_config_cache']) && is_array($GLOBALS['_mail_config_cache'])) {
        return $GLOBALS['_mail_config_cache'];
    }

    $path = PRIVATE_ROOT . '/config/mail.php';
    if (!is_readable($path)) {
        $config = ['enabled' => false];
    } else {
        $config = require $path;
    }
    $localPath = PRIVATE_ROOT . '/config/mail.local.php';
    if (is_readable($localPath)) {
        $local = require $localPath;
        if (is_array($local)) {
            $config = array_replace_recursive($config, $local);
        }
    }

    $GLOBALS['_mail_config_cache'] = $config;

    return $config;
}

function mail_config_reload(): void
{
    unset($GLOBALS['_mail_config_cache']);
}

function mail_is_configured(): bool
{
    $cfg = mail_config();
    if (empty($cfg['enabled'])) {
        return false;
    }
    $host = trim((string) ($cfg['host'] ?? ''));

    return $host !== '' && trim((string) ($cfg['from_email'] ?? '')) !== '';
}

function mail_send(string $to, string $subject, string $bodyText): bool
{
    if (!mail_is_configured()) {
        return false;
    }

    $cfg = mail_config();
    $fromEmail = (string) $cfg['from_email'];
    $fromName = (string) ($cfg['from_name']);

    $headers = [
        'From: ' . ($fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $replyTo = trim((string) ($cfg['reply_to'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $host = (string) $cfg['host'];
    $port = (int) ($cfg['port'] ?? 587);
    $user = (string) ($cfg['username'] ?? '');
    $pass = (string) ($cfg['password'] ?? '');
    $encryption = strtolower((string) ($cfg['encryption'] ?? 'tls'));

    if ($user === '' && $pass === '') {
        return @mail($to, $subject, $bodyText, implode("\r\n", $headers));
    }

    return smtp_send($host, $port, $encryption, $user, $pass, $fromEmail, $to, $subject, $bodyText);
}

function smtp_send(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    string $from,
    string $to,
    string $subject,
    string $body
): bool {
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20);
    if (!$fp) {
        mail_log_failure('connect', $errstr);
        return false;
    }

    stream_set_timeout($fp, 20);

    if (!smtp_expect($fp, [220])) {
        fclose($fp);
        return false;
    }
    smtp_cmd($fp, 'EHLO localhost');
    if ($encryption === 'tls') {
        if (!smtp_expect($fp, [250])) {
            fclose($fp);
            return false;
        }
        smtp_cmd($fp, 'STARTTLS');
        if (!smtp_expect($fp, [220])) {
            fclose($fp);
            return false;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            mail_log_failure('tls', 'TLS negotiation failed');
            fclose($fp);
            return false;
        }
        smtp_cmd($fp, 'EHLO localhost');
    }

    if ($username !== '') {
        if (!smtp_expect($fp, [250])) {
            fclose($fp);
            return false;
        }
        smtp_cmd($fp, 'AUTH LOGIN');
        if (!smtp_expect($fp, [334])) {
            fclose($fp);
            return false;
        }
        smtp_cmd($fp, base64_encode($username));
        if (!smtp_expect($fp, [334])) {
            fclose($fp);
            return false;
        }
        smtp_cmd($fp, base64_encode($password));
        if (!smtp_expect($fp, [235])) {
            mail_log_failure('auth', 'SMTP authentication failed');
            fclose($fp);
            return false;
        }
    }

    smtp_cmd($fp, 'MAIL FROM:<' . $from . '>');
    if (!smtp_expect($fp, [250])) {
        fclose($fp);
        return false;
    }
    smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
    if (!smtp_expect($fp, [250, 251])) {
        fclose($fp);
        return false;
    }
    smtp_cmd($fp, 'DATA');
    if (!smtp_expect($fp, [354])) {
        fclose($fp);
        return false;
    }

    $data = 'Subject: ' . $subject . "\r\n";
    $data .= 'From: ' . $from . "\r\n";
    $data .= 'To: ' . $to . "\r\n";
    $data .= "\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.\r\n";
    fwrite($fp, $data);
    if (!smtp_expect($fp, [250])) {
        fclose($fp);
        return false;
    }

    smtp_cmd($fp, 'QUIT');
    fclose($fp);

    return true;
}

/** @param resource $fp */
function smtp_cmd($fp, string $cmd): void
{
    fwrite($fp, $cmd . "\r\n");
}

/** @param resource $fp @param list<int> $codes */
function smtp_expect($fp, array $codes): bool
{
    $line = '';
    while ($str = fgets($fp, 515)) {
        $line .= $str;
        if (isset($str[3]) && $str[3] === ' ') {
            break;
        }
    }
    $code = (int) substr(trim($line), 0, 3);
    if (!in_array($code, $codes, true)) {
        mail_log_failure('smtp', 'Unexpected SMTP response: ' . trim($line));
        return false;
    }

    return true;
}

/**
 * Verify SMTP connectivity and optional AUTH without sending mail.
 *
 * @return array{ok:bool,error:?string}
 */
function smtp_test_connection(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password
): array {
    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP host is required.'];
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20);
    if (!$fp) {
        return ['ok' => false, 'error' => 'Could not connect to SMTP server.'];
    }

    stream_set_timeout($fp, 20);

    if (!smtp_expect($fp, [220])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'Unexpected greeting from SMTP server.'];
    }
    smtp_cmd($fp, 'EHLO localhost');
    if ($encryption === 'tls') {
        if (!smtp_expect($fp, [250])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'EHLO failed before STARTTLS.'];
        }
        smtp_cmd($fp, 'STARTTLS');
        if (!smtp_expect($fp, [220])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'STARTTLS was rejected.'];
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);

            return ['ok' => false, 'error' => 'TLS negotiation failed.'];
        }
        smtp_cmd($fp, 'EHLO localhost');
    }

    if ($username !== '') {
        if (!smtp_expect($fp, [250])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'EHLO failed before authentication.'];
        }
        smtp_cmd($fp, 'AUTH LOGIN');
        if (!smtp_expect($fp, [334])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'SMTP AUTH LOGIN not accepted.'];
        }
        smtp_cmd($fp, base64_encode($username));
        if (!smtp_expect($fp, [334])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'SMTP username rejected.'];
        }
        smtp_cmd($fp, base64_encode($password));
        if (!smtp_expect($fp, [235])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'SMTP authentication failed.'];
        }
    }

    smtp_cmd($fp, 'QUIT');
    fclose($fp);

    return ['ok' => true, 'error' => null];
}

function mail_log_failure(string $stage, string $message): void
{
    $dir = PRIVATE_ROOT . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = gmdate('c') . ' mail_' . $stage . ' ' . $message . PHP_EOL;
    @file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
}
