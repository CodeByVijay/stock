<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/http_client.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';
require_once PRIVATE_ROOT . '/services/sync_log.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';

/** Cash-equity segments in Upstox BOD JSON (derivatives use *_FO, indices use *_INDEX). */
const UPSTOX_EQ_SEGMENTS = ['NSE_EQ', 'BSE_EQ'];

/** @return list<string> */
function upstox_derivative_instrument_types(): array
{
    return ['FUT', 'CE', 'PE', 'OPT', 'FUTIDX', 'FUTSTK', 'FUTCOM', 'INDEX'];
}

/**
 * BSE cash-market series codes (Upstox uses BSE India series in instrument_type, not "EQ").
 * @see Upstox instrument search API — BSE_EQ example uses instrument_type "A".
 */
/** @return list<string> */
function upstox_bse_cash_equity_series_types(): array
{
    return ['A', 'B', 'T', 'M', 'X', 'Z', 'P', 'G', 'E', 'R', 'S', 'MT', 'XT', 'TS', 'W', 'ZP'];
}

/** Official BOD instrument files (no OAuth required). @see https://upstox.com/developer/api-documentation/instruments */
function upstox_instruments_exchange_url(string $exchange): string
{
    $ex = strtoupper(trim($exchange));
    $cfg = providers_config();
    if ($ex === 'NSE') {
        $custom = trim((string) ($cfg['upstox_instruments_nse_url'] ?? ''));

        return $custom !== '' ? $custom : 'https://assets.upstox.com/market-quote/instruments/exchange/NSE.json.gz';
    }
    if ($ex === 'BSE') {
        $custom = trim((string) ($cfg['upstox_instruments_bse_url'] ?? ''));

        return $custom !== '' ? $custom : 'https://assets.upstox.com/market-quote/instruments/exchange/BSE.json.gz';
    }

    throw new InvalidArgumentException('Exchange must be NSE or BSE.');
}

/** @return array{ok:bool,error:?string,body:string,status:int} */
function upstox_instruments_download_gz(string $url, int $timeout = 120): array
{
    if (!extension_loaded('curl')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is not enabled.', 'body' => '', 'status' => 0];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, application/octet-stream, */*',
            'User-Agent: StockResearchPortal/1.0 (instrument-master-import)',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        return ['ok' => false, 'error' => $err !== '' ? $err : 'Empty response downloading instrument file.', 'body' => '', 'status' => $status];
    }

    if ($status < 200 || $status >= 300) {
        $hint = upstox_instruments_http_hint($status, (string) $body);

        return ['ok' => false, 'error' => $hint, 'body' => (string) $body, 'status' => $status];
    }

    return ['ok' => true, 'error' => null, 'body' => (string) $body, 'status' => $status];
}

function upstox_instruments_http_hint(int $status, string $body): string
{
    if ($status === 403 && (stripos($body, 'Blocked') !== false || stripos($body, '<html') !== false)) {
        return 'HTTP 403 — instrument file blocked from this server/network. '
            . 'Download NSE.json.gz manually from Upstox docs, set providers.php → upstox_instruments_nse_gz_path, or use NSE/BSE CSV paths.';
    }

    return 'HTTP ' . $status . ' downloading Upstox instrument file.';
}

/**
 * @return array{ok:bool,error:?string,rows:list<array<string,mixed>>}
 */
