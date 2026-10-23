<?php

declare(strict_types=1);

/**
 * Background sync worker: spawn CLI drain process or process jobs inline (HTTP tick).
 */

/**
 * PHP 7.4 (Apache) requires proc_open(command string). PHP 8+ accepts a command array.
 *
 * @param list<string> $cmd
 * @param array<int,array<string,mixed>> $descriptors
 * @param array<int,mixed> $pipes
 * @return resource|false
 */
function sync_worker_proc_open(array $cmd, array $descriptors, array &$pipes, string $cwd)
{
    if (PHP_VERSION_ID >= 80000) {
        return @proc_open($cmd, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
    }

    $line = '';
    foreach ($cmd as $part) {
        $line .= ($line === '' ? '' : ' ') . escapeshellarg((string) $part);
    }

    return @proc_open($line, $descriptors, $pipes, $cwd);
}

function sync_worker_config(): array
{
    $sync = sync_config();
    $defaults = [
        'php_cli_binary' => '',
        'spawn_enabled' => true,
        'drain_max_loops' => 2000,
        'jobs_per_loop' => 1,
        'lock_file' => PRIVATE_ROOT . '/logs/sync-worker.lock',
        'log_file' => PRIVATE_ROOT . '/logs/sync-worker.log',
    ];
    $worker = isset($sync['worker']) && is_array($sync['worker']) ? $sync['worker'] : [];

    return array_merge($defaults, $worker);
}

/** @return array{allowed:bool,reason:string} */
function sync_worker_spawn_capability(): array
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    $disabled = array_filter($disabled, static function ($fn) {
        return $fn !== '';
    });

    if (in_array('proc_open', $disabled, true)) {
        return ['allowed' => false, 'reason' => 'proc_open is disabled in PHP (disable_functions).'];
    }

    if (!function_exists('proc_open')) {
        return ['allowed' => false, 'reason' => 'proc_open is not available.'];
    }

    return ['allowed' => true, 'reason' => ''];
}

function sync_worker_lock_path(): string
{
    return (string) sync_worker_config()['lock_file'];
}

/** @return array{pid:int,started:int}|null */
function sync_worker_read_lock(): ?array
{
    $path = sync_worker_lock_path();
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    return [
        'pid' => (int) ($data['pid'] ?? 0),
        'started' => (int) ($data['started'] ?? 0),
    ];
}

function sync_worker_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0);
    }

    return is_dir('/proc/' . $pid);
}

function sync_worker_is_running(): bool
{
    $lock = sync_worker_read_lock();
    if ($lock === null || $lock['pid'] <= 0) {
        return false;
    }
    if (!sync_worker_pid_alive($lock['pid'])) {
        sync_worker_release_lock();

        return false;
    }

    return true;
}

function sync_worker_release_lock(): void
{
    $path = sync_worker_lock_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

/** Drop CLI drain lock when the process is gone or the lock is very old (prevents false "background working"). */
function sync_worker_maybe_release_stale_daemon_lock(int $maxAgeSeconds = 7200): bool
{
    $lock = sync_worker_read_lock();
    if ($lock === null) {
        return false;
    }
    $pid = (int) ($lock['pid'] ?? 0);
    $started = (int) ($lock['started'] ?? 0);
    $tooOld = $started > 0 && (time() - $started) > $maxAgeSeconds;
    if ($pid > 0 && sync_worker_pid_alive($pid) && !$tooOld) {
        return false;
    }
    sync_worker_release_lock();

    return true;
}

/** Running rows that still hold a valid worker lease. */
function sync_count_leased_running_jobs(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM sync_jobs WHERE status = 'running' AND lock_until IS NOT NULL AND lock_until > NOW()"
    )->fetchColumn();
}

/** Called by CLI drain worker at startup. */
function sync_worker_acquire_daemon_lock(): bool
{
    if (sync_worker_is_running()) {
        return false;
    }
    sync_worker_release_lock();
    $path = sync_worker_lock_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $payload = json_encode(['pid' => getmypid(), 'started' => time()]);
    if ($payload === false) {
        return false;
    }
    file_put_contents($path, $payload, LOCK_EX);

    return true;
}

