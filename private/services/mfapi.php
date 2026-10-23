<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/http_client.php';
require_once PRIVATE_ROOT . '/services/sync_log.php';
require_once PRIVATE_ROOT . '/services/mf_scheme_meta.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';

const MFAPI_BASE = 'https://api.mfapi.in';
const MFAPI_SOURCE = 'mfapi.in';
const MFAPI_MIN_REQUEST_GAP_MS = 350;

function mfapi_throttle(): void
{
    static $lastAt = 0.0;
    $now = microtime(true);
    $elapsedMs = ($now - $lastAt) * 1000;
    if ($lastAt > 0 && $elapsedMs < MFAPI_MIN_REQUEST_GAP_MS) {
        usleep((int) ((MFAPI_MIN_REQUEST_GAP_MS - $elapsedMs) * 1000));
    }
    $lastAt = microtime(true);
}

/** @return array{ok:bool,data:mixed,error:?string,status:int} */
function mfapi_search(string $query): array
{
    mfapi_throttle();
    $url = MFAPI_BASE . '/mf/search?q=' . rawurlencode($query);
    $res = http_get($url, ['Accept: application/json'], 25);

    if (!$res['ok']) {
        sync_log('mfapi', 'search_failed', ['query' => $query, 'status' => $res['status'], 'error' => $res['error']]);
        return ['ok' => false, 'data' => null, 'error' => $res['error'] ?? 'Search request failed.', 'status' => $res['status']];
    }

    if (!is_array($res['json'])) {
        return ['ok' => false, 'data' => null, 'error' => 'Unexpected search response format.', 'status' => $res['status']];
    }

    return ['ok' => true, 'data' => $res['json'], 'error' => null, 'status' => $res['status']];
}

/** @return array{ok:bool,data:mixed,error:?string,status:int} */
function mfapi_latest(int $schemeCode): array
{
    mfapi_throttle();
    $url = MFAPI_BASE . '/mf/' . $schemeCode . '/latest';
    $res = http_get($url, ['Accept: application/json'], 25);

    if (!$res['ok']) {
        sync_log('mfapi', 'latest_failed', ['scheme_code' => $schemeCode, 'status' => $res['status']]);
        return ['ok' => false, 'data' => null, 'error' => $res['error'] ?? 'Latest NAV request failed.', 'status' => $res['status']];
    }

    if (!is_array($res['json']) || ($res['json']['status'] ?? '') !== 'SUCCESS') {
        return ['ok' => false, 'data' => $res['json'], 'error' => 'Latest NAV response was not successful.', 'status' => $res['status']];
    }

    return ['ok' => true, 'data' => $res['json'], 'error' => null, 'status' => $res['status']];
}

/** @return array{ok:bool,data:mixed,error:?string,status:int} */
function mfapi_nav_history(int $schemeCode, ?string $startDate = null, ?string $endDate = null, int $timeoutSeconds = 45): array
{
    mfapi_throttle();
    $url = MFAPI_BASE . '/mf/' . $schemeCode;
    $query = [];
    if ($startDate !== null && $startDate !== '') {
        $query['startDate'] = $startDate;
    }
    if ($endDate !== null && $endDate !== '') {
        $query['endDate'] = $endDate;
    }
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $res = http_get($url, ['Accept: application/json'], max(15, $timeoutSeconds));

    if (!$res['ok']) {
        sync_log('mfapi', 'history_failed', ['scheme_code' => $schemeCode, 'status' => $res['status']]);
        return ['ok' => false, 'data' => null, 'error' => $res['error'] ?? 'NAV history request failed.', 'status' => $res['status']];
    }

    if (!is_array($res['json']) || ($res['json']['status'] ?? '') !== 'SUCCESS') {
        return ['ok' => false, 'data' => $res['json'], 'error' => 'NAV history response was not successful.', 'status' => $res['status']];
    }

    return ['ok' => true, 'data' => $res['json'], 'error' => null, 'status' => $res['status']];
}

