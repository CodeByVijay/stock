<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once PRIVATE_ROOT . '/services/predictions.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';

require_login();

/** @return array<string,mixed>|null */
function screener_progress_from_session(int $runId): ?array
{
    $all = $_SESSION['screener_run_progress'] ?? null;
    if (!is_array($all)) {
        return null;
    }
    $row = $all[$runId] ?? null;

    return is_array($row) ? $row : null;
}

/** @param array<string,mixed> $progress */
function screener_progress_save(int $runId, array $progress): void
{
    if (!isset($_SESSION['screener_run_progress']) || !is_array($_SESSION['screener_run_progress'])) {
        $_SESSION['screener_run_progress'] = [];
    }
    $_SESSION['screener_run_progress'][$runId] = $progress;
}

$pageTitle = 'Screener';
$activeNav = 'predictions';
$message = flash_get('success');
$error = flash_get('error');
$selectedRunId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? 'run');

    try {
        $pdo = db();
        if ($action === 'check_run_progress') {
            $runId = (int) ($_POST['run_id'] ?? 0);
            if ($runId <= 0) {
                throw new InvalidArgumentException('Choose a run to check.');
            }
            $progress = prediction_run_live_progress($pdo, $runId);
            screener_progress_save($runId, $progress);
            flash_set(
                'success',
                'Run #' . $runId . ': progress updated as of '
                . date('M j, Y g:i A', strtotime($progress['checked_at'])) . '. See the table below.'
            );
            redirect('predictions.php?run_id=' . $runId . '#screener-run-results');
        }

        if ($action === 'evaluate_run') {
            $runId = (int) ($_POST['run_id'] ?? 0);
            if ($runId <= 0) {
                throw new InvalidArgumentException('Choose a run to check.');
            }
            $count = prediction_evaluate_run_due_outcomes($pdo, $runId);
            if ($count === 0) {
                flash_set('success', 'Run #' . $runId . ': wait period not over yet — use Check progress for today’s numbers.');
            } else {
                flash_set('success', 'Run #' . $runId . ': final results updated for ' . $count . ' pick(s).');
            }
            redirect('predictions.php?run_id=' . $runId . '#screener-run-results');
        }

        if ($action === 'evaluate') {
            $count = prediction_evaluate_due_outcomes($pdo);
            if ($count === 0) {
                flash_set('success', 'Nothing to check right now — no past picks have passed their wait period yet.');
            } else {
                flash_set('success', 'Updated results for ' . $count . ' past pick(s) whose wait period has ended.');
            }
            redirect('predictions.php');
        }

        $post = $_POST;
        $customCount = (int) ($post['result_count_custom'] ?? 0);
        if ($customCount > 0) {
            $post['result_count'] = $customCount;
        }
        $settings = prediction_validate_settings($post);
        $runId = prediction_create_run($pdo, $settings);
        $job = sync_create_job($pdo, 'run_screener', ['prediction_run_id' => $runId]);
        if (!$job['ok']) {
            prediction_mark_run_failed($pdo, $runId, $job['error'] ?? 'Could not queue worker job.');
            flash_set('error', $job['error'] ?? 'Could not queue screener job.');
            redirect('predictions.php');
        }
        $worker = sync_worker_ensure_running($pdo);
        if (!$worker['ok'] && !$worker['running']) {
            flash_set('success', 'Prediction run #' . $runId . ' queued.');
            flash_set(
                'error',
                'Auto-processing did not start: ' . ($worker['error'] ?? 'unknown error')
                . ' Open Sync Center and run the screener from there, or start private/bin/start-sync-worker.sh.'
            );
        } else {
            flash_set('success', 'Screener run #' . $runId . ' started. Results will appear below in a moment — refresh if needed.');
        }
        redirect('predictions.php?run_id=' . $runId . '#screener-run-results');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect('predictions.php');
    }
}

$runs = [];
$results = [];
$runDetail = null;

