<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/http_client.php';
require_once PRIVATE_ROOT . '/services/sync_log.php';

/** @return array<string,mixed> */
function upstox_config(): array
{
    if (isset($GLOBALS['_upstox_config_cache']) && is_array($GLOBALS['_upstox_config_cache'])) {
        return $GLOBALS['_upstox_config_cache'];
    }

    $path = PRIVATE_ROOT . '/config/upstox.php';
    if (!is_readable($path)) {
        throw new RuntimeException('Upstox configuration is missing. Create private/config/upstox.php.');
    }
    $config = require $path;
    $localPath = PRIVATE_ROOT . '/config/upstox.local.php';
    if (is_readable($localPath)) {
        $local = require $localPath;
        if (is_array($local)) {
            $config = array_replace_recursive($config, $local);
        }
    }

    $GLOBALS['_upstox_config_cache'] = $config;

    return $config;
}

function upstox_config_reload(): void
{
    unset($GLOBALS['_upstox_config_cache']);
}

function upstox_access_token(): string
{
    $env = getenv('UPSTOX_ACCESS_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $cfg = upstox_config();
    return trim((string) ($cfg['access_token'] ?? ''));
}

function upstox_auth_headers(): array
{
    $token = upstox_access_token();
    if ($token === '') {
        throw new RuntimeException('Upstox access token is not configured.');
    }

    return [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
}

/**
 * @param array{ok:bool,status:int,body:string,json:mixed,error:?string} $res
 */
function upstox_explain_http_failure(array $res): string
{
    $status = (int) ($res['status'] ?? 0);
    $body = (string) ($res['body'] ?? '');

    if ($status === 403 && (stripos($body, 'Blocked site') !== false || stripos($body, '<html') !== false)) {
        return 'HTTP 403 — this server cannot reach api.upstox.com (network or firewall block). '
            . 'Try from another network, allow outbound HTTPS to api.upstox.com, or use NSE/BSE CSV import instead of the brokerage API.';
    }

    if (is_array($res['json'] ?? null)) {
        $json = $res['json'];
        $msg = $json['errors'][0]['message'] ?? $json['message'] ?? null;
        if (is_string($msg) && trim($msg) !== '') {
            return 'HTTP ' . $status . ' — ' . trim($msg);
        }
    }

    if ($status === 401) {
        return 'HTTP 401 — access token is invalid or expired. Re-authorize under Settings → Upstox.';
    }

    return $res['error'] ?? ('HTTP ' . $status);
}

/**
 * Verify credentials by calling GET /v2/user/profile.
 *
 * @return array{ok:bool,status:int,error:?string,data:mixed}
 */
function upstox_verify_authentication(): array
{
    $cfg = upstox_config();
    $base = rtrim((string) ($cfg['api_base'] ?? ''), '/');
    $timeout = (int) ($cfg['http_timeout_seconds'] ?? 30);

    try {
        $headers = upstox_auth_headers();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'data' => null];
    }

    $res = http_get($base . '/v2/user/profile', $headers, $timeout);

    if (!$res['ok']) {
        $error = upstox_explain_http_failure($res);
        sync_log('upstox', 'auth_failed', ['status' => $res['status'], 'error' => $error]);
        return [
            'ok' => false,
            'status' => $res['status'],
            'error' => $error,
            'data' => $res['json'],
        ];
    }

    if (!is_array($res['json']) || ($res['json']['status'] ?? '') !== 'success') {
        return ['ok' => false, 'status' => $res['status'], 'error' => 'Unexpected profile response.', 'data' => $res['json']];
    }

    sync_log('upstox', 'auth_ok', ['user_id' => $res['json']['data']['user_id'] ?? null]);

    return ['ok' => true, 'status' => $res['status'], 'error' => null, 'data' => $res['json']['data'] ?? null];
}

/**
 * Initiate Upstox access-token request (user must approve in Upstox app).
 *
 * @return array{ok:bool,status:int,error:?string,data:mixed}
 */
function upstox_request_access_token(): array
{
    $cfg = upstox_config();
    $clientId = trim((string) ($cfg['client_id'] ?? ''));
    $clientSecret = trim((string) ($cfg['client_secret'] ?? ''));
    if ($clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'client_id and client_secret are required in private/config/upstox.php.', 'data' => null];
    }

    $base = rtrim((string) $cfg['api_base'], '/');
    $url = $base . '/v3/login/auth/token/request/' . rawurlencode($clientId);
    $timeout = (int) ($cfg['http_timeout_seconds'] ?? 30);

    $res = http_post_json($url, ['client_secret' => $clientSecret], ['Accept: application/json'], $timeout);

    if (!$res['ok']) {
        sync_log('upstox', 'token_request_failed', ['status' => $res['status'], 'error' => $res['error']]);
        return ['ok' => false, 'status' => $res['status'], 'error' => $res['error'] ?? 'Token request failed.', 'data' => $res['json']];
    }

    sync_log('upstox', 'token_request_sent', ['client_id' => $clientId]);

    return ['ok' => true, 'status' => $res['status'], 'error' => null, 'data' => $res['json']];
}