function mfapi_parse_nav_date(string $date): ?string
{
    $date = trim($date);
    $dt = DateTime::createFromFormat('d-m-Y', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);

    return $dt instanceof DateTime ? $dt->format('Y-m-d') : null;
}

/** @param array<string,mixed> $meta Raw MFapi `meta` object */
function mfapi_upsert_scheme(PDO $pdo, array $meta): int
{
    $fields = mfapi_extract_scheme_meta($meta);
    if ($fields['scheme_code'] === '') {
        throw new InvalidArgumentException('scheme_code missing in MFapi meta.');
    }

    $name = $fields['scheme_name'] !== '' ? $fields['scheme_name'] : 'Unknown scheme';

    $stmt = $pdo->prepare(
        'INSERT INTO mutual_fund_schemes (
            scheme_code, scheme_name, fund_house, scheme_category, scheme_type,
            isin_growth, isin_div_reinvestment, meta_source, meta_synced_at, is_active
         ) VALUES (
            :code, :name, :house, :category, :stype,
            :isin_g, :isin_d, :msrc, NOW(), 1
         )
         ON DUPLICATE KEY UPDATE
            scheme_name = CASE WHEN VALUES(scheme_name) <> \'\' AND VALUES(scheme_name) <> \'Unknown scheme\'
                THEN VALUES(scheme_name) ELSE scheme_name END,
            fund_house = COALESCE(VALUES(fund_house), fund_house),
            scheme_category = COALESCE(VALUES(scheme_category), scheme_category),
            scheme_type = COALESCE(VALUES(scheme_type), scheme_type),
            isin_growth = COALESCE(VALUES(isin_growth), isin_growth),
            isin_div_reinvestment = COALESCE(VALUES(isin_div_reinvestment), isin_div_reinvestment),
            meta_source = VALUES(meta_source),
            meta_synced_at = VALUES(meta_synced_at),
            is_active = 1'
    );
    $stmt->execute([
        'code' => $fields['scheme_code'],
        'name' => $name,
        'house' => $fields['fund_house'],
        'category' => $fields['scheme_category'],
        'stype' => $fields['scheme_type'],
        'isin_g' => $fields['isin_growth'],
        'isin_d' => $fields['isin_div_reinvestment'],
        'msrc' => MFAPI_META_SOURCE,
    ]);

    $fetch = $pdo->prepare('SELECT id FROM mutual_fund_schemes WHERE scheme_code = :code LIMIT 1');
    $fetch->execute(['code' => $fields['scheme_code']]);
    $row = $fetch->fetch();

    if (!$row) {
        throw new RuntimeException('Could not resolve scheme id after upsert.');
    }

    return (int) $row['id'];
}

/**
 * @param list<array{date:string,nav:string}> $rows
 * @return array{inserted:int,updated:int,skipped:int}
 */