try {
    auth_release_session_lock();
    $pdo = db();
    $runs = $pdo->query(
        'SELECT id, status, started_at, completed_at, error_message, settings_snapshot_json
         FROM prediction_runs ORDER BY id DESC LIMIT 15'
    )->fetchAll() ?: [];

    if ($selectedRunId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM prediction_runs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $selectedRunId]);
        $runDetail = $stmt->fetch() ?: null;

        if ($runDetail) {
            $stmt = $pdo->prepare(
                'SELECT pr.*, i.symbol, i.name AS instrument_name, s.scheme_name,
                        po.outcome_status, po.realized_return_percent, po.evaluated_at
                 FROM prediction_results pr
                 LEFT JOIN instruments i ON i.id = pr.instrument_id
                 LEFT JOIN mutual_fund_schemes s ON s.id = pr.scheme_id
                 LEFT JOIN prediction_outcomes po ON po.prediction_result_id = pr.id
                 WHERE pr.run_id = :id
                 ORDER BY pr.rank_position ASC'
            );
            $stmt->execute(['id' => $selectedRunId]);
            $results = $stmt->fetchAll() ?: [];
        }
    }
} catch (Throwable $e) {
    $error = $error ?? $e->getMessage();
}

$defaults = prediction_defaults();
$loadPredictionsJs = true;
$runProgress = ($selectedRunId > 0) ? screener_progress_from_session($selectedRunId) : null;
$viewingRun = $runDetail !== null;

