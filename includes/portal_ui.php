<?php

declare(strict_types=1);

/**
 * @return array{eyebrow:string,title:string,subtitle:string}
 */
function portal_page_copy(string $pageKey): array
{
    $pages = [
        'dashboard' => [
            'eyebrow' => 'Dashboard',
            'title' => 'Portfolio intelligence',
            'subtitle' => 'Unified view of instrument coverage, sync health, and research activity.',
        ],
        'stocks' => [
            'eyebrow' => 'Markets',
            'title' => 'Equities',
            'subtitle' => 'NSE and BSE listings with pricing coverage and drill-down analytics.',
        ],
        'etfs' => [
            'eyebrow' => 'Markets',
            'title' => 'ETFs',
            'subtitle' => 'Exchange-traded fund universe with historical price coverage.',
        ],
        'funds' => [
            'eyebrow' => 'Markets',
            'title' => 'Mutual Funds',
            'subtitle' => 'Scheme directory with NAV coverage and performance detail.',
        ],
        'sync' => [
            'eyebrow' => 'Download data',
            'title' => 'Update your market data',
            'subtitle' => 'Press a button below, wait until status shows Done, then open Markets in the menu to view shares and funds.',
        ],
        'predictions' => [
            'eyebrow' => 'Analytics',
            'title' => 'Screener',
            'subtitle' => 'Pick a goal (for example 8% in 30 days). We rank names from your data — research only, not advice.',
        ],
        'backtesting' => [
            'eyebrow' => 'Analytics',
            'title' => 'Backtests',
            'subtitle' => 'Historical walk-forward evaluation of the screener ruleset.',
        ],
        'settings_upstox' => [
            'eyebrow' => 'Platform',
            'title' => 'Integrations',
            'subtitle' => 'Broker connectivity for automated equity and ETF market data.',
        ],
        'settings_email' => [
            'eyebrow' => 'Platform',
            'title' => 'Notifications',
            'subtitle' => 'SMTP delivery for account security and system messages.',
        ],
    ];

    return $pages[$pageKey] ?? [
        'eyebrow' => '',
        'title' => 'Portal',
        'subtitle' => '',
    ];
}

function portal_icon_svg(string $name): string
{
    $icons = [
        'sync' => '<path d="M4 4v6h6M20 20v-6h-6M20 8a8 8 0 00-14.5-2M4 16a8 8 0 0014.5 2"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/>',
        'import' => '<path d="M12 3v12M8 11l4 4 4-4M5 21h14"/>',
        'archive' => '<path d="M4 7h16v12H4zM4 7l2-4h12l2 4M10 11h4"/>',
        'chart' => '<path d="M4 19V5m0 14h16M8 17V9m4 8V7m4 10v-4"/>',
        'fund' => '<path d="M12 3v18M7 8h10M6 12h12"/>',
    ];
    $path = $icons[$name] ?? $icons['sync'];

    return '<svg class="portal-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">' . $path . '</svg>';
}

/** Compact title row for data-heavy pages. */
function portal_render_page_header(string $title, string $subtitle = ''): void
{
    ?>
    <header class="pro-page-header portal-animate-in">
        <h2 class="pro-page-header__title"><?= e($title) ?></h2>
        <?php if ($subtitle !== ''): ?>
            <p class="pro-page-header__sub"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </header>
    <?php
}

/** Coverage summary above data tables (client-friendly, no pipeline jargon). */
function portal_render_coverage_strip(int $covered, int $total, string $metricName, string $actionHref = 'sync-center.php'): void
{
    if ($total <= 0 || $covered >= $total) {
        return;
    }
    $pct = round(($covered / $total) * 100, 1);
    ?>
    <aside class="pro-coverage-strip portal-animate-in" role="status">
        <div class="pro-coverage-strip__main">
            <p class="pro-coverage-strip__title"><?= e($metricName) ?> coverage</p>
            <p class="pro-coverage-strip__value">
                <span class="pro-coverage-strip__num"><?= (int) $covered ?></span>
                <span class="pro-coverage-strip__of">of <?= (int) $total ?></span>
                <span class="pro-coverage-strip__pct">(<?= e((string) $pct) ?>%)</span>
            </p>
            <div class="pro-coverage-strip__bar" aria-hidden="true">
                <span style="width:<?= min(100, max(0, (float) $pct)) ?>%"></span>
            </div>
        </div>
        <a href="<?= e($actionHref) ?>" class="portal-btn portal-btn--ghost pro-coverage-strip__action">Improve coverage</a>
    </aside>
    <?php
}