function upstox_instruments_load_exchange_file(string $exchange): array
{
    $cfg = providers_config();
    $ex = strtoupper(trim($exchange));
    $localKey = $ex === 'NSE' ? 'upstox_instruments_nse_gz_path' : 'upstox_instruments_bse_gz_path';
    $localPath = trim((string) ($cfg[$localKey] ?? ''));

    $raw = '';
    $source = '';

    if ($localPath !== '') {
        if (!is_readable($localPath)) {
            return ['ok' => false, 'error' => 'Local instrument file not readable: ' . $localPath, 'rows' => []];
        }
        $raw = (string) file_get_contents($localPath);
        $source = 'local:' . $localPath;
    } else {
        $url = upstox_instruments_exchange_url($ex);
        $dl = upstox_instruments_download_gz($url);
        if (!$dl['ok']) {
            return ['ok' => false, 'error' => $dl['error'], 'rows' => []];
        }
        $raw = $dl['body'];
        $source = 'url:' . $url;
    }

    $jsonText = upstox_instruments_gunzip_if_needed($raw);
    if ($jsonText === null) {
        return ['ok' => false, 'error' => 'Could not decompress or decode instrument file (expected gzip JSON).', 'rows' => []];
    }

    try {
        $decoded = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['ok' => false, 'error' => 'Invalid JSON in instrument file: ' . $e->getMessage(), 'rows' => []];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Instrument file root must be a JSON array.', 'rows' => []];
    }

    sync_log('upstox', 'instruments_file_loaded', [
        'exchange' => $ex,
        'source' => $source,
        'row_count' => count($decoded),
    ]);

    return ['ok' => true, 'error' => null, 'rows' => $decoded];
}

function upstox_instruments_gunzip_if_needed(string $raw): ?string
{
    if ($raw === '') {
        return null;
    }
    if ($raw[0] === '{') {
        return $raw;
    }
    if ($raw[0] !== "\x1f") {
        return null;
    }
    $inflated = @gzdecode($raw);
    if ($inflated === false) {
        return null;
    }

    return $inflated;
}

/**
 * Summarize raw BOD file (no secrets).
 *
 * @param list<array<string,mixed>> $rows
 * @return array{total:int,by_segment:array<string,int>,by_instrument_type:array<string,int>,by_segment_type:array<string,int>}
 */
function upstox_instruments_summarize_file(array $rows): array
{
    $bySegment = [];
    $byType = [];
    $byBoth = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $seg = strtoupper(trim((string) ($row['segment'] ?? '')));
        $typ = strtoupper(trim((string) ($row['instrument_type'] ?? '')));
        if ($seg === '') {
            $seg = '(empty)';
        }
        if ($typ === '') {
            $typ = '(empty)';
        }
        $bySegment[$seg] = ($bySegment[$seg] ?? 0) + 1;
        $byType[$typ] = ($byType[$typ] ?? 0) + 1;
        $key = $seg . ' · ' . $typ;
        $byBoth[$key] = ($byBoth[$key] ?? 0) + 1;
    }

    arsort($bySegment);
    arsort($byType);
    arsort($byBoth);

    return [
        'total' => count($rows),
        'by_segment' => array_slice($bySegment, 0, 25, true),
        'by_instrument_type' => array_slice($byType, 0, 25, true),
        'by_segment_type' => array_slice($byBoth, 0, 30, true),
    ];
}

/**
 * Upstox NSE BOD often lists ETFs as NSE_EQ · EQ (not instrument_type ETF).
 *
 * @param array<string,mixed> $row
 */
function upstox_row_looks_like_etf(array $row): bool
{
    $instType = strtoupper(trim((string) ($row['instrument_type'] ?? '')));
    if ($instType === 'ETF') {
        return true;
    }

    foreach (['asset_type', 'underlying_type', 'security_type'] as $field) {
        $v = strtolower(trim((string) ($row[$field] ?? '')));
        if ($v !== '' && str_contains($v, 'etf')) {
            return true;
        }
    }

    $name = strtolower(trim((string) ($row['name'] ?? '')));
    if ($name !== '') {
        if (preg_match('/\betf\b|exchange traded fund|exchange-traded fund/i', $name)) {
            return true;
        }
        if (preg_match('/\bbees\b|\bgoldbees\b|\bniftybees\b/i', $name)) {
            return true;
        }
    }

    $symbol = upstox_instruments_resolve_trading_symbol($row);
    if ($symbol !== '' && preg_match('/BEES$|ETF$/i', $symbol)) {
        return true;
    }

    return false;
}