/**
 * Historical daily candles (V3).
 *
 * @return array{ok:bool,status:int,error:?string,candles:list}
 */
function upstox_fetch_daily_candles(string $instrumentKey, string $toDate, ?string $fromDate = null): array
{
    $cfg = upstox_config();
    $base = rtrim((string) $cfg['api_base'], '/');
    $timeout = (int) ($cfg['http_timeout_seconds'] ?? 30);

    $encodedKey = rawurlencode($instrumentKey);
    $path = '/v3/historical-candle/' . $encodedKey . '/days/1/' . $toDate;
    if ($fromDate !== null && $fromDate !== '') {
        $path .= '/' . $fromDate;
    }

    try {
        $headers = upstox_auth_headers();
    } catch (RuntimeException $e) {
        return ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'candles' => []];
    }

    $res = http_get($base . $path, $headers, $timeout);

    if (!$res['ok']) {
        sync_log('upstox', 'candles_failed', [
            'instrument_key' => $instrumentKey,
            'status' => $res['status'],
            'error' => $res['error'],
        ]);
        return ['ok' => false, 'status' => $res['status'], 'error' => $res['error'] ?? 'Historical candle request failed.', 'candles' => []];
    }

    if (!is_array($res['json']) || ($res['json']['status'] ?? '') !== 'success') {
        return ['ok' => false, 'status' => $res['status'], 'error' => 'Unexpected candle response.', 'candles' => []];
    }

    $candles = $res['json']['data']['candles'] ?? [];
    if (!is_array($candles)) {
        $candles = [];
    }

    return ['ok' => true, 'status' => $res['status'], 'error' => null, 'candles' => $candles];
}

