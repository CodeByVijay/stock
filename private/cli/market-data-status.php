<?php

declare(strict_types=1);

/**
 * Quick health check for Market Data (run on the server):
 *   php private/cli/market-data-status.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/init.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/upstox.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Database: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$stocks = (int) $pdo->query("SELECT COUNT(*) FROM instruments WHERE instrument_type='stock' AND is_active=1")->fetchColumn();
$stockKeys = (int) $pdo->query(
    "SELECT COUNT(*) FROM instruments WHERE instrument_type='stock' AND is_active=1
     AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''"
)->fetchColumn();
$etfs = (int) $pdo->query("SELECT COUNT(*) FROM instruments WHERE instrument_type='etf' AND is_active=1")->fetchColumn();
$mf = (int) $pdo->query('SELECT COUNT(*) FROM mutual_fund_schemes')->fetchColumn();
$mfNav = (int) $pdo->query('SELECT COUNT(DISTINCT scheme_id) FROM mutual_fund_nav')->fetchColumn();

$queue = $pdo->query(
    "SELECT status, COUNT(*) c FROM sync_jobs GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$cfg = providers_config();
$syncCfg = sync_config();
$tokenLen = strlen(upstox_access_token());
$auth = $tokenLen > 0 ? upstox_verify_authentication() : ['ok' => false, 'error' => 'No token'];

echo "=== Market Data Status ===\n";
echo "Stocks in DB: {$stocks} (with Upstox key: {$stockKeys})\n";
echo "ETFs in DB: {$etfs}\n";
echo "MF schemes: {$mf} (with NAV history: {$mfNav})\n";
echo "NSE CSV path: " . (trim((string) ($cfg['nse_equity_csv'] ?? '')) ?: '(not set)') . "\n";
echo "BSE CSV path: " . (trim((string) ($cfg['bse_equity_csv'] ?? '')) ?: '(not set)') . "\n";
echo "BSE Upstox gz path: " . (trim((string) ($cfg['upstox_instruments_bse_gz_path'] ?? '')) ?: '(download from CDN)') . "\n";
echo "Worker PHP CLI: " . ($syncCfg['worker']['php_cli_binary'] ?? 'default') . "\n";
echo "Upstox token: " . ($tokenLen > 0 ? 'set' : 'missing') . ' — API test: ' . ($auth['ok'] ? 'OK' : ($auth['error'] ?? 'fail')) . "\n";
echo "Job queue: " . json_encode($queue) . "\n";
echo "\nNext steps:\n";
if ($stocks < 100) {
    echo "- Stocks: Market Data → Import NSE/BSE stocks (Upstox master). If CDN blocked, download .json.gz manually.\n";
}
if ($etfs === 0) {
    echo "- ETFs: Import NSE ETFs (Upstox master) or CSV.\n";
}
if ($mfNav < $mf) {
    echo "- MF NAV: Run “Download mutual fund NAV history” or sync per fund (full history).\n";
}
if (!empty($queue['queued'])) {
    echo "- Queue: Keep Sync Center tab open or run: php private/cron/process-queue.php --drain\n";
}

exit(0);