function mfapi_upsert_nav_rows(PDO $pdo, int $schemeId, array $rows, DateTimeInterface $fetchedAt): array
{
    $inserted = 0;
    $updated = 0;
    $skipped = 0;

    $stmt = $pdo->prepare(
        'INSERT INTO mutual_fund_nav (scheme_id, nav_date, nav, source, fetched_at)
         VALUES (:scheme_id, :nav_date, :nav, :source, :fetched_at)
         ON DUPLICATE KEY UPDATE
            nav = VALUES(nav),
            source = VALUES(source),
            fetched_at = VALUES(fetched_at)'
    );

    foreach ($rows as $row) {
        if (!isset($row['date'], $row['nav'])) {
            $skipped++;
            continue;
        }
        $navDate = mfapi_parse_nav_date((string) $row['date']);
        if ($navDate === null) {
            $skipped++;
            continue;
        }
        $nav = (float) $row['nav'];
        if ($nav <= 0) {
            $skipped++;
            continue;
        }

        $stmt->execute([
            'scheme_id' => $schemeId,
            'nav_date' => $navDate,
            'nav' => $nav,
            'source' => MFAPI_SOURCE,
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
 * Sync one scheme: upsert metadata and NAV rows.
 *
 * @param string $rangeMode bounded (start/end dates), full (entire MFapi history), ignored when latestOnly
 * @return array{ok:bool,scheme_id:?int,stats:array,api_rows:int,error:?string}
 */
function mfapi_sync_scheme(
    PDO $pdo,
    int $schemeCode,
    ?string $startDate = null,
    ?string $endDate = null,
    bool $latestOnly = false,
    string $rangeMode = 'bounded'
): array {
    $fetchedAt = new DateTimeImmutable('now');

    if ($latestOnly) {
        $api = mfapi_latest($schemeCode);
    } elseif ($rangeMode === 'full') {
        $api = mfapi_nav_history($schemeCode, null, null, 120);
    } else {
        $api = mfapi_nav_history($schemeCode, $startDate, $endDate, 60);
    }

    if (!$api['ok'] || !is_array($api['data'])) {
        return ['ok' => false, 'scheme_id' => null, 'stats' => [], 'api_rows' => 0, 'error' => $api['error'] ?? 'MFapi request failed.'];
    }

    $meta = $api['data']['meta'] ?? null;
    $data = $api['data']['data'] ?? null;
    if (!is_array($meta) || !is_array($data)) {
        return ['ok' => false, 'scheme_id' => null, 'stats' => [], 'api_rows' => 0, 'error' => 'MFapi response missing meta or data.'];
    }

    $apiRows = count($data);

    try {
        $pdo->beginTransaction();
        $schemeId = mfapi_upsert_scheme($pdo, $meta);
        $stats = mfapi_upsert_nav_rows($pdo, $schemeId, $data, $fetchedAt);
        mf_refresh_nav_coverage($pdo, $schemeId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sync_log('mfapi', 'db_error', ['scheme_code' => $schemeCode, 'message' => $e->getMessage()]);
        return ['ok' => false, 'scheme_id' => null, 'stats' => [], 'api_rows' => $apiRows, 'error' => 'Database error while storing MF data.'];
    }

    $cov = $pdo->prepare(
        'SELECT earliest_nav_date, latest_nav_date, nav_count FROM mf_nav_coverage WHERE scheme_id = :id'
    );
    $cov->execute(['id' => $schemeId]);
    $coverage = $cov->fetch() ?: null;

    $metaFields = mfapi_extract_scheme_meta($meta);
    $missingMeta = [];
    foreach (['fund_house', 'scheme_type', 'scheme_category', 'isin_growth', 'isin_div_reinvestment'] as $k) {
        if ($metaFields[$k] === null) {
            $missingMeta[] = $k;
        }
    }

    sync_log('mfapi', 'scheme_synced', [
        'scheme_code' => $schemeCode,
        'scheme_id' => $schemeId,
        'stats' => $stats,
        'api_rows' => $apiRows,
        'range_mode' => $latestOnly ? 'latest' : $rangeMode,
        'latest_only' => $latestOnly,
        'coverage' => $coverage,
        'meta_fields_present' => array_keys(array_filter($metaFields, static function ($v) {
            return $v !== null && $v !== '';
        })),
        'meta_fields_missing' => $missingMeta,
    ]);

    return [
        'ok' => true,
        'scheme_id' => $schemeId,
        'stats' => $stats,
        'api_rows' => $apiRows,
        'coverage' => $coverage,
        'meta' => $metaFields,
        'error' => null,
    ];
}

/** @return array{ok:bool,data:mixed,error:?string} */
function mfapi_list_schemes_page(int $offset, int $limit): array
{
    mfapi_throttle();
    $url = MFAPI_BASE . '/mf?limit=' . max(1, min(500, $limit)) . '&offset=' . max(0, $offset);
    $res = http_get($url, ['Accept: application/json'], 45);
    if (!$res['ok'] || !is_array($res['json'])) {
        return ['ok' => false, 'data' => null, 'error' => $res['error'] ?? 'Scheme list request failed.'];
    }

    return ['ok' => true, 'data' => $res['json'], 'error' => null];
}

/** @return array{discovered:int,inserted:int,updated:int,skipped:int} */
function mfapi_sync_scheme_master_page(PDO $pdo, int $offset, int $limit): array
{
    $api = mfapi_list_schemes_page($offset, $limit);
    if (!$api['ok'] || !is_array($api['data'])) {
        throw new RuntimeException($api['error'] ?? 'MFapi scheme list failed.');
    }

    $stats = ['discovered' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];
    foreach ($api['data'] as $item) {
        if (!is_array($item) || empty($item['schemeCode'])) {
            $stats['skipped']++;
            continue;
        }
        $stats['discovered']++;
        $code = (string) $item['schemeCode'];
        $name = (string) ($item['schemeName'] ?? 'Scheme ' . $code);

        $find = $pdo->prepare('SELECT id FROM mutual_fund_schemes WHERE scheme_code = :c LIMIT 1');
        $find->execute(['c' => $code]);
        $exists = (bool) $find->fetch();

        $stmt = $pdo->prepare(
            'INSERT INTO mutual_fund_schemes (scheme_code, scheme_name, is_active, master_source, master_synced_at)
             VALUES (:code, :name, 1, :src, NOW())
             ON DUPLICATE KEY UPDATE scheme_name = VALUES(scheme_name), is_active = 1,
             master_source = VALUES(master_source), master_synced_at = NOW()'
        );
        $stmt->execute(['code' => $code, 'name' => $name, 'src' => MFAPI_SOURCE]);

        if ($exists) {
            $stats['updated']++;
        } else {
            $stats['inserted']++;
        }
    }

    return $stats;
}

/** @return array{pages:int,stats:array,complete:bool} */
function mfapi_sync_all_scheme_masters(PDO $pdo, int $jobId, int $pageSize): array
{
    $offset = 0;
    $pages = 0;
    $totals = ['discovered' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];

    while (true) {
        $page = mfapi_sync_scheme_master_page($pdo, $offset, $pageSize);
        $pages++;
        foreach ($totals as $k => $_) {
            $totals[$k] += (int) ($page[$k] ?? 0);
        }
        sync_update_progress($pdo, $jobId, $pages, $pages + 1);
        if ($page['discovered'] === 0) {
            break;
        }
        $offset += $pageSize;
        if ($page['discovered'] < $pageSize) {
            break;
        }
    }

    return ['pages' => $pages, 'stats' => $totals, 'complete' => true];
}

/** @return array{processed:int,failed:int,offset:int,complete:bool} */
function mfapi_nav_backfill_batch(PDO $pdo, int $offset, int $batchSize, int $historyDays, ?int $jobId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT id, scheme_code FROM mutual_fund_schemes WHERE is_active = 1 ORDER BY id ASC LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue('lim', $batchSize, PDO::PARAM_INT);
    $stmt->bindValue('off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        return ['processed' => 0, 'failed' => 0, 'offset' => $offset, 'complete' => true];
    }

    $days = max(1, $historyDays);
    $endYmd = gmdate('Y-m-d');
    $startYmd = gmdate('Y-m-d', strtotime('-' . $days . ' days'));
    $processed = 0;
    $failed = 0;
    $i = 0;
    foreach ($rows as $row) {
        $i++;
        if ($jobId) {
            sync_update_progress($pdo, $jobId, $i, count($rows));
        }
        $code = (int) $row['scheme_code'];
        $result = mfapi_sync_scheme($pdo, $code, $startYmd, $endYmd, false);
        if ($result['ok'] && $result['scheme_id']) {
            mf_refresh_nav_coverage($pdo, (int) $result['scheme_id']);
            $processed++;
        } else {
            $failed++;
        }
    }

    $nextOffset = $offset + count($rows);
    $complete = count($rows) < $batchSize;

    return ['processed' => $processed, 'failed' => $failed, 'offset' => $nextOffset, 'complete' => $complete];
}
