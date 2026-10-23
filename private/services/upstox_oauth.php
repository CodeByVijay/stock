<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/settings_store.php';
require_once PRIVATE_ROOT . '/services/http_client.php';
require_once PRIVATE_ROOT . '/services/upstox.php';

function upstox_oauth_authorize_url(): string
{
    return 'https://api.upstox.com/v2/login/authorization/dialog';
}

function upstox_oauth_token_url(): string
{
    return 'https://api.upstox.com/v2/login/authorization/token';
}

function upstox_default_redirect_uri(): string
{
    return rtrim(app_base_url(), '/') . '/oauth/upstox/';
}

/**
 * Upstox access tokens expire at 03:30 IST on the next calendar day (per official docs).
 */
function upstox_compute_token_expiry_utc(): string
{
    $ist = new DateTimeZone('Asia/Kolkata');
    $now = new DateTimeImmutable('now', $ist);
    $cutoffToday = $now->setTime(3, 30, 0);
    if ($now < $cutoffToday) {
        $expires = $cutoffToday;
    } else {
        $expires = $cutoffToday->modify('+1 day');
    }

    return $expires->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** @return array{ok:bool,error:?string,url:?string} */
function upstox_oauth_begin(): array
{
    $cfg = upstox_config();
    $clientId = trim((string) ($cfg['client_id'] ?? ''));
    $redirectUri = trim((string) ($cfg['redirect_uri'] ?? ''));
    if ($redirectUri === '') {
        $redirectUri = upstox_default_redirect_uri();
    }

    if ($clientId === '') {
        return ['ok' => false, 'error' => 'Save your API Key (Client ID) and Redirect URI before authorizing.', 'url' => null];
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['upstox_oauth_state'] = $state;
    $_SESSION['upstox_oauth_redirect_uri'] = $redirectUri;

    $query = http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);

    return ['ok' => true, 'error' => null, 'url' => upstox_oauth_authorize_url() . '?' . $query];
}

/**
 * @return array{ok:bool,error:?string}
 */
function upstox_oauth_handle_callback(string $code, string $state): array
{
    $expected = (string) ($_SESSION['upstox_oauth_state'] ?? '');
    unset($_SESSION['upstox_oauth_state']);

    if ($expected === '' || !hash_equals($expected, $state)) {
        return ['ok' => false, 'error' => 'OAuth state mismatch. Please start authorization again.'];
    }

    $redirectUri = (string) ($_SESSION['upstox_oauth_redirect_uri'] ?? '');
    unset($_SESSION['upstox_oauth_redirect_uri']);
    if ($redirectUri === '') {
        $redirectUri = upstox_default_redirect_uri();
    }

    $code = trim($code);
    if ($code === '') {
        return ['ok' => false, 'error' => 'Authorization code was missing.'];
    }

    $cfg = upstox_config();
    $clientId = trim((string) ($cfg['client_id'] ?? ''));
    $clientSecret = trim((string) ($cfg['client_secret'] ?? ''));
    if ($clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'error' => 'Client ID and Client Secret must be saved before exchanging the code.'];
    }

    $body = http_build_query([
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ], '', '&', PHP_QUERY_RFC3986);

    $res = http_request('POST', upstox_oauth_token_url(), [
        'headers' => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        'body' => $body,
        'timeout' => (int) ($cfg['http_timeout_seconds'] ?? 30),
    ]);

    if (!$res['ok']) {
        $msg = 'Token exchange failed.';
        if (is_array($res['json']) && isset($res['json']['errors'][0]['message'])) {
            $msg = (string) $res['json']['errors'][0]['message'];
        } elseif ($res['status'] > 0) {
            $msg = 'Token exchange failed (HTTP ' . $res['status'] . ').';
        }

        return ['ok' => false, 'error' => $msg];
    }

    $json = $res['json'];
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Unexpected response from Upstox token endpoint.'];
    }

    $token = trim((string) ($json['access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'Upstox did not return an access token.'];
    }

    $local = settings_load_merged('upstox', settings_upstox_base_defaults());
    $local['access_token'] = $token;
    $local['redirect_uri'] = $redirectUri;
    $meta = is_array($local['meta'] ?? null) ? $local['meta'] : [];
    $meta['access_token_set_at'] = gmdate('c');
    $meta['access_token_expires_at'] = upstox_compute_token_expiry_utc();
    $meta['last_oauth_at'] = gmdate('c');
    $meta['last_connection_test_ok'] = null;
    $local['meta'] = $meta;

    if (!settings_write_local('upstox', $local)) {
        return ['ok' => false, 'error' => 'Token received but could not be saved securely.'];
    }

    upstox_config_reload();

    return ['ok' => true, 'error' => null];
}

/** @return array<string,mixed> */
function settings_upstox_base_defaults(): array
{
    $path = PRIVATE_ROOT . '/config/upstox.php';
    if (!is_readable($path)) {
        return [];
    }

    return require $path;
}

/** @return array{ok:bool,error:?string,public:array<string,mixed>} */
function settings_upstox_save(array $input): array
{
    $existing = upstox_config();

    $next = settings_preserve_secrets([
        'api_base' => trim((string) ($input['api_base'] ?? 'https://api.upstox.com')) ?: 'https://api.upstox.com',
        'client_id' => trim((string) ($input['client_id'] ?? '')),
        'client_secret' => trim((string) ($input['client_secret'] ?? '')),
        'access_token' => trim((string) ($input['access_token'] ?? '')),
        'redirect_uri' => trim((string) ($input['redirect_uri'] ?? '')),
        'http_timeout_seconds' => max(5, min(120, (int) ($input['http_timeout_seconds'] ?? 30))),
        'default_instrument_key' => trim((string) ($input['default_instrument_key'] ?? '')),
        'default_symbol' => trim((string) ($input['default_symbol'] ?? '')),
        'default_name' => trim((string) ($input['default_name'] ?? '')),
        'default_exchange' => trim((string) ($input['default_exchange'] ?? 'NSE')),
        'default_instrument_type' => trim((string) ($input['default_instrument_type'] ?? 'stock')),
        'meta' => is_array($existing['meta'] ?? null) ? $existing['meta'] : [],
    ], $existing, ['client_secret', 'access_token']);

    if ($next['redirect_uri'] === '') {
        $next['redirect_uri'] = upstox_default_redirect_uri();
    }

    if (!settings_write_local('upstox', $next)) {
        return ['ok' => false, 'error' => 'Could not save settings file.', 'public' => settings_public_upstox($existing)];
    }

    upstox_config_reload();
    $saved = upstox_config();

    return ['ok' => true, 'error' => null, 'public' => settings_public_upstox($saved)];
}

/** @return array{ok:bool,error:?string,detail:?array<string,mixed>} */
function settings_upstox_test_connection(): array
{
    $auth = upstox_verify_authentication();
    $cfg = upstox_config();
    $local = settings_load_merged('upstox', settings_upstox_base_defaults());
    $meta = is_array($local['meta'] ?? null) ? $local['meta'] : [];
    $meta['last_connection_test_at'] = gmdate('c');
    $meta['last_connection_test_ok'] = $auth['ok'];
    $meta['last_connection_test_message'] = $auth['ok']
        ? 'Connected to Upstox profile API.'
        : ($auth['error'] ?? 'Connection failed.');
    $local['meta'] = $meta;
    settings_write_local('upstox', $local);
    upstox_config_reload();

    if (!$auth['ok']) {
        return ['ok' => false, 'error' => $auth['error'] ?? 'Upstox authentication failed.', 'detail' => null];
    }

    $data = is_array($auth['data']) ? $auth['data'] : [];

    return [
        'ok' => true,
        'error' => null,
        'detail' => [
            'user_id' => $data['user_id'] ?? null,
            'user_name' => $data['user_name'] ?? null,
            'broker' => $data['broker'] ?? null,
        ],
    ];
}

/** @return array{ok:bool,error:?string} */
function settings_upstox_clear_credentials(): array
{
    $local = settings_load_merged('upstox', settings_upstox_base_defaults());
    $local['client_secret'] = '';
    $local['access_token'] = '';
    $meta = is_array($local['meta'] ?? null) ? $local['meta'] : [];
    $meta['access_token_set_at'] = '';
    $meta['access_token_expires_at'] = '';
    $meta['last_connection_test_ok'] = false;
    $local['meta'] = $meta;

    if (!settings_write_local('upstox', $local)) {
        return ['ok' => false, 'error' => 'Could not clear stored credentials.'];
    }

    upstox_config_reload();

    return ['ok' => true, 'error' => null];
}
