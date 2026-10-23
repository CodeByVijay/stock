<?php

declare(strict_types=1);

const SYNC_JOB_TYPES = [
    'nse_equity_master',
    'bse_equity_master',
    'upstox_nse_stocks',
    'upstox_bse_stocks',
    'upstox_nse_etfs',
    'etf_master',
    'mf_scheme_master',
    'mf_nav_backfill',
    'mf_nav_daily',
    'equity_ohlcv_csv',
    'equity_daily_upstox',
    'etf_daily_upstox',
    'history_backfill',
    'stocks',
    'intraday',
    'etfs',
    'mutual_funds',
    'recalculate_indicators',
    'run_screener',
    'run_backtest',
    'sync_all_available',
    'sync_stocks',
    'sync_etfs',
    'sync_mutual_funds',
    'instrument_ohlcv_sync',
];

function sync_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = PRIVATE_ROOT . '/config/sync.php';
        if (!is_readable($path)) {
            throw new RuntimeException('Sync configuration missing: private/config/sync.php');
        }
        $config = require $path;
        $localPath = PRIVATE_ROOT . '/config/sync.local.php';
        if (is_readable($localPath)) {
            $local = require $localPath;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }
    }

    return $config;
}

function sync_job_type_allowed(string $jobType): bool
{
    return in_array($jobType, SYNC_JOB_TYPES, true);
}