/** @param list<array{label:string,value:string}> $chips */
function portal_render_data_table_head(string $title, array $chips = [], string $hint = ''): void
{
    ?>
    <div class="pro-data-table__head">
        <div class="pro-data-table__head-left">
            <h3 class="pro-data-table__title"><?= e($title) ?></h3>
            <?php if ($chips !== []): ?>
                <div class="pro-stat-chips">
                    <?php foreach ($chips as $chip): ?>
                        <span class="pro-stat-chip">
                            <span class="pro-stat-chip__label"><?= e($chip['label']) ?></span>
                            <span class="pro-stat-chip__value"><?= e($chip['value']) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($hint !== ''): ?>
            <p class="pro-data-table__hint"><?= e($hint) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/** @param list<array{href:string,label:string,primary?:bool}>|null $actions */
function portal_render_page_hero(string $eyebrow, string $title, string $subtitle, ?array $actions = null): void
{
    ?>
    <header class="pro-hero portal-animate-in">
        <div class="pro-hero__content">
            <?php if ($eyebrow !== ''): ?>
                <span class="pro-hero__pill"><?= e($eyebrow) ?></span>
            <?php endif; ?>
            <h2 class="pro-hero__title"><?= e($title) ?></h2>
            <?php if ($subtitle !== ''): ?>
                <p class="pro-hero__lead"><?= e($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actions !== null && $actions !== []): ?>
            <div class="pro-hero__actions">
                <?php foreach ($actions as $action): ?>
                    <a href="<?= e($action['href']) ?>"
                       class="portal-btn <?= !empty($action['primary']) ? 'portal-btn--primary' : 'portal-btn--ghost' ?>">
                        <?= e($action['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </header>
    <?php
}

function portal_render_section_head(string $title, string $subtitle = ''): void
{
    ?>
    <div class="pro-section-head">
        <h3 class="pro-section-head__title"><?= e($title) ?></h3>
        <?php if ($subtitle !== ''): ?>
            <p class="pro-section-head__sub"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function portal_render_empty_state(string $title, string $body, ?string $href = null, ?string $buttonLabel = null): void
{
    ?>
    <div class="portal-empty portal-animate-in">
        <div class="portal-empty__icon" aria-hidden="true"><?= portal_icon_svg('chart') ?></div>
        <p class="portal-empty__title"><?= e($title) ?></p>
        <p class="portal-empty__body"><?= e($body) ?></p>
        <?php if ($href !== null && $buttonLabel !== null): ?>
            <a href="<?= e($href) ?>" class="portal-btn portal-btn--primary"><?= e($buttonLabel) ?></a>
        <?php endif; ?>
    </div>
    <?php
}

/** @param array{page:int,total_pages:int,search:string,extra_query:array<string,string>} $pager */
function portal_render_pagination(array $pager): void
{
    if ($pager['total_pages'] <= 1) {
        return;
    }
    $page = $pager['page'];
    $q = $pager['search'];
    $extra = $pager['extra_query'] ?? [];
    $build = static function (int $p) use ($q, $extra): string {
        $params = array_merge(['q' => $q, 'page' => (string) $p], $extra);

        return '?' . http_build_query($params);
    };
    ?>
    <nav class="portal-pagination" aria-label="Pagination">
        <?php if ($page > 1): ?>
            <a class="portal-pagination__btn" href="<?= e($build($page - 1)) ?>">Previous</a>
        <?php else: ?>
            <span class="portal-pagination__btn is-disabled">Previous</span>
        <?php endif; ?>
        <span class="portal-pagination__info">Page <?= (int) $page ?> of <?= (int) $pager['total_pages'] ?></span>
        <?php if ($page < $pager['total_pages']): ?>
            <a class="portal-pagination__btn" href="<?= e($build($page + 1)) ?>">Next</a>
        <?php else: ?>
            <span class="portal-pagination__btn is-disabled">Next</span>
        <?php endif; ?>
    </nav>
    <?php
}

/**
 * @param array<string,mixed> $card
 */
function portal_render_pipeline_quickstart(): void
{
    ?>
    <section class="pro-quickstart portal-animate-in" aria-labelledby="pipeline-quickstart-title">
        <h3 id="pipeline-quickstart-title" class="pro-quickstart__title">Start here — 3 simple steps</h3>
        <ol class="pro-quickstart__steps">
            <li>
                <span class="pro-quickstart__num">1</span>
                <div>
                    <strong>Pick what you need</strong>
                    <p>Shares, ETFs, or Mutual funds — press <em>Start update</em> on that card.</p>
                </div>
            </li>
            <li>
                <span class="pro-quickstart__num">2</span>
                <div>
                    <strong>Wait on this page</strong>
                    <p>Watch <em>Status below</em>. When it says <strong>Done</strong>, you can continue.</p>
                </div>
            </li>
            <li>
                <span class="pro-quickstart__num">3</span>
                <div>
                    <strong>View your data</strong>
                    <p>Open <em>Equities</em>, <em>ETFs</em>, or <em>Mutual Funds</em> from the left menu.</p>
                </div>
            </li>
        </ol>
        <p class="pro-quickstart__tip">First visit? Run <strong>One-time setup</strong> once, then use <strong>Regular update</strong> every day.</p>
    </section>
    <?php
}

/**
 * @param list<array<string,mixed>> $actions
 */
function portal_render_setup_buttons(array $actions): void
{
    ?>
    <section class="pro-setup-row" aria-labelledby="pipeline-setup-title">
        <h3 id="pipeline-setup-title" class="pro-section-head__title">One-time setup <span class="pro-setup-row__once">(once)</span></h3>
        <p class="pro-section-head__sub">Downloads names and codes so lists are not empty. Safe to run again if needed.</p>
        <div class="pro-setup-row__grid">
            <?php foreach ($actions as $action): ?>
                <button type="button"
                        class="sync-start-btn pro-setup-btn mdc-action-btn"
                        data-job-type="<?= e((string) $action['type']) ?>"
                        data-requires="<?= e((string) ($action['requires'] ?? '')) ?>"
                        <?= !empty($action['disabled']) ? 'disabled' : '' ?>>
                    <span class="pro-setup-btn__title"><?= e((string) $action['label']) ?></span>
                    <span class="pro-setup-btn__note"><?= e((string) ($action['help'] ?? '')) ?></span>
                    <?php if (!empty($action['disabled']) && !empty($action['disabled_reason'])): ?>
                        <span class="pro-setup-btn__warn"><?= e((string) $action['disabled_reason']) ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/**
 * @param array<string,mixed> $card
 */
function portal_render_mdc_action_card(array $card, bool $isActive): void
{
    $tone = (string) ($card['tone'] ?? 'sky');
    $stored = (int) ($card['stored'] ?? 0);
    $with = (int) ($card['with_data'] ?? 0);
    $pct = $stored > 0 ? (int) round(min(100, ($with / $stored) * 100)) : 0;
    $title = (string) ($card['easy_title'] ?? $card['title'] ?? '');
    $desc = (string) ($card['easy_desc'] ?? $card['desc'] ?? '');
    $btn = (string) ($card['btn_label'] ?? 'Start update');
    $storedLabel = (string) ($card['stored_label'] ?? 'In your list');
    $withLabel = (string) ($card['with_label'] ?? 'With prices');
    $lastLabel = (string) ($card['last_label'] ?? 'Last updated');
    $lastDisplay = (string) ($card['last'] ?? '—');
    if ($lastDisplay === 'Never') {
        $lastDisplay = 'Not yet';
    }
    ?>
    <article class="mdc-card mdc-card--easy mdc-card--<?= e($tone) ?><?= $isActive ? ' mdc-card--busy' : '' ?>">
        <header class="mdc-card__head">
            <div class="mdc-card__icon"><?= portal_icon_svg((string) ($card['icon'] ?? 'chart')) ?></div>
            <div>
                <h3 class="mdc-card__title"><?= e($title) ?></h3>
                <p class="mdc-card__desc"><?= e($desc) ?></p>
            </div>
        </header>
        <div class="mdc-card__metrics">
            <div class="mdc-metric">
                <span class="mdc-metric__label"><?= e($storedLabel) ?></span>
                <span class="mdc-metric__value"><?= $stored ?></span>
            </div>
            <div class="mdc-metric">
                <span class="mdc-metric__label"><?= e($withLabel) ?></span>
                <span class="mdc-metric__value"><?= $with ?></span>
            </div>
            <div class="mdc-metric mdc-metric--wide">
                <span class="mdc-metric__label"><?= e($lastLabel) ?></span>
                <span class="mdc-metric__value mdc-metric__value--text"><?= e($lastDisplay) ?></span>
            </div>
        </div>
        <div class="mdc-card__coverage">
            <div class="mdc-card__coverage-row"><span>Data ready</span><span><?= $pct ?>%</span></div>
            <div class="mdc-card__bar"><span style="width:<?= $pct ?>%"></span></div>
        </div>
        <button type="button"
                class="mdc-primary-sync sync-start-btn mdc-card__cta portal-btn portal-btn--primary"
                data-job-type="<?= e((string) $card['job_type']) ?>"
                data-category="<?= e((string) $card['category']) ?>"
                data-idle-label="<?= e($btn) ?>"
                <?= $isActive ? 'disabled aria-busy="true"' : '' ?>>
            <span class="mdc-btn-label"><?= $isActive ? 'Please wait…' : e($btn) ?></span>
        </button>
    </article>
    <?php
}
