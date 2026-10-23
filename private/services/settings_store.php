<?php

declare(strict_types=1);

/**
 * Persist admin settings to private/config/*.local.php (outside version control).
 */

function settings_local_path(string $basename): string
{
    return PRIVATE_ROOT . '/config/' . $basename . '.local.php';
}

/** @return array<string,mixed> */
function settings_load_merged(string $basename, array $defaults): array
{
    $path = settings_local_path($basename);
    if (!is_readable($path)) {
        return $defaults;
    }
    $local = require $path;
    if (!is_array($local)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $local);
}

/** @param array<string,mixed> $data */
function settings_write_local(string $basename, array $data): bool
{
    $path = settings_local_path($basename);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $export = var_export($data, true);
    $php = "<?php\n/**\n * Admin-managed settings — do not commit secrets.\n * Generated " . gmdate('c') . "\n */\nreturn " . $export . ";\n";

    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $php, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }
    @chmod($path, 0600);

    return true;
}

/**
 * @param list<string> $secretKeys
 * @param array<string,mixed> $incoming
 * @param array<string,mixed> $existing
 * @return array<string,mixed>
 */
function settings_preserve_secrets(array $incoming, array $existing, array $secretKeys): array
{
    foreach ($secretKeys as $key) {
        if (!array_key_exists($key, $incoming)) {
            continue;
        }
        $val = is_string($incoming[$key]) ? trim($incoming[$key]) : $incoming[$key];
        if ($val === '' || $val === null) {
            if (array_key_exists($key, $existing)) {
                $incoming[$key] = $existing[$key];
            }
        }
    }

    return $incoming;
}

function settings_secret_configured(?string $value): bool
{
    return is_string($value) && trim($value) !== '';
}

/** @param array<string,mixed> $cfg */
function settings_public_mail(array $cfg): array
{
    return [
        'enabled' => !empty($cfg['enabled']),
        'host' => (string) ($cfg['host'] ?? ''),
        'port' => (int) ($cfg['port'] ?? 587),
        'username' => (string) ($cfg['username'] ?? ''),
        'encryption' => (string) ($cfg['encryption'] ?? 'tls'),
        'from_email' => (string) ($cfg['from_email'] ?? ''),
        'from_name' => (string) ($cfg['from_name'] ?? ''),
        'reply_to' => (string) ($cfg['reply_to'] ?? ''),
        'password_set' => settings_secret_configured($cfg['password'] ?? null),
        'meta' => is_array($cfg['meta'] ?? null) ? $cfg['meta'] : [],
    ];
}

/** @param array<string,mixed> $cfg */
function settings_public_upstox(array $cfg): array
{
    $tokenSet = settings_secret_configured($cfg['access_token'] ?? null);
    $expiresAt = (string) ($cfg['meta']['access_token_expires_at'] ?? '');
    $meta = is_array($cfg['meta'] ?? null) ? $cfg['meta'] : [];
    $lastTestOk = !empty($meta['last_connection_test_ok']);
    $savedRedirect = trim((string) ($cfg['redirect_uri'] ?? ''));
    if ($savedRedirect === '' && function_exists('upstox_default_redirect_uri')) {
        $savedRedirect = upstox_default_redirect_uri();
    }
    $recommendedRedirect = function_exists('upstox_default_redirect_uri') ? upstox_default_redirect_uri() : '';
    $redirectMismatch = $recommendedRedirect !== ''
        && rtrim($savedRedirect, '/') !== rtrim($recommendedRedirect, '/');

    return [
        'api_base' => (string) ($cfg['api_base'] ?? 'https://api.upstox.com'),
        'client_id' => (string) ($cfg['client_id'] ?? ''),
        'redirect_uri' => (string) ($cfg['redirect_uri'] ?? ''),
        'recommended_redirect_uri' => $recommendedRedirect,
        'redirect_uri_mismatch' => $redirectMismatch,
        'api_last_test_ok' => $lastTestOk,
        'default_instrument_key' => (string) ($cfg['default_instrument_key'] ?? ''),
        'default_symbol' => (string) ($cfg['default_symbol'] ?? ''),
        'default_name' => (string) ($cfg['default_name'] ?? ''),
        'default_exchange' => (string) ($cfg['default_exchange'] ?? 'NSE'),
        'default_instrument_type' => (string) ($cfg['default_instrument_type'] ?? 'stock'),
        'client_secret_set' => settings_secret_configured($cfg['client_secret'] ?? null),
        'access_token_set' => $tokenSet,
        'access_token_expires_at' => $expiresAt,
        'access_token_expired' => $tokenSet && $expiresAt !== ''
            && ($expTs = strtotime($expiresAt)) !== false && time() >= $expTs,
        'meta' => $meta,
    ];
}
