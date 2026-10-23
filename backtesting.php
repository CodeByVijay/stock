<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once PRIVATE_ROOT . '/services/backtesting.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';

require_login();

$pageTitle = 'Backtests';
$activeNav = 'backtesting';
$message = flash_get('success');
$error = flash_get('error');
auth_release_session_lock();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $pdo = db();
        $settings = backtest_validate_settings($_POST);
        $runId = backtest_create_run($pdo, $settings);
        $job = sync_create_job($pdo, 'run_backtest', ['backtest_run_id' => $runId]);
        if (!$job['ok']) {
            backtest_mark_failed($pdo, $runId, $job['error'] ?? 'Queue failed');
            flash_set('error', $job['error'] ?? 'Could not queue backtest.');
            redirect('backtesting.php');
        }
        $worker = sync_worker_ensure_running($pdo);
        if (!$worker['ok'] && !$worker['running']) {
            flash_set('success', 'Backtest #' . $runId . ' queued. Open Sync Center to finish processing if it does not start automatically.');
            if (!empty($worker['error'])) {
                flash_set('error', $worker['error']);
            }
        } else {
            flash_set('success', 'Backtest #' . $runId . ' queued and processing started.');
        }
        redirect('backtesting.php?run_id=' . $runId);
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('backtesting.php');
    }
}

$runId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;
$runs = [];
$detail = null;

try {
    $pdo = db();
    $runs = $pdo->query(
        'SELECT id, asset_type, start_date, end_date, status, created_at, summary_json
         FROM backtest_runs ORDER BY id DESC LIMIT 12'
    )->fetchAll() ?: [];
    if ($runId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM backtest_runs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $runId]);
        $detail = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $error = $error ?? $e->getMessage();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained space-y-6">
        <?php if ($message): ?>
            <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200"><?= e($error) ?></div>
        <?php endif; ?>

        <?php
        $btCopy = portal_page_copy('backtesting');
        portal_render_page_header($btCopy['title'], $btCopy['subtitle']);
        ?>

        <section class="portal-card">
            <h2 class="text-sm font-medium text-slate-200 mb-2">Basic backtest</h2>
            <p class="text-xs text-slate-500 mb-4">
                Historical simulation using the same rule-based scorer as the screener, stepped through time without look-ahead.
                Results are not a guarantee of future performance. Stored universe only (survivorship bias).
            </p>
            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <?= csrf_field() ?>
                <div>
                    <label class="block text-slate-400 mb-1">Asset type</label>
                    <select name="asset_type" class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2">
                        <option value="stock">Stocks</option>
                        <option value="etf">ETFs</option>
                        <option value="mutual_fund">Mutual funds</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Target %</label>
                    <input type="number" step="0.1" name="target_percent" value="8" class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Start date</label>
                    <input type="date" name="start_date" required class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">End date</label>
                    <input type="date" name="end_date" required class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Horizon</label>
                    <div class="flex gap-2">
                        <input type="number" name="horizon_value" value="30" min="1" class="w-24 rounded-lg border border-surface-border bg-surface px-3 py-2">
                        <select name="horizon_unit" class="flex-1 rounded-lg border border-surface-border bg-surface px-3 py-2">
                            <option value="days">Days</option>
                            <option value="months">Months</option>
                            <option value="years">Years</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Step (days between samples)</label>
                    <input type="number" name="step_days" value="10" min="5" max="30" class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Fee % (round trip assumption)</label>
                    <input type="number" step="0.01" name="fee_percent" value="0" class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Slippage %</label>
                    <input type="number" step="0.01" name="slippage_percent" value="0.05" class="w-full rounded-lg border border-surface-border bg-surface px-3 py-2">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-600">Run backtest (queue)</button>
                </div>
            </form>
        </section>

        <?php if ($detail): ?>
            <?php $summary = json_decode((string) ($detail['summary_json'] ?? ''), true); ?>
            <section class="rounded-xl border border-surface-border bg-surface-card p-5">
                <h3 class="text-sm font-medium">Run #<?= (int) $detail['id'] ?> — <?= e((string) $detail['status']) ?></h3>
                <?php if (is_array($summary) && !isset($summary['error'])): ?>
                    <dl class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-4 text-sm">
                        <div><dt class="text-slate-500">Cases</dt><dd><?= e((string) ($summary['cases'] ?? '—')) ?></dd></div>
                        <div><dt class="text-slate-500">Target hit rate</dt><dd><?= isset($summary['target_hit_rate']) ? e((string) $summary['target_hit_rate']) . '%' : '—' ?></dd></div>
                        <div><dt class="text-slate-500">Avg return (net)</dt><dd><?= isset($summary['average_return_percent']) ? e((string) $summary['average_return_percent']) . '%' : '—' ?></dd></div>
                        <div><dt class="text-slate-500">Negative outcomes</dt><dd><?= e((string) ($summary['negative_outcomes'] ?? '—')) ?></dd></div>
                        <div><dt class="text-slate-500">Worst drawdown</dt><dd><?= isset($summary['worst_drawdown_percent']) ? e((string) $summary['worst_drawdown_percent']) . '%' : '—' ?></dd></div>
                        <div><dt class="text-slate-500">Costs assumed</dt><dd><?= e((string) ($summary['fees_slippage_assumed_percent'] ?? '0')) ?>%</dd></div>
                    </dl>
                    <p class="text-xs text-slate-500 mt-3"><?= e((string) ($summary['limitations'] ?? '')) ?></p>
                <?php elseif (is_array($summary) && isset($summary['error'])): ?>
                    <p class="text-red-300 text-sm mt-2"><?= e((string) $summary['error']) ?></p>
                <?php else: ?>
                    <p class="text-slate-500 text-sm mt-2">Processing or no summary yet.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="rounded-xl border border-surface-border bg-surface-card p-5">
            <h3 class="text-sm font-medium mb-3">Recent backtests</h3>
            <ul class="text-sm space-y-2">
                <?php foreach ($runs as $run): ?>
                    <li>
                        <a href="backtesting.php?run_id=<?= (int) $run['id'] ?>" class="text-emerald-300 hover:underline">
                            #<?= (int) $run['id'] ?> · <?= e($run['asset_type']) ?> · <?= e($run['status']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