/** @param string|null $status */
function screener_outcome_label(?string $status): string
{
    switch ($status) {
        case 'target_reached':
            return 'Goal reached';
        case 'target_not_reached':
            return 'Goal not reached';
        case 'not_evaluable':
            return 'Could not verify';
        case 'Pending horizon':
        case '':
        case null:
            return 'Still waiting';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function screener_run_status_label(string $status): string
{
    switch ($status) {
        case 'completed':
            return 'Done';
        case 'running':
            return 'Working…';
        case 'failed':
            return 'Problem';
        case 'queued':
            return 'Waiting';
        default:
            return $status;
    }
}

/**
 * @param array<string,mixed> $runDetail
 * @param list<array<string,mixed>> $results
 * @param array<string,mixed>|null $snap
 * @param array<string,mixed>|null $scanStats
 * @param array<string,mixed>|null $runProgress
 */
function screener_render_run_panel(array $runDetail, array $results, ?array $snap, ?array $scanStats, ?array $runProgress): void
{
    $runId = (int) $runDetail['id'];
    $runStatus = screener_run_status_label((string) $runDetail['status']);
    $resultCount = count($results);
    $progressItems = is_array($runProgress['items'] ?? null) ? $runProgress['items'] : [];
    $progressAt = isset($runProgress['checked_at']) ? (string) $runProgress['checked_at'] : '';
    ?>
    <div id="screener-run-results" class="pro-run-view-anchor pro-data-table portal-animate-in" tabindex="-1">
        <div class="pro-run-view-banner" role="status">
            <div>
                <p class="pro-run-view-banner__title">Showing results for run #<?= $runId ?></p>
                <p class="pro-run-view-banner__sub">Same page — scroll is not a new window. Use <strong>Close</strong> to hide this run.</p>
            </div>
            <div class="pro-run-view-banner__actions">
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="check_run_progress">
                    <input type="hidden" name="run_id" value="<?= $runId ?>">
                    <button type="submit" class="portal-btn portal-btn--primary portal-btn--sm">Check progress now</button>
                </form>
                <a href="predictions.php" class="portal-btn portal-btn--ghost portal-btn--sm">Close</a>
            </div>
        </div>
        <?php
        portal_render_data_table_head(
            'Run #' . $runId . ' — ' . $runStatus,
            [['label' => 'Picks', 'value' => (string) $resultCount]],
            is_array($snap)
                ? 'Goal ' . ($snap['target_percent'] ?? '') . '% · '
                  . ($snap['horizon_value'] ?? '') . ' ' . ($snap['horizon_unit'] ?? '')
                  . ' · final check after ' . ($snap['horizon_end_date'] ?? '')
                : ''
        );
        ?>
        <?php if ($progressAt !== ''): ?>
            <p class="pro-screener-run-meta pro-screener-run-meta--highlight">
                Live progress last checked: <?= e(date('M j, Y g:i A', strtotime($progressAt))) ?>
            </p>
        <?php else: ?>
            <p class="pro-screener-run-meta">Press <strong>Check progress now</strong> to see how much of the goal each pick has reached today.</p>
        <?php endif; ?>
        <?php if (is_array($scanStats) && (int) ($scanStats['scanned'] ?? 0) > 0): ?>
            <p class="pro-screener-run-meta">
                Looked at <?= (int) ($scanStats['scanned'] ?? 0) ?> names ·
                <?= (int) ($scanStats['eligible_ranked'] ?? 0) ?> qualified for ranking.
            </p>
        <?php endif; ?>
        <?php if ($results === []): ?>
            <p class="p-5 text-sm text-slate-400">
                No picks in this run yet<?php if (!empty($runDetail['error_message'])): ?> — <?= e($runDetail['error_message']) ?><?php endif; ?>.
            </p>
        <?php else: ?>
        <div class="portal-table-wrap">
        <table class="portal-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th class="portal-table__num">Start price</th>
                    <th class="portal-table__num">Goal price</th>
                    <th class="portal-table__num">Score</th>
                    <th class="portal-table__num">Progress now</th>
                    <th>Final result</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <?php
                $prId = (int) $r['id'];
                $outcomeRaw = $r['outcome_status'] ?? null;
                $outcomePending = $outcomeRaw === null || $outcomeRaw === '';
                $outcomeLabel = screener_outcome_label($outcomePending ? null : (string) $outcomeRaw);
                $horizonEnd = (string) ($r['horizon_end_date'] ?? '');
                $live = $progressItems[$prId] ?? null;
                ?>
                <tr>
                    <td><?= (int) $r['rank_position'] ?></td>
                    <td class="portal-table__name">
                        <?= e($r['instrument_name'] ?? $r['scheme_name'] ?? '—') ?>
                        <?php if (!empty($r['symbol'])): ?>
                            <span class="text-slate-500">(<?= e($r['symbol']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="portal-table__num font-mono"><?= e(number_format((float) $r['base_value'], 4)) ?></td>
                    <td class="portal-table__num font-mono"><?= e(number_format((float) $r['target_value'], 4)) ?></td>
                    <td class="portal-table__num"><?= e(number_format((float) $r['technical_score'], 2)) ?></td>
                    <td class="text-sm portal-table__num">
                        <?php if (is_array($live) && !empty($live['ok'])): ?>
                            <strong class="text-indigo-200"><?= e((string) $live['goal_reached_percent']) ?>%</strong>
                            <span class="text-slate-500"> of goal</span>
                            <span class="block text-xs text-slate-400 mt-0.5">
                                Change <?= e((string) $live['return_percent']) ?>%
                                <?php if (!empty($live['goal_touched'])): ?>
                                    · touched goal price
                                <?php endif; ?>
                            </span>
                        <?php elseif (is_array($live) && empty($live['ok'])): ?>
                            <span class="text-slate-500 text-xs"><?= e((string) ($live['message'] ?? '—')) ?></span>
                        <?php else: ?>
                            <span class="text-slate-500">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm">
                        <span class="portal-badge <?= $outcomeRaw === 'target_reached' ? 'portal-badge--ok' : ($outcomeRaw === 'target_not_reached' ? 'portal-badge--warn' : 'portal-badge--muted') ?>">
                            <?= e($outcomeLabel) ?>
                        </span>
                        <?php if ($outcomePending && $horizonEnd !== ''): ?>
                            <span class="block text-xs text-slate-500 mt-1">Final after <?= e($horizonEnd) ?></span>
                        <?php elseif ($r['realized_return_percent'] !== null): ?>
                            <span class="block text-xs text-slate-400 mt-1">Actual change <?= e((string) $r['realized_return_percent']) ?>%</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($r['risk_note'])): ?>
                <tr><td colspan="7" class="pb-2 text-xs text-slate-500"><?= e((string) $r['risk_note']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained pro-list-page">
        <?php
        $predCopy = portal_page_copy('predictions');
        portal_render_page_header($predCopy['title'], $predCopy['subtitle']);
        ?>

        <?php if ($message): ?>
            <div class="pro-toast pro-toast--ok" role="status"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="portal-alert portal-alert--warn pro-toast pro-toast--err" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($viewingRun): ?>
            <?php
            $snapTop = json_decode((string) $runDetail['settings_snapshot_json'], true);
            $scanTop = json_decode((string) ($runDetail['scan_stats_json'] ?? ''), true);
            screener_render_run_panel(
                $runDetail,
                $results,
                is_array($snapTop) ? $snapTop : null,
                is_array($scanTop) ? $scanTop : null,
                $runProgress
            );
            ?>
        <?php endif; ?>

        <?php if (!$viewingRun): ?>
        <section class="pro-quickstart portal-animate-in" aria-labelledby="screener-quickstart-title">
            <h3 id="screener-quickstart-title" class="pro-quickstart__title">How it works — 3 steps</h3>
            <ol class="pro-quickstart__steps">
                <li>
                    <span class="pro-quickstart__num">1</span>
                    <div>
                        <strong>Set your goal</strong>
                        <p>Example: <em>8%</em> rise within <em>30 days</em> on stocks you already downloaded.</p>
                    </div>
                </li>
                <li>
                    <span class="pro-quickstart__num">2</span>
                    <div>
                        <strong>Run screener</strong>
                        <p>We rank names by recent trends — you get a short list of top picks.</p>
                    </div>
                </li>
                <li>
                    <span class="pro-quickstart__num">3</span>
                    <div>
                        <strong>Check later</strong>
                        <p>After the wait period ends, press <em>Check finished picks</em> to see if the goal was met.</p>
                    </div>
                </li>
            </ol>
            <p class="pro-quickstart__tip">Research tool only — not buy/sell advice. Need prices first? Use <a class="portal-link" href="sync-center.php">Download Data</a>.</p>
        </section>
        <?php endif; ?>

        <section class="pro-screener-card portal-animate-in portal-animate-in--delay" aria-labelledby="screener-form-title">
            <?php if ($viewingRun): ?>
                <details>
                    <summary class="pro-section-head__title cursor-pointer">Start another screener run</summary>
                    <p class="pro-section-head__sub mt-2">Opens the form below.</p>
            <?php else: ?>
            <h2 id="screener-form-title" class="pro-section-head__title">New screener run</h2>
            <p class="pro-section-head__sub">Choose what to scan and your target. Defaults work well for a first try.</p>
            <?php endif; ?>
            <form method="post" class="pro-screener-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="run">
                <div class="pro-screener-field">
                    <label for="scr-asset">What to scan</label>
                    <select id="scr-asset" name="asset_type" class="portal-form-input">
                        <option value="stock">Company shares</option>
                        <option value="etf">ETFs</option>
                        <option value="mutual_fund">Mutual funds</option>
                    </select>
                </div>
                <div class="pro-screener-field">
                    <label for="scr-target">Goal: price goes up by (%)</label>
                    <input id="scr-target" type="number" step="0.1" name="target_percent" value="8" required class="portal-form-input">
                </div>
                <div class="pro-screener-field pro-screener-field--horizon">
                    <label for="scr-horizon">Watch period</label>
                    <div class="pro-screener-horizon">
                        <input id="scr-horizon" type="number" name="horizon_value" value="30" min="1" required class="portal-form-input">
                        <select name="horizon_unit" class="portal-form-input" aria-label="Period unit">
                            <option value="days">Days</option>
                            <option value="months">Months</option>
                            <option value="years">Years</option>
                        </select>
                    </div>
                </div>
                <div class="pro-screener-field">
                    <label>Show top picks</label>
                    <div class="pro-screener-topn">
                        <?php foreach ([3, 5, 10] as $n): ?>
                            <label class="pro-screener-radio"><input type="radio" name="result_count" value="<?= $n ?>" <?= $n === 5 ? 'checked' : '' ?>> Top <?= $n ?></label>
                        <?php endforeach; ?>
                    </div>
                    <input type="number" name="result_count_custom" min="1" max="<?= PREDICTION_MAX_RESULTS ?>"
                           placeholder="Or custom (max <?= (int) PREDICTION_MAX_RESULTS ?>)" class="portal-form-input mt-2">
                </div>
                <div class="pro-screener-field">
                    <label for="scr-universe">Which list</label>
                    <select id="scr-universe" name="universe_type" class="portal-form-input">
                        <option value="all">Everything in your database</option>
                        <option value="watchlist">Watchlist only (shares &amp; ETFs)</option>
                    </select>
                </div>
                <details class="pro-screener-advanced md:col-span-2">
                    <summary>Advanced options</summary>
                    <div class="pro-screener-field mt-3">
                        <label for="scr-min-bars">Minimum price history (days of data)</label>
                        <input id="scr-min-bars" type="number" name="min_history_bars" value="<?= (int) $defaults['min_history_bars'] ?>" min="30" class="portal-form-input">
                        <p class="pro-screener-field__hint">Skip names with too little history. Default is fine for most users.</p>
                    </div>
                </details>
                <div class="pro-screener-actions md:col-span-2">
                    <button type="submit" class="portal-btn portal-btn--primary">Run screener</button>
                </div>
            </form>
            <?php if ($viewingRun): ?>
                </details>
            <?php endif; ?>
        </section>

        <section class="pro-check-picks-card portal-animate-in portal-animate-in--delay" aria-labelledby="check-picks-title">
            <h2 id="check-picks-title" class="pro-check-picks-card__title">Check finished picks</h2>
            <p class="pro-check-picks-card__body">
                When you ran the screener earlier, each pick had a <strong>wait period</strong> (for example 30 days).
                After that date passes, this button compares real prices/NAV to your goal and marks picks as
                <strong>Goal reached</strong> or <strong>Goal not reached</strong>. Safe to press anytime — it only updates picks whose wait period has ended.
            </p>
            <form method="post" class="mt-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="evaluate">
                <button type="submit" class="portal-btn portal-btn--ghost">Check finished picks now</button>
            </form>
            <p class="pro-check-picks-card__foot">Shares: goal counts if the daily high touched your target. Funds: published NAV at or above target.</p>
        </section>

        <div class="pro-data-table portal-animate-in portal-animate-in--delay">
            <?php portal_render_data_table_head('Recent runs', [], 'Open results jumps to the panel at the top of this page'); ?>
            <div class="portal-table-wrap">
            <?php if ($runs === []): ?>
                <p class="p-5 text-sm text-slate-500">No runs yet — use <strong>Run screener</strong> above.</p>
            <?php else: ?>
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Run</th>
                            <th>Status</th>
                            <th>When</th>
                            <th class="portal-table__actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($runs as $run): ?>
                        <?php $rid = (int) $run['id']; ?>
                        <tr class="<?= $selectedRunId === $rid ? 'pro-run-row--active' : '' ?>">
                            <td class="font-mono">#<?= $rid ?></td>
                            <td><?= e(screener_run_status_label((string) $run['status'])) ?></td>
                            <td class="text-sm text-slate-400"><?= e((string) ($run['completed_at'] ?? $run['started_at'] ?? '—')) ?></td>
                            <td class="portal-table__actions">
                                <a class="portal-btn portal-btn--primary portal-btn--sm" href="predictions.php?run_id=<?= $rid ?>#screener-run-results">Open results</a>
                                <form method="post" class="inline ml-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="check_run_progress">
                                    <input type="hidden" name="run_id" value="<?= $rid ?>">
                                    <button type="submit" class="portal-btn portal-btn--ghost portal-btn--sm" title="See % of goal reached today">Check progress</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
