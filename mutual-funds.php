<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/research.php';
require_once __DIR__ . '/includes/portal_ui.php';
require_once PRIVATE_ROOT . '/services/mf_returns.php';
require_once PRIVATE_ROOT . '/services/mf_scheme_meta.php';

require_login();

$search = trim((string) ($_GET['q'] ?? ''));
$navFilter = research_normalize_scheme_nav_filter((string) ($_GET['nav'] ?? 'all'));
$schemeId = isset($_GET['scheme_id']) ? (int) $_GET['scheme_id'] : 0;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$scheme = null;
$rows = [];
$total = 0;
$navSchemeCount = 0;
$error = null;
/** @var array{latest:?array<string,mixed>,periods:list<array<string,mixed>>} */
$performance = ['latest' => null, 'periods' => []];
/** @var array<string,mixed>|null */
$schemeCoverage = null;

try {
    auth_release_session_lock();
    $pdo = db();
    if ($schemeId > 0) {
        $scheme = research_get_scheme($pdo, $schemeId);
        if (!$scheme) {
            flash_set('error', 'Scheme not found.');
            redirect('mutual-funds.php');
        }
        $performance = mf_scheme_performance_returns($pdo, $schemeId);
        $schemeCoverage = research_get_scheme_nav_coverage($pdo, $schemeId);
    } else {
        $rows = research_list_schemes($pdo, $search, $perPage, $offset, $navFilter);
        $total = research_count_schemes($pdo, $search, $navFilter);
        $navSchemeCount = (int) $pdo->query(
            'SELECT COUNT(DISTINCT scheme_id) FROM mutual_fund_nav'
        )->fetchColumn();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totalPages = max(1, (int) ceil($total / $perPage));
$pageTitle = $scheme ? 'Fund Detail' : 'Mutual Funds';
$navBreadcrumb = $scheme
    ? [
        ['label' => 'Mutual funds', 'href' => 'mutual-funds.php'],
        ['label' => 'Fund details', 'href' => null],
    ]
    : null;
$activeNav = 'funds';
$loadChartJs = $scheme !== null;
$loadStocksJs = $scheme !== null;
$loadMutualFundsJs = true;

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained pro-list-page">
        <?php if ($scheme): ?>
            <?php if ($error): ?>
                <div class="portal-alert portal-alert--warn" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <?php
            $lat = $performance['latest'] ?? null;
            $schemeName = mf_scheme_display_value($scheme['scheme_name'] ?? null, 'Not available');
            $fundHouse = mf_scheme_display_value($scheme['fund_house'] ?? null);
            $schemeCode = mf_scheme_display_value($scheme['scheme_code'] ?? null);
            $category = mf_scheme_display_value($scheme['scheme_category'] ?? null);
            ?>
            <div class="mf-detail-hero">
                <nav class="mf-detail-hero__breadcrumb portal-breadcrumb" aria-label="Breadcrumb">
                    <a href="mutual-funds.php">Mutual funds</a>
                    <span class="portal-breadcrumb__sep" aria-hidden="true">/</span>
                    <span class="portal-breadcrumb__current">Fund details</span>
                </nav>
                <div class="mf-detail-hero__grid">
                    <div>
                        <h2 class="mf-detail-name"><?= e($schemeName) ?></h2>
                        <div class="mf-detail-meta">
                            <span>Fund house: <strong class="text-slate-300 font-normal"><?= e($fundHouse) ?></strong></span>
                            <span>Code: <strong class="text-slate-300 font-normal"><?= e($schemeCode) ?></strong></span>
                            <span>Category: <strong class="text-slate-300 font-normal"><?= e($category) ?></strong></span>
                        </div>
                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <button type="button" id="mf-detail-sync" class="portal-btn portal-btn--primary"
                                    data-scheme-id="<?= (int) $scheme['id'] ?>">Full NAV backfill</button>
                            <p id="mf-sync-toast" class="hidden w-full sm:w-auto text-sm rounded-lg px-3 py-2 border" role="status"></p>
                        </div>
                    </div>
                    <aside class="mf-nav-summary" aria-label="Latest NAV">
                        <p class="mf-nav-summary__label">Latest NAV</p>
                        <?php if ($lat): ?>
                            <p class="mf-nav-summary__value">₹<?= e(number_format((float) $lat['nav'], 4)) ?></p>
                            <p class="mf-nav-summary__date">As of <?= e($lat['nav_date']) ?></p>
                            <div class="mf-freshness">
                                Source: <?= e($lat['source'] ?? 'Not available') ?><br>
                                Last fetched: <?= e($lat['fetched_at'] ?? 'Not available') ?>
                                <?php if ($schemeCoverage && !empty($schemeCoverage['nav_count'])): ?>
                                    <br>Stored NAV points: <?= (int) $schemeCoverage['nav_count'] ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="mf-nav-summary__value return-pct--na text-xl">Not available</p>
                            <p class="mf-nav-summary__date">Sync NAV history to load pricing.</p>
                        <?php endif; ?>
                    </aside>
                </div>
            </div>

            <section class="portal-card">
                <h3 class="portal-card__title">Scheme information</h3>
                <p class="portal-card__subtitle">Fields from your database and MFapi metadata where available.</p>
                <dl class="mf-scheme-dl mt-4">
                    <?php foreach (mf_scheme_info_rows($scheme, $schemeCoverage) as $infoRow): ?>
                        <div class="mf-scheme-dl__row">
                            <dt><?= e($infoRow['label']) ?></dt>
                            <dd><?= e($infoRow['value']) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>

            <?php if ($performance['periods'] !== []): ?>
                <section aria-label="Performance returns">
                    <h3 class="text-sm font-semibold text-slate-200 mb-3">Returns</h3>
                    <div class="mf-returns-grid" id="mf-return-cards">
                        <?php foreach ($performance['periods'] as $period): ?>
                            <?php
                            $avail = !empty($period['available']) && $period['percent'] !== null;
                            $pctClass = mf_return_css_class($period['percent'] ?? null, $avail);
                            $isLifetime = ($period['key'] ?? '') === 'lifetime';
                            ?>
                            <article class="mf-return-card animate-fade-in">
                                <p class="mf-return-card__label"><?= e($period['label']) ?></p>
                                <p class="mf-return-card__pct <?= e($pctClass) ?>">
                                    <?= e(mf_format_return_percent($avail ? (float) $period['percent'] : null)) ?>
                                </p>
                                <p class="mf-return-card__basis">
                                    <?php if ($avail): ?>
                                        <?php if ($isLifetime): ?>
                                            Return since earliest available NAV · <?= e((string) $period['start_date']) ?> (₹<?= e(number_format((float) $period['start_nav'], 4)) ?>)
                                        <?php else: ?>
                                            From <?= e((string) $period['start_date']) ?> · ₹<?= e(number_format((float) $period['start_nav'], 4)) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?= e((string) ($period['note'] ?? 'Insufficient history')) ?>
                                    <?php endif; ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="mf-summary-panel">
                <p class="mf-summary-panel__title">Performance summary</p>
                <p>Cumulative simple returns (not annualized). Non-trading days use the most recent prior published NAV.</p>
                <?php if ($lat): ?>
                    <p id="fund-meta">
                        Latest NAV ₹<?= e(number_format((float) $lat['nav'], 4)) ?> on <?= e($lat['nav_date']) ?>
                        · Source: <?= e($lat['source'] ?? '—') ?>
                        · Fetched: <?= e($lat['fetched_at'] ?? '—') ?>
                    </p>
                <?php else: ?>
                    <p id="fund-meta">No NAV data stored for this scheme yet.</p>
                <?php endif; ?>
                <p id="fund-stale" class="hidden text-amber-300/90"></p>
                <p class="text-slate-500">NAV is end-of-day data, not an intraday stock price.</p>
            </section>

            <span id="mutual-funds-list" class="hidden" data-csrf="<?= e(csrf_token()) ?>"></span>

            <section class="portal-card portal-card--elevated" id="fund-detail" data-scheme-id="<?= (int) $scheme['id'] ?>">
                <div class="portal-card__header">
                    <div>
                        <h3 class="portal-card__title">NAV history</h3>
                        <p class="portal-card__subtitle">Trailing twelve months from stored history (full sync loads complete series in database).</p>
                    </div>
                </div>
                <div class="mf-chart-wrap">
                    <canvas id="nav-chart" aria-label="NAV history chart"></canvas>
                </div>
                <p id="fund-chart-empty" class="hidden text-sm text-slate-400 mt-4">No NAV series on file. Run full NAV backfill or use Data Pipeline.</p>
            </section>
        <?php else: ?>
            <?php
            $mfCopy = portal_page_copy('funds');
            portal_render_page_header($mfCopy['title'], $mfCopy['subtitle']);
            ?>
            <?php if ($error): ?>
                <div class="portal-alert portal-alert--warn" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <?php portal_render_coverage_strip($navSchemeCount, $total, 'NAV'); ?>
            <form method="get" class="pro-filter-card pro-filter-card--with-select portal-animate-in portal-animate-in--delay">
                <div class="min-w-0">
                    <label for="mf-search">Search</label>
                    <input id="mf-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Fund name, code, or AMC"
                           class="portal-form-input">
                </div>
                <div>
                    <label for="mf-nav-filter">NAV status</label>
                    <select id="mf-nav-filter" name="nav" class="portal-form-input">
                        <option value="all" <?= $navFilter === 'all' ? 'selected' : '' ?>>All schemes</option>
                        <option value="with" <?= $navFilter === 'with' ? 'selected' : '' ?>>With NAV</option>
                        <option value="without" <?= $navFilter === 'without' ? 'selected' : '' ?>>Without NAV</option>
                    </select>
                </div>
                <button type="submit" class="portal-btn portal-btn--primary">Apply</button>
            </form>
            <p id="mf-sync-toast" class="hidden pro-toast" role="status"></p>
            <div id="mutual-funds-list" data-csrf="<?= e(csrf_token()) ?>" class="pro-data-table portal-animate-in portal-animate-in--delay">
                <?php
                portal_render_data_table_head(
                    'Fund directory',
                    [
                        ['label' => 'Total', 'value' => (string) (int) $total],
                        ['label' => 'With NAV', 'value' => (string) (int) $navSchemeCount],
                    ],
                    'Open a name to view returns and history'
                );
                ?>
                <div class="portal-table-wrap">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Fund name</th>
                            <th class="portal-table__num">Latest NAV</th>
                            <th>NAV date</th>
                            <th style="text-align:center">Status</th>
                            <th class="portal-table__actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="6">
                                <?php
                                portal_render_empty_state(
                                    'No mutual funds found',
                                    'Adjust filters or download fund names from Download Data in the menu.',
                                    'sync-center.php',
                                    'Open Download Data'
                                );
                                ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="pro-table-symbol">
                                    <a class="pro-table-link font-mono" href="mutual-funds.php?scheme_id=<?= (int) $row['id'] ?>">
                                        <?= e(mf_scheme_display_value($row['scheme_code'] ?? null, '—')) ?>
                                    </a>
                                </td>
                                <td class="portal-table__name">
                                    <a class="pro-table-link" href="mutual-funds.php?scheme_id=<?= (int) $row['id'] ?>">
                                        <?= e($row['scheme_name']) ?>
                                    </a>
                                </td>
                                <td class="portal-table__num font-mono mf-last-nav">
                                    <?= $row['last_nav'] !== null ? e(number_format((float) $row['last_nav'], 4)) : '—' ?>
                                </td>
                                <td class="text-sm mf-last-nav-date"><?= e((string) ($row['last_nav_date'] ?? '—')) ?></td>
                                <td style="text-align:center">
                                    <span class="portal-badge mf-nav-badge <?= !empty($row['has_nav']) ? 'portal-badge--ok' : 'portal-badge--muted' ?>">
                                        <?= !empty($row['has_nav']) ? 'Synced' : 'Pending' ?>
                                    </span>
                                </td>
                                <td class="portal-table__actions">
                                    <button type="button" class="mf-sync-btn portal-btn portal-btn--ghost portal-btn--sm"
                                            data-scheme-id="<?= (int) $row['id'] ?>" data-mode="recent">Update</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php
            portal_render_pagination([
                'page' => $page,
                'total_pages' => $totalPages,
                'search' => $search,
                'extra_query' => ['nav' => $navFilter],
            ]);
            ?>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