function sync_worker_php_binary(): string
{
    $cfg = sync_worker_config();
    $bin = trim((string) $cfg['php_cli_binary']);
    if ($bin !== '' && is_executable($bin)) {
        return $bin;
    }

    // Apache mod_php is often 7.x while /usr/bin/php may be 8.x — prefer matching CLI on old runtimes.
    if (PHP_VERSION_ID < 80000) {
        foreach (['/usr/bin/php7.4', '/usr/bin/php7.3', '/usr/bin/php7.2'] as $legacy) {
            if (is_executable($legacy)) {
                return $legacy;
            }
        }
    }

    if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
        $phpBin = PHP_BINARY;
        if (strpos($phpBin, 'php-fpm') === false && strpos($phpBin, 'apache') === false) {
            return $phpBin;
        }
    }

    foreach (['/usr/local/bin/php', '/usr/bin/php', '/usr/bin/php8.3', '/usr/bin/php8.4'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    $which = trim((string) shell_exec('command -v php 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        return $which;
    }

    return PHP_VERSION_ID >= 80000 ? '/usr/bin/php' : '/usr/bin/php7.4';
}

/** @return array{ok:bool,reason:string} */
function sync_worker_prepare_log_file(string $logFile): array
{
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            return ['ok' => false, 'reason' => 'Log directory is not writable: ' . $logDir];
        }
    }
    if (!is_writable($logDir)) {
        return ['ok' => false, 'reason' => 'Log directory is not writable: ' . $logDir];
    }
    if (!is_file($logFile)) {
        @touch($logFile);
    }
    if (!is_file($logFile) || !is_writable($logFile)) {
        return ['ok' => false, 'reason' => 'Worker log is not writable: ' . $logFile];
    }

    return ['ok' => true, 'reason' => ''];
}

/**
 * Detached spawn via shell when proc_open descriptors fail under mod_php.
 *
 * @param list<string> $cmd
 */
function sync_worker_try_spawn_shell(array $cmd, string $logFile, string $cwd): array
{
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'pid' => 0, 'error' => 'shell_exec unavailable'];
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return ['ok' => false, 'pid' => 0, 'error' => 'shell_exec disabled'];
    }

    $line = 'cd ' . escapeshellarg($cwd) . ' && nohup';
    foreach ($cmd as $part) {
        $line .= ' ' . escapeshellarg((string) $part);
    }
    $line .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($line));
    if ($pid <= 0) {
        return ['ok' => false, 'pid' => 0, 'error' => 'nohup spawn returned no PID'];
    }

    return ['ok' => true, 'pid' => $pid, 'error' => null];
}

/**
 * Process up to $maxJobs queued rows in this PHP process (web-safe, bounded).
 */
function sync_worker_process_jobs(PDO $pdo, int $maxJobs): int
{
    require_once PRIVATE_ROOT . '/services/job_runner.php';

    $processed = 0;
    for ($i = 0; $i < $maxJobs; $i++) {
        $job = sync_claim_next_job($pdo);
        if ($job === null) {
            break;
        }
        job_runner_execute($pdo, $job);
        $processed++;
    }

    return $processed;
}

