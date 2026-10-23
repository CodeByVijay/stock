<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
auth_release_session_lock();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$loadDashboardJs = true;
$loadDashboardCss = true;
$dbOk = true;
$dbMessage = null;

try {
    db();
} catch (Throwable $e) {
    $dbOk = false;
    $dbMessage = $e->getMessage();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column dashboard-shell" id="dashboard-root">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained space-y-6">
        <?php if (!$dbOk): ?>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-100" role="alert">
                <?= e($dbMessage ?? 'Database unavailable.') ?>
            </div>
        <?php else: ?>
            <?php
            $dashCopy = portal_page_copy('dashboard');
            portal_render_page_hero($dashCopy['eyebrow'], $dashCopy['title'], $dashCopy['subtitle']);
            ?>

            <?php portal_render_section_head('Coverage', 'Live counts from the active database.'); ?>

            <section aria-label="Key metrics" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4" id="dash-kpi-grid">
                <?php
                $kpiDefs = [
                    ['key' => 'stocks_total', 'label' => 'Equities', 'hint' => 'Instruments in master', 'accent' => 'from-indigo-500/20 to-transparent', 'bar' => null],
                    ['key' => 'etfs_total', 'label' => 'ETFs', 'hint' => 'Instruments in master', 'accent' => 'from-violet-500/20 to-transparent', 'bar' => null],
                    ['key' => 'mf_total', 'label' => 'MF schemes', 'hint' => 'Registered schemes', 'accent' => 'from-amber-500/15 to-transparent', 'bar' => null],
                    ['key' => 'stocks_with_prices', 'label' => 'Equity OHLCV', 'hint' => 'With stored daily prices', 'accent' => 'from-indigo-500/10 to-transparent', 'bar' => 'stocks_with_prices'],
                    ['key' => 'etfs_with_prices', 'label' => 'ETF OHLCV', 'hint' => 'With stored daily prices', 'accent' => 'from-violet-500/10 to-transparent', 'bar' => 'etfs_with_prices'],
                    ['key' => 'mf_with_nav', 'label' => 'MF NAV', 'hint' => 'Schemes with NAV history', 'accent' => 'from-amber-500/10 to-transparent', 'bar' => 'mf_with_nav'],
                    ['key' => 'watchlist_count', 'label' => 'Watchlist', 'hint' => 'Tracked symbols', 'accent' => 'from-emerald-500/15 to-transparent', 'bar' => null],
                ];
                foreach ($kpiDefs as $kpi):
                    ?>
                    <article class="dash-kpi p-5 relative overflow-hidden">
                        <div class="absolute inset-0 bg-gradient-to-br <?= e($kpi['accent']) ?> pointer-events-none opacity-60"></div>
                        <div class="relative">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-400"><?= e($kpi['label']) ?></p>
                                <span class="dash-kpi-icon" aria-hidden="true"></span>
                            </div>
                            <p class="mt-3 text-3xl font-semibold text-slate-50 dash-counter" data-kpi="<?= e($kpi['key']) ?>">
                                <span class="dash-skeleton inline-block w-16 h-8"></span>
                            </p>
                            <p class="text-xs text-slate-500 mt-1"><?= e($kpi['hint']) ?></p>
                            <?php if (!empty($kpi['bar'])): ?>
                                <div class="mt-3">
                                    <div class="flex justify-between text-[10px] text-slate-500 mb-1">
                                        <span>Share of universe</span>
                                        <span class="dash-kpi-sub" data-kpi-sub="<?= e($kpi['bar']) ?>">—</span>
                                    </div>
                                    <div class="dash-progress-track">
                                        <div class="dash-progress-fill bg-indigo-400/80" data-kpi-bar="<?= e($kpi['bar']) ?>" style="width:0%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <article class="dash-kpi p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500">Last successful sync</p>
                    <p class="text-sm font-medium text-slate-200 mt-1" id="dash-last-sync-label"><span class="dash-skeleton inline-block w-48 h-4"></span></p>
                    <p class="text-xs text-slate-500 mt-0.5" id="dash-last-sync-time"></p>
                </div>
                <a href="sync-center.php" class="portal-link text-sm shrink-0">Data Pipeline →</a>
            </article>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <!-- Chart -->
                <section class="dash-panel p-5 xl:col-span-2 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-slate-200">Price history</h3>
                            <p class="text-xs text-slate-500" id="dash-chart-subtitle">Select an instrument with stored OHLCV data</p>
                        </div>
                        <select id="dash-instrument-select" class="text-sm rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 max-w-full sm:max-w-xs" disabled>
                            <option value="">Loading…</option>
                        </select>
                    </div>
                    <div class="flex flex-wrap gap-1.5" id="dash-range-btns">
                        <?php foreach ([['7', '1W'], ['30', '1M'], ['90', '3M'], ['180', '6M'], ['365', '1Y']] as $r): ?>
                            <button type="button" class="dash-range-btn text-xs px-2.5 py-1 rounded-md border border-surface-border text-slate-400 hover:text-slate-200 transition" data-days="<?= e($r[0]) ?>"><?= e($r[1]) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p id="dash-chart-meta" class="text-xs text-slate-500 hidden"></p>
                    <p id="dash-chart-empty" class="text-sm text-slate-500">Loading chart…</p>
                    <div class="h-64 md:h-72 relative">
                        <canvas id="dashboard-chart" class="hidden"></canvas>
                        <div id="dash-chart-skeleton" class="absolute inset-0 dash-skeleton rounded-lg opacity-40"></div>
                    </div>
                </section>

                <!-- Distribution -->
                <section class="dash-panel p-5 space-y-4">
                    <h3 class="text-sm font-medium text-slate-200">Asset universe</h3>
                    <p class="text-xs text-slate-500">Stored instrument counts (not verified full-market coverage)</p>
                    <div class="h-52 relative">
                        <canvas id="dashboard-doughnut"></canvas>
                    </div>
                    <ul class="text-xs space-y-2" id="dash-distribution-legend">
                        <li class="dash-skeleton h-4 w-full"></li>
                        <li class="dash-skeleton h-4 w-3/4"></li>
                        <li class="dash-skeleton h-4 w-2/3"></li>
                    </ul>
                </section>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <section class="dash-panel p-5 overflow-x-auto">
                    <h3 class="text-sm font-medium text-slate-200 mb-3">Recent predictions</h3>
                    <div id="dash-predictions-wrap">
                        <table class="dash-table w-full text-sm min-w-[28rem]">
                            <thead><tr>
                                <th class="text-left py-2">Asset</th>
                                <th class="text-right py-2">Score</th>
                                <th class="text-left py-2">Outcome</th>
                            </tr></thead>
                            <tbody id="dash-predictions-body">
                                <tr><td colspan="3" class="py-4"><div class="dash-skeleton h-8 w-full"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <a href="predictions.php" class="inline-block mt-3 text-xs portal-link">Open screener →</a>
                </section>

                <section class="dash-panel p-5 overflow-x-auto">
                    <h3 class="text-sm font-medium text-slate-200 mb-3">Recent sync activity</h3>
                    <table class="dash-table w-full text-sm min-w-[24rem]">
                        <thead><tr>
                            <th class="text-left py-2">Task</th>
                            <th class="text-left py-2">Status</th>
                            <th class="text-right py-2">When</th>
                        </tr></thead>
                        <tbody id="dash-sync-body">
                            <tr><td colspan="3" class="py-4"><div class="dash-skeleton h-8 w-full"></div></td></tr>
                        </tbody>
                    </table>
                    <a href="sync-center.php" class="inline-block mt-3 text-xs portal-link">Data Pipeline →</a>
                </section>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <section class="dash-panel p-5 lg:col-span-1">
                    <h3 class="text-sm font-medium text-slate-200 mb-3">Data coverage gaps</h3>
                    <ul class="text-sm space-y-2 text-slate-300" id="dash-coverage-list">
                        <li class="dash-skeleton h-5 w-full"></li>
                        <li class="dash-skeleton h-5 w-5/6"></li>
                        <li class="dash-skeleton h-5 w-4/6"></li>
                    </ul>
                </section>

                <section class="dash-panel p-5 lg:col-span-1 overflow-x-auto">
                    <h3 class="text-sm font-medium text-slate-200 mb-3">Watchlist</h3>
                    <table class="dash-table w-full text-sm">
                        <thead><tr>
                            <th class="text-left py-2">Symbol</th>
                            <th class="text-right py-2">Last</th>
                        </tr></thead>
                        <tbody id="dash-watchlist-rows">
                            <tr><td colspan="2" class="py-4"><div class="dash-skeleton h-6 w-full"></div></td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="dash-panel p-5 lg:col-span-1 overflow-x-auto">
                    <h3 class="text-sm font-medium text-slate-200 mb-3">Session movers</h3>
                    <p class="text-xs text-slate-500 mb-2">Latest vs previous stored daily close (last 30 days)</p>
                    <div id="dash-movers-content">
                        <div class="dash-skeleton h-24 w-full"></div>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
