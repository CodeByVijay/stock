<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/market_data_job_labels.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_bundle.php';
require_once PRIVATE_ROOT . '/services/upstox.php';

require_login();
auth_release_session_lock();

$pageTitle = 'Download Data';
$activeNav = 'sync';
$loadSyncCenterJs = true;
$recentJobs = [];
$dbError = null;
$stats = [];
$providers = [];
$syncCfg = [];
$sourceReady = [];
$upstoxConnected = false;
$intradayEnabled = false;
$coverageVerified = false;
$categoryLastSync = ['stocks' => null, 'etfs' => null, 'mutual_funds' => null];
$categoryCounts = [];
$activeCategories = ['stocks' => false, 'etfs' => false, 'mutual_funds' => false];

try {
    $pdo = db();
    $snapshot = market_data_center_snapshot_cached($pdo);
    $stats = is_array($snapshot['stats'] ?? null) ? $snapshot['stats'] : [];
    $categoryLastSync = is_array($snapshot['category_last_sync'] ?? null) ? $snapshot['category_last_sync'] : $categoryLastSync;
    $categoryCounts = is_array($snapshot['category_counts'] ?? null) ? $snapshot['category_counts'] : [];
    $activeCategories = sync_active_categories($pdo);
    $providers = providers_config();
    $syncCfg = sync_config();
    $coverageVerified = !empty($providers['coverage_claim_complete']);
    $intradayEnabled = !empty($syncCfg['intraday_enabled']);
    $upstoxConnected = upstox_access_token() !== '';

    $sourceReady = [
        'nse' => trim((string) ($providers['nse_equity_csv'] ?? '')) !== '',
        'bse' => trim((string) ($providers['bse_equity_csv'] ?? '')) !== '',
        'etf' => trim((string) ($providers['etf_master_csv'] ?? '')) !== '',
        'ohlcv' => trim((string) ($providers['equity_ohlcv_csv'] ?? '')) !== '',
        'upstox' => $upstoxConnected,
    ];

    $stmt = $pdo->query(
        'SELECT id, job_type, status, requested_at, started_at, finished_at,
                progress_total, progress_done, records_processed, error_message, result_json
         FROM sync_jobs
         ORDER BY id DESC
         LIMIT 15'
    );
    $recentJobs = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

/** @param mixed $raw @return array<string,mixed> */
function market_data_parse_result($raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $data = is_string($raw) ? json_decode($raw, true) : $raw;

    return is_array($data) ? $data : [];
}

function market_data_format_last_sync(?string $at): string
{
    if ($at === null || $at === '') {
        return 'Never';
    }

    return date('M j, Y g:i A', strtotime($at));
}

/** Plain reason when a “More options” action is greyed out (not a permissions issue). */
function market_data_source_not_ready_message(string $requirementKey): string
{
    switch ($requirementKey) {
        case 'upstox':
            return 'Connect your broker under Integrations first — then use the buttons in One-time setup or Start update.';
        case 'nse':
            return 'No NSE list file on this server. Use Download NSE share list (needs Integrations) instead of this option.';
        case 'bse':
            return 'No BSE list file on this server. Use Download BSE share list (needs Integrations) instead of this option.';
        case 'etf':
            return 'No ETF list file on this server. Try Download NSE ETF list (needs Integrations) instead.';
        case 'ohlcv':
            return 'Bulk price CSV import is not configured on this server. Use Start update with broker connected for normal prices.';
        case 'intraday':
            return 'Intraday is turned off for this site.';
        default:
            return 'This option is not set up on this server yet.';
    }
}

$primarySyncCards = [
    [
        'category' => 'stocks',
        'job_type' => 'sync_stocks',
        'easy_title' => 'Company shares',
        'easy_desc' => 'Updates your share list and latest prices (NSE & BSE).',
        'tone' => 'sky',
        'icon' => 'chart',
        'btn_label' => 'Start update',
        'stored_label' => 'Companies listed',
        'with_label' => 'With prices',
        'stored' => (int) ($categoryCounts['stocks_total'] ?? 0),
        'with_data' => (int) ($categoryCounts['stocks_with_prices'] ?? 0),
        'last' => market_data_format_last_sync($categoryLastSync['stocks']),
    ],
    [
        'category' => 'etfs',
        'job_type' => 'sync_etfs',
        'easy_title' => 'ETFs',
        'easy_desc' => 'Updates ETF names and latest prices.',
        'tone' => 'violet',
        'icon' => 'chart',
        'btn_label' => 'Start update',
        'stored_label' => 'ETFs listed',
        'with_label' => 'With prices',
        'stored' => (int) ($categoryCounts['etfs_total'] ?? 0),
        'with_data' => (int) ($categoryCounts['etfs_with_prices'] ?? 0),
        'last' => market_data_format_last_sync($categoryLastSync['etfs']),
    ],
    [
        'category' => 'mutual_funds',
        'job_type' => 'sync_mutual_funds',
        'easy_title' => 'Mutual funds',
        'easy_desc' => 'Updates fund names and latest NAV values.',
        'tone' => 'amber',
        'icon' => 'fund',
        'btn_label' => 'Start update',
        'stored_label' => 'Funds listed',
        'with_label' => 'With NAV',
        'stored' => (int) ($categoryCounts['mf_total'] ?? 0),
        'with_data' => (int) ($categoryCounts['mf_with_nav'] ?? 0),
        'last' => market_data_format_last_sync($categoryLastSync['mutual_funds']),
    ],
];

$setupActions = [
    [
        'type' => 'mf_scheme_master',
        'label' => 'Download all fund names',
        'help' => 'Fills the Mutual Funds menu only — not Equities.',
        'requires' => null,
        'disabled' => false,
    ],
    [
        'type' => 'upstox_nse_stocks',
        'label' => 'Download NSE share list',
        'help' => 'Company names from NSE.',
        'requires' => 'upstox',
        'disabled' => empty($sourceReady['upstox']),
        'disabled_reason' => market_data_source_not_ready_message('upstox'),
    ],
    [
        'type' => 'upstox_bse_stocks',
        'label' => 'Download BSE share list',
        'help' => 'Listed companies from BSE (MF units on BSE are excluded).',
        'requires' => 'upstox',
        'disabled' => empty($sourceReady['upstox']),
        'disabled_reason' => market_data_source_not_ready_message('upstox'),
    ],
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained pro-list-page max-w-6xl" id="sync-center"
          data-csrf="<?= e(csrf_token()) ?>"
          data-sources="<?= e(json_encode($sourceReady, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
          data-intraday="<?= $intradayEnabled ? '1' : '0' ?>"
          data-active-categories="<?= e(json_encode($activeCategories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">

        <?php if ($dbError): ?>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100" role="alert">
                Could not load market data status. <?= e($dbError) ?>
            </div>
        <?php endif; ?>

        <?php
        $syncCopy = portal_page_copy('sync');
        portal_render_page_hero($syncCopy['eyebrow'], $syncCopy['title'], $syncCopy['subtitle']);
        portal_render_pipeline_quickstart();
        portal_render_setup_buttons($setupActions);
        ?>

        <?php portal_render_section_head('Regular update', 'Use daily or whenever prices or NAV look old. Only one update per type at a time.'); ?>

        <section class="mdc-layout" aria-labelledby="mdc-primary-heading">
            <h2 id="mdc-primary-heading" class="sr-only">Regular update</h2>
            <?php foreach ($primarySyncCards as $card):
                portal_render_mdc_action_card($card, !empty($activeCategories[$card['category']]));
            endforeach; ?>
        </section>

        <section class="pro-panel pro-panel--status" aria-live="polite" aria-labelledby="mdc-activity-heading">
            <h2 id="mdc-activity-heading" class="pro-panel__title">Status — is it finished?</h2>
            <div id="queue-summary-panel" class="pro-status-line hidden"></div>
            <div id="active-job-panel" class="pro-status-body">
                <p class="pro-status-idle">Nothing is running right now. Press <strong>Start update</strong> above and stay on this page until you see <strong>Done</strong>.</p>
            </div>
            <div id="sync-status-msg" class="hidden pro-toast mt-3" role="status"></div>
            <div id="sync-stuck-panel" class="hidden mt-4 p-4 rounded-lg border border-amber-500/35 bg-amber-500/10">
                <p class="text-sm text-amber-100 mb-3">
                    This update looks <strong>stuck</strong> (no movement for a long time). Use the buttons below — saved data is kept.
                </p>
            </div>

            <div class="pro-queue-actions" aria-labelledby="queue-actions-title">
                <h3 id="queue-actions-title" class="pro-queue-actions__title">Run queue now</h3>
                <p class="pro-queue-actions__sub">If progress stopped, use these before starting a new update above.</p>
                <div class="pro-queue-actions__buttons">
                    <button type="button" id="sync-run-next-btn" class="portal-btn portal-btn--primary portal-btn--sm">Run next job now</button>
                    <button type="button" id="sync-run-batch-btn" class="portal-btn portal-btn--ghost portal-btn--sm">Run 3 jobs in this tab</button>
                    <button type="button" id="sync-resume-btn" class="portal-btn portal-btn--ghost portal-btn--sm">Fix stuck queue</button>
                    <button type="button" id="sync-spawn-btn" class="portal-btn portal-btn--ghost portal-btn--sm">Start background worker</button>
                </div>
            </div>

            <div id="queue-jobs-panel" class="pro-queue-jobs hidden">
                <h3 class="pro-queue-jobs__title">Waiting &amp; active tasks</h3>
                <ul id="queue-jobs-list" class="pro-queue-jobs__list"></ul>
            </div>

            <p class="pro-panel__foot">Keep this page open while jobs run here, or use <strong>Start background worker</strong> and refresh later. You will not get duplicate data.</p>
        </section>

        <details class="pro-advanced-block">
            <summary class="pro-advanced-block__summary">More options (history, imports, technical tools)</summary>
            <div class="pro-advanced-block__body space-y-8">

        <?php portal_render_section_head('Items still missing data', 'These counts help you see what still needs a download.'); ?>
        <section class="pro-panel" aria-labelledby="mdc-coverage-heading">
            <dl class="grid sm:grid-cols-2 gap-4 text-sm">
                <div class="flex justify-between gap-4 pb-2 border-b border-surface-border/50">
                    <dt class="text-slate-400">Shares without price history</dt>
                    <dd class="font-mono text-slate-200"><?= (int) ($stats['stocks_without_history'] ?? 0) ?></dd>
                </div>
                <div class="flex justify-between gap-4 pb-2 border-b border-surface-border/50">
                    <dt class="text-slate-400">ETFs without price history</dt>
                    <dd class="font-mono text-slate-200"><?= (int) ($stats['etfs_without_history'] ?? 0) ?></dd>
                </div>
                <div class="flex justify-between gap-4 pb-2 border-b border-surface-border/50">
                    <dt class="text-slate-400">Funds without NAV</dt>
                    <dd class="font-mono text-slate-200"><?= (int) ($stats['mf_schemes_without_nav'] ?? 0) ?></dd>
                </div>
                <div class="flex justify-between gap-4 pb-2 border-b border-surface-border/50">
                    <dt class="text-slate-400">Problems last 7 days</dt>
                    <dd class="font-mono text-slate-200"><?= (int) ($stats['recent_failed_records'] ?? 0) ?></dd>
                </div>
            </dl>
        </section>

        <?php
        $workflows = [
            'import' => [
                'title' => 'Extra list downloads',
                'desc' => 'Special imports (usually handled in One-time setup above).',
                'actions' => [
                    ['type' => 'upstox_nse_etfs', 'label' => 'Download NSE ETF list', 'help' => 'ETF names from NSE.', 'requires' => 'upstox'],
                    ['type' => 'nse_equity_master', 'label' => 'NSE list from file', 'help' => 'For advanced server setup.', 'requires' => 'nse'],
                    ['type' => 'bse_equity_master', 'label' => 'BSE list from file', 'help' => 'For advanced server setup.', 'requires' => 'bse'],
                    ['type' => 'etf_master', 'label' => 'ETF list from file', 'help' => 'For advanced server setup.', 'requires' => 'etf'],
                ],
            ],
            'history' => [
                'title' => 'Old data (years of history)',
                'desc' => 'For charts and research — can take a long time.',
                'actions' => [
                    ['type' => 'mf_nav_backfill', 'label' => 'Download full NAV history (all funds)', 'help' => 'Run once; may take hours.', 'requires' => null],
                    ['type' => 'equity_ohlcv_csv', 'label' => 'Import prices from file', 'help' => 'Admin uploads a CSV on the server.', 'requires' => 'ohlcv'],
                    ['type' => 'history_backfill', 'label' => 'Fill missing share price history', 'help' => 'Needs broker connection.', 'requires' => 'upstox'],
                ],
            ],
            'latest' => [
                'title' => 'Extra price updates',
                'desc' => 'Optional refreshes beyond the main Start update buttons.',
                'actions' => [
                    ['type' => 'mf_nav_daily', 'label' => 'Refresh latest NAV (all funds)', 'help' => 'Bulk NAV update.', 'requires' => null],
                    ['type' => 'equity_daily_upstox', 'label' => 'Refresh latest share prices', 'help' => 'Needs broker connection.', 'requires' => 'upstox'],
                    ['type' => 'etf_daily_upstox', 'label' => 'Refresh latest ETF prices', 'help' => 'Needs broker connection.', 'requires' => 'upstox'],
                    ['type' => 'intraday', 'label' => 'Intraday charts', 'help' => 'Optional.', 'requires' => 'intraday'],
                ],
            ],
        ];
        foreach ($workflows as $wfKey => $wf):
            portal_render_section_head($wf['title'], $wf['desc']);
            ?>
            <section class="mdc-action-grid mb-8" aria-labelledby="mdc-wf-<?= e($wfKey) ?>">
                <h2 id="mdc-wf-<?= e($wfKey) ?>" class="sr-only"><?= e($wf['title']) ?></h2>
                    <?php foreach ($wf['actions'] as $action):
                        $req = $action['requires'];
                        $disabled = false;
                        $disabledReason = '';
                        if ($req === 'intraday' && !$intradayEnabled) {
                            $disabled = true;
                            $disabledReason = market_data_source_not_ready_message('intraday');
                        } elseif ($req !== null && $req !== 'intraday' && empty($sourceReady[$req])) {
                            $disabled = true;
                            $disabledReason = market_data_source_not_ready_message((string) $req);
                        }
                        ?>
                        <button type="button"
                                class="mdc-action-btn sync-start-btn mdc-action-tile"
                                data-job-type="<?= e($action['type']) ?>"
                                data-requires="<?= e((string) ($req ?? '')) ?>"
                                <?= $disabled ? 'disabled title="' . e($disabledReason) . '"' : '' ?>>
                            <span class="mdc-action-tile__title"><?= e($action['label']) ?></span>
                            <span class="mdc-action-tile__note"><?= e($action['help']) ?></span>
                            <?php if ($disabled && $disabledReason): ?>
                                <span class="mdc-action-tile__warn"><?= e($disabledReason) ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <?php portal_render_section_head('Recent activity log', 'Last 15 tasks for support.'); ?>
        <section class="portal-table-wrap">
            <?php if ($recentJobs === []): ?>
                <p class="p-5 text-sm text-slate-500">No jobs recorded.</p>
            <?php else: ?>
                <table class="portal-table min-w-[32rem]">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Status</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentJobs as $job):
                        $result = market_data_parse_result($job['result_json'] ?? null);
                        ?>
                        <tr class="border-b border-surface-border/40">
                            <td class="py-2.5 pr-3"><?= e(market_data_job_label_simple((string) $job['job_type'])) ?></td>
                            <td class="py-2.5 pr-3">
                                <span class="inline-flex items-center gap-1.5">
                                    <?php if ($job['status'] === 'completed'): ?>
                                        <span class="w-2 h-2 rounded-full bg-emerald-400" aria-hidden="true"></span>
                                    <?php elseif ($job['status'] === 'failed'): ?>
                                        <span class="w-2 h-2 rounded-full bg-red-400" aria-hidden="true"></span>
                                    <?php elseif ($job['status'] === 'running'): ?>
                                        <span class="w-2 h-2 rounded-full bg-sky-400 animate-pulse" aria-hidden="true"></span>
                                    <?php else: ?>
                                        <span class="w-2 h-2 rounded-full bg-slate-500" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <?= e(market_data_status_label((string) $job['status'])) ?>
                                </span>
                                <?php if (!empty($job['error_message'])): ?>
                                    <p class="text-xs text-red-300/90 mt-0.5"><?= e((string) $job['error_message']) ?></p>
                                <?php elseif ($result !== []): ?>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        <?php
                                        $bits = [];
                                        if (isset($result['inserted'])) {
                                            $bits[] = (int) $result['inserted'] . ' added';
                                        }
                                        if (isset($result['updated'])) {
                                            $bits[] = (int) $result['updated'] . ' updated';
                                        }
                                        if (isset($result['skipped'])) {
                                            $bits[] = (int) $result['skipped'] . ' skipped';
                                        }
                                        if (isset($result['failed'])) {
                                            $bits[] = (int) $result['failed'] . ' failed';
                                        }
                                        echo e($bits === [] ? 'Done' : implode(', ', $bits));
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                            <td class="py-2.5 pr-3 text-slate-400 whitespace-nowrap"><?= e((string) ($job['finished_at'] ?? $job['requested_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <details class="pro-panel group">
            <summary class="cursor-pointer text-sm font-medium text-slate-300 hover:text-slate-100 list-none flex items-center justify-between">
                Technical diagnostics (support team)
                <span class="text-xs text-slate-600 group-open:rotate-180 transition-transform">▼</span>
            </summary>
            <div class="pt-4 mt-4 text-xs text-slate-500 space-y-3 border-t border-surface-border/60">
                <p>Configuration state for implementation and support teams.</p>
                <ul class="space-y-1 font-mono text-slate-400">
                    <li>NSE list file: <?= $sourceReady['nse'] ? 'configured' : 'not set' ?></li>
                    <li>BSE list file: <?= $sourceReady['bse'] ? 'configured' : 'not set' ?></li>
                    <li>ETF list file: <?= $sourceReady['etf'] ? 'configured' : 'not set' ?></li>
                    <li>OHLCV import file: <?= $sourceReady['ohlcv'] ? 'configured' : 'not set' ?></li>
                    <li>Brokerage API: <?= $upstoxConnected ? 'connected' : 'not connected' ?></li>
                    <li>Config: private/config/providers.php, private/config/sync.php</li>
                    <li>Docs: private/docs/INSTRUMENT_SOURCES.md</li>
                </ul>
                <div class="flex flex-wrap gap-2 pt-2">
                    <button type="button" class="sync-start-all px-2 py-1 rounded border border-surface-border text-slate-400 hover:text-slate-200">Queue full pipeline (includes history backfill)</button>
                    <button type="button" class="sync-start-btn px-2 py-1 rounded border border-surface-border text-slate-400 hover:text-slate-200" data-job-type="recalculate_indicators">Refresh indicators</button>
                    <button type="button" class="sync-start-btn px-2 py-1 rounded border border-surface-border text-slate-400 hover:text-slate-200" data-job-type="sync_all_available">Run orchestrator job</button>
                </div>
            </div>
        </details>

            </div>
        </details>

        <!-- Confirm modal -->
        <dialog id="mdc-confirm-dialog" class="backdrop:bg-black/60 bg-surface-card border border-surface-border rounded-xl p-0 text-slate-100 max-w-md w-[calc(100%-2rem)] shadow-2xl">
            <form method="dialog" class="p-6 space-y-4">
                <h3 class="text-lg font-medium">Run full pipeline?</h3>
                <p class="text-sm text-slate-400 leading-relaxed">
                    Queues master import, NAV backfill, and price refresh stages where configured. Runtime scales with dataset size.
                </p>
                <div class="flex justify-end gap-2 pt-2">
                    <button value="cancel" class="portal-btn portal-btn--ghost">Cancel</button>
                    <button type="button" id="mdc-confirm-sync-all" value="confirm" class="portal-btn portal-btn--primary">Confirm</button>
                </div>
            </form>
        </dialog>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