function upstox_upsert_instrument(
    PDO $pdo,
    string $instrumentKey,
    string $symbol,
    string $name,
    string $exchange,
    string $instrumentType
): int {
    $type = in_array($instrumentType, ['stock', 'etf', 'other'], true) ? $instrumentType : 'stock';

    $find = $pdo->prepare(
        'SELECT id FROM instruments
         WHERE provider = :provider AND provider_instrument_key = :key LIMIT 1'
    );
    $find->execute(['provider' => 'upstox', 'key' => $instrumentKey]);
    $existing = $find->fetch();

    if ($existing) {
        $upd = $pdo->prepare(
            'UPDATE instruments SET symbol = :symbol, name = :name, exchange = :exchange,
             instrument_type = :type, is_active = 1 WHERE id = :id'
        );
        $upd->execute([
            'symbol' => $symbol,
            'name' => $name,
            'exchange' => $exchange,
            'type' => $type,
            'id' => (int) $existing['id'],
        ]);

        return (int) $existing['id'];
    }

    $ins = $pdo->prepare(
        'INSERT INTO instruments (symbol, name, exchange, instrument_type, provider, provider_instrument_key, is_active)
         VALUES (:symbol, :name, :exchange, :type, :provider, :key, 1)'
    );
    $ins->execute([
        'symbol' => $symbol,
        'name' => $name,
        'exchange' => $exchange,
        'type' => $type,
        'provider' => 'upstox',
        'key' => $instrumentKey,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param list<mixed> $candles Upstox candle arrays
 * @return array{inserted:int,updated:int,skipped:int}
 */
function upstox_upsert_daily_candles(PDO $pdo, int $instrumentId, array $candles, DateTimeInterface $fetchedAt): array
{
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    $stmt = $pdo->prepare(
        'INSERT INTO daily_prices (instrument_id, trade_date, open, high, low, close, volume, source, fetched_at)
         VALUES (:instrument_id, :trade_date, :open, :high, :low, :close, :volume, :source, :fetched_at)
         ON DUPLICATE KEY UPDATE
            open = VALUES(open),
            high = VALUES(high),
            low = VALUES(low),
            close = VALUES(close),
            volume = VALUES(volume),
            source = VALUES(source),
            fetched_at = VALUES(fetched_at)'
    );

    foreach ($candles as $candle) {
        if (!is_array($candle) || count($candle) < 5) {
            $skipped++;
            continue;
        }

        $ts = (string) $candle[0];
        $tradeDate = substr($ts, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tradeDate)) {
            $skipped++;
            continue;
        }

        $open = (float) $candle[1];
        $high = (float) $candle[2];
        $low = (float) $candle[3];
        $close = (float) $candle[4];
        $volume = isset($candle[5]) ? (int) $candle[5] : null;

        $stmt->execute([
            'instrument_id' => $instrumentId,
            'trade_date' => $tradeDate,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
            'source' => 'upstox',
            'fetched_at' => $fetchedAt->format('Y-m-d H:i:s'),
        ]);

        if ($stmt->rowCount() === 1) {
            $inserted++;
        } elseif ($stmt->rowCount() === 2) {
            $updated++;
        }
    }

    return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
}

/**
 * @return array{ok:bool,instrument_id:?int,stats:array,error:?string,candle_count:int}
 */
function upstox_sync_daily_candles(
    PDO $pdo,
    string $instrumentKey,
    string $symbol,
    string $name,
    string $exchange,
    string $instrumentType,
    string $toDate,
    ?string $fromDate = null
): array {
    $api = upstox_fetch_daily_candles($instrumentKey, $toDate, $fromDate);
    if (!$api['ok']) {
        return [
            'ok' => false,
            'instrument_id' => null,
            'stats' => [],
            'error' => $api['error'],
            'candle_count' => 0,
        ];
    }

    $fetchedAt = new DateTimeImmutable('now');

    try {
        $pdo->beginTransaction();
        $instrumentId = upstox_upsert_instrument($pdo, $instrumentKey, $symbol, $name, $exchange, $instrumentType);
        $stats = upstox_upsert_daily_candles($pdo, $instrumentId, $api['candles'], $fetchedAt);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sync_log('upstox', 'db_error', ['instrument_key' => $instrumentKey, 'message' => $e->getMessage()]);
        return ['ok' => false, 'instrument_id' => null, 'stats' => [], 'error' => 'Database error while storing candle data.', 'candle_count' => 0];
    }

    sync_log('upstox', 'candles_synced', [
        'instrument_key' => $instrumentKey,
        'instrument_id' => $instrumentId,
        'stats' => $stats,
        'from' => $fromDate,
        'to' => $toDate,
    ]);

    return [
        'ok' => true,
        'instrument_id' => $instrumentId,
        'stats' => $stats,
        'error' => null,
        'candle_count' => count($api['candles']),
    ];
}
