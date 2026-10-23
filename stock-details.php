<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/research.php';
require_once __DIR__ . '/includes/portal_ui.php';

require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash_set('error', 'Invalid instrument.');
    redirect('stocks.php');
}

$instrument = null;
$error = null;
$onWatchlist = false;

try {
    auth_release_session_lock();
    $pdo = db();
    $instrument = research_get_instrument($pdo, $id);
    if (!$instrument) {
        flash_set('error', 'Instrument not found.');
        redirect('stocks.php');
    }
    $onWatchlist = research_watchlist_has($pdo, $id);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Instrument details';
$activeNav = 'stocks';
$backLink = 'stocks.php';
$loadChartJs = true;
$loadStocksJs = true;

if ($instrument) {
    $backLink = ($instrument['instrument_type'] ?? '') === 'etf' ? 'etfs.php' : 'stocks.php';
    $displaySymbol = instrument_display_symbol($instrument);
    $pageTitle = $displaySymbol . ' details';
    $activeNav = ($instrument['instrument_type'] ?? '') === 'etf' ? 'etfs' : 'stocks';
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained pro-list-page"
          id="instrument-detail"
          data-instrument-id="<?= (int) $id ?>"
          data-csrf="<?= e(csrf_token()) ?>">
        <?php if ($error): ?>
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"><?= e($error) ?></div>
        <?php else: ?>
            <div class="inst-detail-toolbar">
                <a href="<?= e($backLink) ?>" class="portal-link text-sm">← Back to list</a>
                <div class="inst-detail-toolbar__actions">
                    <button type="button" id="watchlist-toggle" class="portal-btn portal-btn--ghost portal-btn--sm"
                            data-on="<?= $onWatchlist ? '1' : '0' ?>">
                        <?= $onWatchlist ? 'Remove from watchlist' : 'Add to watchlist' ?>
                    </button>
                    <button type="button" id="stock-sync-recent" class="portal-btn portal-btn--primary portal-btn--sm"
                            data-mode="recent">Refresh prices</button>
                    <button type="button" id="stock-sync-full" class="portal-btn portal-btn--ghost portal-btn--sm"
                            data-mode="full">Download full history</button>
                </div>
                <p id="stock-sync-toast" class="inst-detail-toast hidden" role="status"></p>
                <p id="stock-sync-progress" class="inst-detail-progress hidden" aria-live="polite"></p>
            </div>
            <?php
            $upstoxKey = trim((string) ($instrument['provider_instrument_key'] ?? ''));
            if ($upstoxKey === ''): ?>
                <p class="text-sm text-amber-200/90 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2">
                    This instrument is missing a broker feed key. Import the equity master from Data Pipeline or map a valid listing.
                </p>
            <?php endif; ?>

            <section class="portal-card portal-card--elevated">
                <h2 class="text-lg font-medium"><?= e($instrument['name']) ?></h2>
                <p class="text-sm text-slate-400 mt-1">
                    <?= e(instrument_display_symbol($instrument)) ?> · <?= e((string) ($instrument['exchange'] ?? '')) ?>
                    · <?= e($instrument['instrument_type']) ?>
                </p>
                <div id="price-headline" class="inst-price-headline mt-4">
                    <p class="text-slate-500 text-sm">Loading latest price…</p>
                </div>
                <p id="price-meta" class="text-sm text-slate-500 mt-2">Loading price metadata…</p>
                <p id="stale-warning" class="hidden text-sm text-amber-300 mt-2"></p>
            </section>

            <section class="portal-card portal-card--elevated" id="insights-panel" aria-labelledby="insights-title">
                <h3 id="insights-title" class="text-sm font-medium text-slate-200 mb-1">Research snapshot</h3>
                <p class="text-xs text-slate-500 mb-4">52-week range, returns, and rule-based tilt from stored prices — not financial advice.</p>
                <div id="insights-grid" class="inst-insights-grid">
                    <p class="text-slate-500 text-sm">Loading…</p>
                </div>
            </section>

            <section class="portal-card portal-card--elevated">
                <h3 class="text-sm font-medium text-slate-200 mb-3">Daily OHLCV (close)</h3>
                <div class="h-72 md:h-96">
                    <canvas id="price-chart"></canvas>
                </div>
                <p id="chart-empty" class="hidden text-sm text-slate-500 mt-2">No historical prices stored for this instrument.</p>
            </section>

            <section class="portal-card portal-card--elevated">
                <h3 class="text-sm font-medium text-slate-200 mb-3">Technical indicators (from stored history)</h3>
                <div id="indicators-grid" class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <p class="text-slate-500 col-span-full">Loading…</p>
                </div>
                <p class="text-xs text-slate-500 mt-3">Indicators are research estimates, not trading signals or guaranteed outcomes.</p>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