function sync_has_active_job(PDO $pdo, string $jobType, ?int $excludeJobId = null): bool
{
    $sql = "SELECT id FROM sync_jobs
            WHERE job_type = :type AND status IN ('queued', 'running')";
    if ($excludeJobId !== null) {
        $sql .= ' AND id != :exclude';
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $params = ['type' => $jobType];
    if ($excludeJobId !== null) {
        $params['exclude'] = $excludeJobId;
    }
    $stmt->execute($params);

    return (bool) $stmt->fetch();
}

/** @param array<string,mixed>|null $payload */
function sync_create_job(PDO $pdo, string $jobType, ?array $payload = null): array
{
    if (!sync_job_type_allowed($jobType)) {
        return ['ok' => false, 'job_id' => null, 'error' => 'Invalid job type.'];
    }

    if (sync_has_active_job($pdo, $jobType)) {
        return ['ok' => false, 'job_id' => null, 'error' => 'A job of this type is already queued or running.'];
    }

    $payloadJson = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);

    $stmt = $pdo->prepare(
        'INSERT INTO sync_jobs (job_type, payload_json, status, requested_at)
         VALUES (:type, :payload, \'queued\', NOW())'
    );
    $stmt->execute([
        'type' => $jobType,
        'payload' => $payloadJson,
    ]);

    return ['ok' => true, 'job_id' => (int) $pdo->lastInsertId(), 'error' => null];
}

/** @return list<int> */
function sync_create_all_jobs(PDO $pdo): array
{
    require_once PRIVATE_ROOT . '/services/instrument_master.php';
    $cfg = providers_config();
    $queue = ['mf_scheme_master'];
    if (trim((string) ($cfg['nse_equity_csv'] ?? '')) !== '') {
        $queue[] = 'nse_equity_master';
    }
    if (trim((string) ($cfg['bse_equity_csv'] ?? '')) !== '') {
        $queue[] = 'bse_equity_master';
    }
    if (trim((string) ($cfg['etf_master_csv'] ?? '')) !== '') {
        $queue[] = 'etf_master';
    }
    $queue[] = 'mf_nav_backfill';
    $queue[] = 'equity_daily_upstox';
    $queue[] = 'etf_daily_upstox';

    $created = [];
    foreach ($queue as $type) {
        $result = sync_create_job($pdo, $type);
        if ($result['ok'] && $result['job_id'] !== null) {
            $created[] = $result['job_id'];
        }
    }

    return $created;
}

/** @return array<string,mixed>|null */
function sync_job_row_to_array(array $row): array
{
    $out = [
        'id' => (int) $row['id'],
        'job_type' => (string) ($row['job_type'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'requested_at' => $row['requested_at'] ?? null,
        'started_at' => $row['started_at'] ?? null,
        'finished_at' => $row['finished_at'] ?? null,
        'progress_total' => array_key_exists('progress_total', $row) && $row['progress_total'] !== null
            ? (int) $row['progress_total'] : null,
        'progress_done' => array_key_exists('progress_done', $row) && $row['progress_done'] !== null
            ? (int) $row['progress_done'] : null,
        'records_processed' => (int) ($row['records_processed'] ?? 0),
        'attempts' => (int) ($row['attempts'] ?? 0),
        'max_attempts' => (int) ($row['max_attempts'] ?? 0),
        'error_message' => $row['error_message'] ?? null,
        'result' => null,
    ];
    if (!empty($row['result_json'])) {
        $decoded = json_decode((string) $row['result_json'], true);
        if (is_array($decoded)) {
            $out['result'] = $decoded;
        }
    }

    return $out;
}

function sync_get_job(PDO $pdo, int $jobId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, job_type, status, requested_at, started_at, finished_at,
                progress_total, progress_done, records_processed, attempts, max_attempts,
                error_message, result_json, payload_json
         FROM sync_jobs WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $jobId]);
    $row = $stmt->fetch();

    return $row ? sync_job_row_to_array($row) : null;
}

function sync_update_progress(PDO $pdo, int $jobId, int $done, int $total): void
{
    $stmt = $pdo->prepare(
        'UPDATE sync_jobs SET progress_done = :done, progress_total = :total WHERE id = :id'
    );
    $stmt->execute(['done' => $done, 'total' => $total, 'id' => $jobId]);
}

function sync_job_lease_seconds(): int
{
    $cfg = sync_config();
    $worker = isset($cfg['worker']) && is_array($cfg['worker']) ? $cfg['worker'] : [];

    return max(300, (int) ($worker['job_lease_seconds'] ?? 900));
}

function sync_stale_job_grace_seconds(): int
{
    $cfg = sync_config();
    $worker = isset($cfg['worker']) && is_array($cfg['worker']) ? $cfg['worker'] : [];

    return max(60, (int) ($worker['stale_job_grace_seconds'] ?? 120));
}

function sync_extend_job_lease(PDO $pdo, int $jobId, ?int $leaseSeconds = null): void
{
    $lease = $leaseSeconds ?? sync_job_lease_seconds();
    $stmt = $pdo->prepare(
        'UPDATE sync_jobs SET lock_until = DATE_ADD(NOW(), INTERVAL :lease SECOND) WHERE id = :id AND status = \'running\''
    );
    $stmt->bindValue('lease', $lease, PDO::PARAM_INT);
    $stmt->bindValue('id', $jobId, PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Running jobs whose worker lease expired (crash, timeout, PHP killed) block the whole queue.
 *
 * @return int Number of jobs moved back to queued or marked failed
 */
function sync_reclaim_stale_running_jobs(PDO $pdo): int
{
    $grace = sync_stale_job_grace_seconds();
    $stmt = $pdo->prepare(
        "SELECT id, attempts, max_attempts, job_type, progress_done, progress_total, payload_json
         FROM sync_jobs
         WHERE status = 'running'
           AND (
             lock_until IS NULL
             OR lock_until < DATE_SUB(NOW(), INTERVAL :grace SECOND)
           )"
    );
    $stmt->bindValue('grace', $grace, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    $count = 0;
    foreach ($rows as $row) {
        $jobId = (int) $row['id'];
        $attempts = (int) $row['attempts'];
        $maxAttempts = (int) $row['max_attempts'];
        $jobType = (string) ($row['job_type'] ?? '');
        $done = (int) ($row['progress_done'] ?? 0);
        $total = (int) ($row['progress_total'] ?? 0);
        $step = $total > 0 ? (' at step ' . $done . '/' . $total) : '';
        $resumePayload = null;

        if (in_array($jobType, ['sync_stocks', 'sync_etfs'], true) && $done > 0) {
            $existing = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string) $row['payload_json'], true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
            $existing['price_offset'] = max((int) ($existing['price_offset'] ?? 0), $done);
            $existing['imports_done'] = true;
            try {
                $resumePayload = json_encode($existing, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $resumePayload = null;
            }
            $msg = $total > 0
                ? 'Paused by server time limit at ' . $done . '/' . $total . '. Resuming automatically…'
                : 'Paused by server time limit. Resuming automatically…';
        } else {
            $msg = 'Worker stopped unexpectedly' . $step . '. Queued for retry.';
        }

        if ($attempts < $maxAttempts) {
            $upd = $pdo->prepare(
                "UPDATE sync_jobs
                 SET status = 'queued',
                     lock_until = DATE_ADD(NOW(), INTERVAL 30 SECOND),
                     error_message = :msg,
                     payload_json = COALESCE(:payload, payload_json)
                 WHERE id = :id AND status = 'running'"
            );
            $upd->execute([
                'msg' => mb_substr($msg, 0, 2000),
                'payload' => $resumePayload,
                'id' => $jobId,
            ]);
        } else {
            $msg .= 'Max retries reached — press Start update again.';
            $upd = $pdo->prepare(
                "UPDATE sync_jobs
                 SET status = 'failed',
                     finished_at = NOW(),
                     lock_until = NULL,
                     error_message = :msg
                 WHERE id = :id AND status = 'running'"
            );
            $upd->execute(['msg' => mb_substr($msg, 0, 2000), 'id' => $jobId]);
        }
        if ($upd->rowCount() > 0) {
            $count++;
        }
    }

    return $count;
}

function sync_has_parallel_claimable_job(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT q.id FROM sync_jobs q
         WHERE q.status = 'queued'
           AND q.attempts < q.max_attempts
           AND (q.lock_until IS NULL OR q.lock_until < NOW())
           AND NOT EXISTS (
             SELECT 1 FROM sync_jobs r
             WHERE r.status = 'running' AND r.job_type = q.job_type
           )
         LIMIT 1"
    );

    return (bool) $stmt->fetch();
}

function sync_claim_next_job(PDO $pdo, ?int $leaseSeconds = null): ?array
{
    $leaseSeconds = $leaseSeconds ?? sync_job_lease_seconds();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            "SELECT q.* FROM sync_jobs q
             WHERE q.status = 'queued'
               AND q.attempts < q.max_attempts
               AND (q.lock_until IS NULL OR q.lock_until < NOW())
               AND NOT EXISTS (
                 SELECT 1 FROM sync_jobs r
                 WHERE r.status = 'running' AND r.job_type = q.job_type
               )
             ORDER BY q.requested_at ASC
             LIMIT 1
             FOR UPDATE"
        );
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->commit();
            return null;
        }

        $upd = $pdo->prepare(
            "UPDATE sync_jobs
             SET status = 'running',
                 started_at = COALESCE(started_at, NOW()),
                 attempts = attempts + 1,
                 lock_until = DATE_ADD(NOW(), INTERVAL :lease SECOND)
             WHERE id = :id AND status = 'queued'"
        );
        $upd->bindValue('lease', $leaseSeconds, PDO::PARAM_INT);
        $upd->bindValue('id', (int) $row['id'], PDO::PARAM_INT);
        $upd->execute();

        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }

        $pdo->commit();
        $row['status'] = 'running';

        return $row;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sync_complete_job(PDO $pdo, int $jobId, int $recordsProcessed, ?array $result = null): void
{
    $stmt = $pdo->prepare(
        "UPDATE sync_jobs
         SET status = 'completed',
             finished_at = NOW(),
             records_processed = :records,
             lock_until = NULL,
             error_message = NULL,
             result_json = :result
         WHERE id = :id"
    );
    $stmt->execute([
        'records' => $recordsProcessed,
        'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR),
        'id' => $jobId,
    ]);
}

function sync_queue_followup(PDO $pdo, string $jobType, array $payload, ?int $excludeJobId = null): void
{
    if (!sync_has_active_job($pdo, $jobType, $excludeJobId)) {
        sync_create_job($pdo, $jobType, $payload);
    }
}

function sync_fail_job(PDO $pdo, int $jobId, string $message, bool $retry): void
{
    $stmt = $pdo->prepare('SELECT attempts, max_attempts FROM sync_jobs WHERE id = :id');
    $stmt->execute(['id' => $jobId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $attempts = (int) $row['attempts'];
    $maxAttempts = (int) $row['max_attempts'];

    if ($retry && $attempts < $maxAttempts) {
        $upd = $pdo->prepare(
            "UPDATE sync_jobs
             SET status = 'queued',
                 error_message = :msg,
                 lock_until = DATE_ADD(NOW(), INTERVAL 60 SECOND)
             WHERE id = :id"
        );
        $upd->execute(['msg' => mb_substr($message, 0, 2000), 'id' => $jobId]);
        return;
    }

    $upd = $pdo->prepare(
        "UPDATE sync_jobs
         SET status = 'failed',
             finished_at = NOW(),
             error_message = :msg,
             lock_until = NULL
         WHERE id = :id"
    );
    $upd->execute(['msg' => mb_substr($message, 0, 2000), 'id' => $jobId]);
}

/** @return list<array<string,mixed>> */
function sync_list_open_jobs(PDO $pdo, int $limit = 12): array
{
    $limit = max(1, min(30, $limit));
    $stmt = $pdo->prepare(
        "SELECT id, job_type, status, requested_at, started_at, progress_done, progress_total, error_message
         FROM sync_jobs
         WHERE status IN ('queued', 'running')
         ORDER BY FIELD(status, 'running', 'queued'), id ASC
         LIMIT :lim"
    );
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = sync_job_row_to_array($row);
    }

    return $out;
}