/** @return array{queued:int,running:int,failed_recent:int} */
function sync_queue_snapshot(PDO $pdo): array
{
    $queued = (int) $pdo->query(
        "SELECT COUNT(*) FROM sync_jobs WHERE status = 'queued'"
    )->fetchColumn();
    $running = (int) $pdo->query(
        "SELECT COUNT(*) FROM sync_jobs WHERE status = 'running'"
    )->fetchColumn();
    $failedRecent = (int) $pdo->query(
        "SELECT COUNT(*) FROM sync_jobs WHERE status = 'failed' AND finished_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    )->fetchColumn();

    return [
        'queued' => $queued,
        'running' => $running,
        'failed_recent' => $failedRecent,
    ];
}

/**
 * Try to start detached CLI drain worker.
 *
 * @return array{ok:bool,spawned:bool,running:bool,mode:string,error:?string,pid:?int}
 */
function sync_worker_try_spawn(): array
{
    $cfg = sync_worker_config();
    if (empty($cfg['spawn_enabled'])) {
        return [
            'ok' => false,
            'spawned' => false,
            'running' => false,
            'mode' => 'browser',
            'error' => 'Background spawn is disabled in config.',
            'pid' => null,
        ];
    }

    if (sync_worker_is_running()) {
        return [
            'ok' => true,
            'spawned' => false,
            'running' => true,
            'mode' => 'background',
            'error' => null,
            'pid' => sync_worker_read_lock()['pid'] ?? null,
        ];
    }

    $cap = sync_worker_spawn_capability();
    if (!$cap['allowed']) {
        return [
            'ok' => false,
            'spawned' => false,
            'running' => false,
            'mode' => 'browser',
            'error' => $cap['reason'],
            'pid' => null,
        ];
    }

    $php = sync_worker_php_binary();
    $script = PRIVATE_ROOT . '/cron/process-queue.php';
    if (!is_readable($script)) {
        return [
            'ok' => false,
            'spawned' => false,
            'running' => false,
            'mode' => 'browser',
            'error' => 'Worker script missing.',
            'pid' => null,
        ];
    }

    $logFile = (string) $cfg['log_file'];
    $logReady = sync_worker_prepare_log_file($logFile);
    if (!$logReady['ok']) {
        return [
            'ok' => false,
            'spawned' => false,
            'running' => false,
            'mode' => 'browser',
            'error' => $logReady['reason'],
            'pid' => null,
        ];
    }

    $maxLoops = max(1, (int) $cfg['drain_max_loops']);
    $jobsPerLoop = max(1, (int) $cfg['jobs_per_loop']);

    $cmd = [
        $php,
        $script,
        '--drain',
        '--max-jobs=' . $jobsPerLoop,
        '--max-loops=' . $maxLoops,
    ];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];

    $pipes = [];
    $proc = sync_worker_proc_open($cmd, $descriptors, $pipes, APP_ROOT);
    $pid = 0;
    if (is_resource($proc)) {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $status = proc_get_status($proc);
        $pid = (int) ($status['pid'] ?? 0);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        // Do not proc_close — would wait for drain to finish. Child continues after pipes closed.
    } else {
        $shell = sync_worker_try_spawn_shell($cmd, $logFile, APP_ROOT);
        if ($shell['ok']) {
            $pid = (int) $shell['pid'];
        } else {
            $last = error_get_last();
            $detail = $last && isset($last['message']) ? $last['message'] : ($shell['error'] ?? 'unknown');

            return [
                'ok' => false,
                'spawned' => false,
                'running' => false,
                'mode' => 'browser',
                'error' => 'Background worker unavailable (' . $detail . '). Jobs run in this browser tab.',
                'pid' => null,
            ];
        }
    }

    if ($pid <= 0) {
        return [
            'ok' => false,
            'spawned' => false,
            'running' => false,
            'mode' => 'browser',
            'error' => 'Worker started but PID unknown.',
            'pid' => null,
        ];
    }

    // Worker acquires lock itself; brief wait to detect immediate crash.
    usleep(200000);
    if (!sync_worker_is_running() && !sync_worker_pid_alive($pid)) {
        return [
            'ok' => false,
            'spawned' => false,
            'running' => false,
            'mode' => 'browser',
            'error' => 'Background worker exited immediately. Check private/logs/sync-worker.log.',
            'pid' => null,
        ];
    }

    return [
        'ok' => true,
        'spawned' => true,
        'running' => true,
        'mode' => 'background',
        'error' => null,
        'pid' => $pid,
    ];
}

/**
 * After queueing work, start background worker or allow browser-driven ticks.
 *
 * @return array{ok:bool,spawned:bool,running:bool,mode:string,error:?string,pid:?int}
 */
function sync_worker_ensure_running(PDO $pdo): array
{
    unset($pdo);
    $result = sync_worker_try_spawn();
    if ($result['running']) {
        return $result;
    }

    // Spawn failed — browser tick can still process jobs.
    return $result;
}
