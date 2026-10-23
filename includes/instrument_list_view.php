<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $activeNav */
/** @var string $instrumentType */
/** @var string $detailsPage */

require_once __DIR__ . '/research.php';
require_once __DIR__ . '/portal_ui.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$rows = [];
$total = 0;
$pricedCount = 0;
$error = null;

try {
    auth_release_session_lock();
    $pdo = db();
    $rows = research_list_instruments($pdo, $instrumentType, $search, $perPage, $offset);
    $total = research_count_instruments($pdo, $instrumentType, $search);
    $pricedCount = research_count_instruments_with_prices($pdo, $instrumentType);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totalPages = max(1, (int) ceil($total / $perPage));
$copyKey = $instrumentType === 'etf' ? 'etfs' : 'stocks';
$pageCopy = portal_page_copy($copyKey);
$listLabel = $instrumentType === 'etf' ? 'ETFs' : 'stocks';
$directoryTitle = $instrumentType === 'etf' ? 'ETF directory' : 'Equity directory';
$priceMetricLabel = 'Price';

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>
<div class="portal-main-column">
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="portal-main portal-main--constrained pro-list-page">
        <?php
        portal_render_page_header($pageCopy['title'], $pageCopy['subtitle']);
        ?>

        <?php if ($error): ?>
            <div class="portal-alert portal-alert--warn" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php portal_render_coverage_strip($pricedCount, $total, $priceMetricLabel); ?>

        <form method="get" class="pro-filter-card portal-animate-in portal-animate-in--delay">
            <div class="min-w-0">
                <label for="inst-search">Search</label>
                <input id="inst-search" type="search" name="q" value="<?= e($search) ?>" placeholder="Symbol or company name"
                       class="portal-form-input">
            </div>
            <button type="submit" class="portal-btn portal-btn--primary">Apply</button>
        </form>

        <div class="pro-data-table portal-animate-in portal-animate-in--delay">
            <?php
            portal_render_data_table_head(
                $directoryTitle,
                [
                    ['label' => 'Total', 'value' => (string) (int) $total],
                    ['label' => 'With prices', 'value' => (string) (int) $pricedCount],
                ],
                'Open a name to view charts and history'
            );
            ?>
            <div class="portal-table-wrap">
            <table class="portal-table">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Company name</th>
                        <th>Exchange</th>
                        <th class="portal-table__num">Last price</th>
                        <th>Price date</th>
                        <th style="text-align:center">Status</th>
                        <th style="text-align:center">Watchlist</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7">
                            <?php
                            portal_render_empty_state(
                                'No ' . strtolower($listLabel) . ' yet',
                                'Download share lists and run an update from Download Data in the menu.',
                                'sync-center.php',
                                'Open Download Data'
                            );
                            ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $stale = research_stale_message($row['last_fetched_at'] ?? null, $row['last_trade_date'] ?? null, false);
                        $hasPrice = $row['last_close'] !== null;
                        $displaySymbol = instrument_display_symbol($row);
                        $rawName = trim((string) ($row['name'] ?? ''));
                        $displayName = ($rawName !== '' && strcasecmp($rawName, $displaySymbol) !== 0)
                            ? $rawName
                            : ($rawName !== '' ? $rawName : '—');
                        ?>
                        <tr>
                            <td class="pro-table-symbol">
                                <a class="pro-table-link" href="<?= e($detailsPage) ?>?id=<?= (int) $row['id'] ?>">
                                    <?= e($displaySymbol) ?>
                                </a>
                            </td>
                            <td class="portal-table__name" title="<?= e($displayName) ?>">
                                <a class="pro-table-link" href="<?= e($detailsPage) ?>?id=<?= (int) $row['id'] ?>"><?= e($displayName) ?></a>
                            </td>
                            <td><?= e((string) ($row['exchange'] ?? '—')) ?></td>
                            <td class="portal-table__num font-mono">
                                <?= $hasPrice ? e(number_format((float) $row['last_close'], 2)) : '—' ?>
                            </td>
                            <td class="text-sm"><?= $hasPrice ? e((string) ($row['last_trade_date'] ?? '—')) : '—' ?></td>
                            <td style="text-align:center">
                                <?php if (!$hasPrice): ?>
                                    <span class="portal-badge portal-badge--muted">Pending</span>
                                <?php elseif ($stale): ?>
                                    <span class="portal-badge portal-badge--warn" title="<?= e($stale) ?>">Stale</span>
                                <?php else: ?>
                                    <span class="portal-badge portal-badge--ok">Synced</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center"><?= !empty($row['on_watchlist']) ? '★' : '—' ?></td>
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
            'extra_query' => [],
        ]);
        ?>
    </main>
</div>
<?php include __DIR__ . '/footer.php'; ?>