/**
 * @param array<string,mixed>|null $row Full BOD row (required for ETF detection on NSE_EQ · EQ).
 * @return array{ok:bool,reason:?string}
 */
function upstox_instruments_matches_asset_kind(string $segment, string $instType, string $assetKind, ?array $row = null): array
{
    if (!in_array($segment, UPSTOX_EQ_SEGMENTS, true)) {
        return ['ok' => false, 'reason' => 'unsupported_segment'];
    }

    if (in_array($instType, upstox_derivative_instrument_types(), true)) {
        return ['ok' => false, 'reason' => 'derivative_or_index_type'];
    }

    if ($assetKind === 'stock') {
        if ($instType === 'ETF') {
            return ['ok' => false, 'reason' => 'unsupported_instrument_type'];
        }
        if ($row !== null && upstox_row_looks_like_etf($row)) {
            return ['ok' => false, 'reason' => 'etf_not_stock'];
        }
        if ($segment === 'NSE_EQ') {
            return $instType === 'EQ'
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'unsupported_instrument_type'];
        }
        if ($segment === 'BSE_EQ') {
            if ($instType === 'EQ') {
                return ['ok' => true, 'reason' => null];
            }
            if (in_array($instType, upstox_bse_cash_equity_series_types(), true)) {
                return ['ok' => true, 'reason' => null];
            }

            return ['ok' => false, 'reason' => 'unsupported_instrument_type'];
        }
    }

    if ($assetKind === 'etf') {
        if ($instType === 'ETF') {
            return ['ok' => true, 'reason' => null];
        }
        if ($row !== null && upstox_row_looks_like_etf($row)) {
            return ['ok' => true, 'reason' => null];
        }

        return ['ok' => false, 'reason' => 'not_etf'];
    }

    return ['ok' => false, 'reason' => 'unsupported_instrument_type'];
}

/**
 * @param array<string,mixed> $row
 */
function upstox_instruments_resolve_trading_symbol(array $row): string
{
    foreach (['trading_symbol', 'tradingsymbol', 'short_name', 'symbol', 'asset_symbol'] as $field) {
        $v = strtoupper(trim((string) ($row[$field] ?? '')));
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

/**
 * @param array<string,mixed> $row
 * @return array{row:?array<string,mixed>,skip_reason:?string}
 */
function upstox_instruments_map_row(array $row, string $assetKind): array
{
    $segment = strtoupper(trim((string) ($row['segment'] ?? '')));
    $instType = strtoupper(trim((string) ($row['instrument_type'] ?? '')));
    $exchange = strtoupper(trim((string) ($row['exchange'] ?? '')));
    $instrumentKey = trim((string) ($row['instrument_key'] ?? ''));
    $symbol = upstox_instruments_resolve_trading_symbol($row);
    $name = trim((string) ($row['name'] ?? $symbol));

    if ($instrumentKey === '') {
        return ['row' => null, 'skip_reason' => 'missing_instrument_key'];
    }
    if ($symbol === '') {
        return ['row' => null, 'skip_reason' => 'missing_symbol'];
    }
    if ($exchange === '') {
        return ['row' => null, 'skip_reason' => 'missing_exchange'];
    }

    $kindCheck = upstox_instruments_matches_asset_kind($segment, $instType, $assetKind, $row);
    if (!$kindCheck['ok']) {
        return ['row' => null, 'skip_reason' => $kindCheck['reason'] ?? 'unsupported_instrument_type'];
    }

    if ($assetKind === 'stock' && instrument_is_non_equity_cash_listing($name, $symbol, $segment . '/' . $instType)) {
        return ['row' => null, 'skip_reason' => 'non_equity_cash_listing'];
    }

    $portalType = $assetKind === 'etf' ? 'etf' : 'stock';

    return [
        'row' => [
            'exchange' => $exchange,
            'symbol' => $symbol,
            'name' => $name !== '' ? $name : $symbol,
            'isin' => trim((string) ($row['isin'] ?? '')) ?: null,
            'instrument_type' => $portalType,
            'provider' => 'upstox',
            'provider_instrument_key' => $instrumentKey,
            'listing_status' => 'active',
            'security_category' => $segment . '/' . $instType,
            'master_source' => 'upstox_bod_json',
        ],
        'skip_reason' => null,
    ];
}

/** @param array<string,int> $skipReasons */
function upstox_instruments_format_skip_summary(array $skipReasons): string
{
    if ($skipReasons === []) {
        return '';
    }
    arsort($skipReasons);
    $parts = [];
    foreach ($skipReasons as $reason => $count) {
        $parts[] = $reason . ': ' . $count;
    }

    return implode('; ', $parts);
}

/**
 * @return array{discovered:int,inserted:int,updated:int,skipped:int,failed:int,errors:list<string>,source:string,exchange:string,asset_kind:string,file_summary:array<string,mixed>,skip_reasons:array<string,int>}
 */
function upstox_import_instruments(PDO $pdo, string $exchange, string $assetKind, ?int $jobId = null): array
{
    $ex = strtoupper(trim($exchange));
    $kind = strtolower(trim($assetKind));
    if (!in_array($kind, ['stock', 'etf'], true)) {
        throw new InvalidArgumentException('assetKind must be stock or etf.');
    }

    $stats = [
        'discovered' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'errors' => [],
        'source' => '',
        'exchange' => $ex,
        'asset_kind' => $kind,
        'file_summary' => [],
        'skip_reasons' => [],
    ];

    $loaded = upstox_instruments_load_exchange_file($ex);
    if (!$loaded['ok']) {
        throw new RuntimeException($loaded['error'] ?? 'Could not load Upstox instrument file.');
    }

    $rows = $loaded['rows'];
    $stats['discovered'] = count($rows);
    $stats['source'] = $ex . '_' . $kind;
    $stats['file_summary'] = upstox_instruments_summarize_file($rows);

    sync_log('upstox', 'instruments_file_summary', [
        'exchange' => $ex,
        'asset_kind' => $kind,
        'summary' => $stats['file_summary'],
    ]);

    $total = count($rows);
    $processed = 0;
    $seenKeys = [];
    if ($jobId !== null) {
        sync_update_progress($pdo, $jobId, 0, max(1, $total));
    }

    foreach ($rows as $rawRow) {
        if (!is_array($rawRow)) {
            $stats['skipped']++;
            $stats['skip_reasons']['invalid_row'] = ($stats['skip_reasons']['invalid_row'] ?? 0) + 1;
            continue;
        }

        $mapped = upstox_instruments_map_row($rawRow, $kind);
        if ($mapped['row'] === null) {
            $stats['skipped']++;
            $reason = $mapped['skip_reason'] ?? 'unmapped';
            $stats['skip_reasons'][$reason] = ($stats['skip_reasons'][$reason] ?? 0) + 1;
            continue;
        }

        $row = $mapped['row'];
        if (strtoupper((string) $row['exchange']) !== $ex) {
            $stats['skipped']++;
            $stats['skip_reasons']['exchange_mismatch'] = ($stats['skip_reasons']['exchange_mismatch'] ?? 0) + 1;
            continue;
        }

        $iKey = (string) $row['provider_instrument_key'];
        if (isset($seenKeys[$iKey])) {
            $stats['skipped']++;
            $stats['skip_reasons']['duplicate_instrument_key'] = ($stats['skip_reasons']['duplicate_instrument_key'] ?? 0) + 1;
            continue;
        }
        $seenKeys[$iKey] = true;

        try {
            $find = $pdo->prepare(
                'SELECT id FROM instruments WHERE exchange = :ex AND symbol = :sym LIMIT 1'
            );
            $find->execute(['ex' => $row['exchange'], 'sym' => $row['symbol']]);
            $existed = (bool) $find->fetch();

            instrument_upsert($pdo, $row);

            if ($existed) {
                $stats['updated']++;
            } else {
                $stats['inserted']++;
            }
        } catch (Throwable $e) {
            $stats['failed']++;
            if (count($stats['errors']) < 15) {
                $stats['errors'][] = $row['symbol'] . ': ' . $e->getMessage();
            }
        }

        $processed++;
        if ($jobId !== null && $processed % 250 === 0) {
            sync_update_progress($pdo, $jobId, $processed, $total);
        }
    }

    if ($jobId !== null) {
        sync_update_progress($pdo, $jobId, $total, $total);
    }

    $imported = $stats['inserted'] + $stats['updated'];
    if ($imported === 0) {
        $skipSummary = upstox_instruments_format_skip_summary($stats['skip_reasons']);
        $segHint = json_encode($stats['file_summary']['by_segment_type'] ?? [], JSON_UNESCAPED_UNICODE);
        throw new RuntimeException(
            'Upstox instrument import matched 0 ' . $kind . ' rows for ' . $ex
            . ' (file had ' . $stats['discovered'] . ' records; skipped ' . $stats['skipped'] . '). '
            . 'Skip reasons: ' . ($skipSummary !== '' ? $skipSummary : 'none') . '. '
            . 'Top segment·type counts: ' . $segHint
        );
    }

    sync_log('upstox', 'instruments_imported', [
        'exchange' => $ex,
        'asset_kind' => $kind,
        'inserted' => $stats['inserted'],
        'updated' => $stats['updated'],
        'skipped' => $stats['skipped'],
        'failed' => $stats['failed'],
        'skip_reasons' => $stats['skip_reasons'],
    ]);

    $stats['symbols_backfilled'] = upstox_backfill_empty_instrument_symbols($pdo, $ex);

    return $stats;
}

/** Fill empty instrument.symbol from a loaded Upstox BOD file (match provider_instrument_key). */
function upstox_backfill_empty_instrument_symbols(PDO $pdo, string $exchange): int
{
    $loaded = upstox_instruments_load_exchange_file($exchange);
    if (!$loaded['ok']) {
        return 0;
    }

    $byKey = [];
    foreach ($loaded['rows'] as $rawRow) {
        if (!is_array($rawRow)) {
            continue;
        }
        $key = trim((string) ($rawRow['instrument_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $sym = upstox_instruments_resolve_trading_symbol($rawRow);
        if ($sym !== '') {
            $byKey[$key] = strtoupper($sym);
        }
    }

    if ($byKey === []) {
        return 0;
    }

    $ex = strtoupper(trim($exchange));
    $stmt = $pdo->prepare(
        "SELECT id, provider_instrument_key FROM instruments
         WHERE exchange = :ex AND instrument_type IN ('stock', 'etf') AND is_active = 1
           AND TRIM(symbol) = '' AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''"
    );
    $stmt->execute(['ex' => $ex]);
    $upd = $pdo->prepare('UPDATE instruments SET symbol = :sym WHERE id = :id');
    $fixed = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pkey = trim((string) ($row['provider_instrument_key'] ?? ''));
        if ($pkey === '' || !isset($byKey[$pkey])) {
            continue;
        }
        $upd->execute(['sym' => $byKey[$pkey], 'id' => (int) $row['id']]);
        $fixed++;
    }

    if ($fixed > 0) {
        market_data_center_invalidate_cache();
    }

    return $fixed;
}

function instrument_count_with_upstox_key(PDO $pdo, string $instrumentType): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM instruments
         WHERE instrument_type = :type AND is_active = 1
           AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''"
    );
    $stmt->execute(['type' => $instrumentType]);

    return (int) $stmt->fetchColumn();
}
