<?php

declare(strict_types=1);

$navSections = [
    [
        'title' => 'Workspace',
        'items' => [
            'dashboard' => [
                'label' => 'Dashboard',
                'href' => 'index.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M4 10.5L12 4l8 6.5V20a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1v-9.5z"/></svg>',
            ],
        ],
    ],
    [
        'title' => 'Markets',
        'items' => [
            'stocks' => [
                'label' => 'Equities',
                'href' => 'stocks.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M4 19V5m0 14h16M8 17V9m4 8V7m4 10v-4"/></svg>',
            ],
            'etfs' => [
                'label' => 'ETFs',
                'href' => 'etfs.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M7 7h10v10H7zM4 4h4M16 4h4M4 16h4M16 16h4"/></svg>',
            ],
            'funds' => [
                'label' => 'Mutual Funds',
                'href' => 'mutual-funds.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12 3v18M7 8h10M6 12h12M8 16h8"/></svg>',
            ],
        ],
    ],
    [
        'title' => 'Analytics',
        'items' => [
            'predictions' => [
                'label' => 'Screener',
                'href' => 'predictions.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M4 19h16M6 16l3-5 3 3 5-8 2 3"/></svg>',
            ],
            'backtesting' => [
                'label' => 'Backtests',
                'href' => 'backtesting.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M5 5h14v14H5zM9 9h6v6H9zM3 12h2M19 12h2M12 3v2M12 19v2"/></svg>',
            ],
        ],
    ],
    [
        'title' => 'Platform',
        'items' => [
            'sync' => [
                'label' => 'Download Data',
                'href' => 'sync-center.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6M20 8a8 8 0 00-14.5-2M4 16a8 8 0 0014.5 2"/></svg>',
            ],
            'settings_upstox' => [
                'label' => 'Integrations',
                'href' => 'settings-upstox.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
            ],
            'settings_email' => [
                'label' => 'Notifications',
                'href' => 'settings-email.php',
                'enabled' => true,
                'icon' => '<svg class="portal-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M4 6h16v12H4zM4 7l8 6 8-6"/></svg>',
            ],
        ],
    ],
];
?>
<div id="portal-nav-overlay" class="portal-overlay" aria-hidden="true"></div>
<aside id="portal-sidebar" class="portal-sidebar" aria-label="Main navigation">
    <button type="button" id="portal-nav-close" class="portal-sidebar__close" aria-label="Close navigation menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
    <div class="portal-sidebar__brand">
        <p class="portal-sidebar__brand-title">Research Portal</p>
        <p class="portal-sidebar__brand-sub">Capital markets workspace</p>
    </div>
    <nav class="portal-sidebar__nav">
        <?php foreach ($navSections as $section): ?>
            <p class="portal-nav-section"><?= e($section['title']) ?></p>
            <?php foreach ($section['items'] as $key => $item): ?>
                <?php if ($item['enabled']): ?>
                    <a href="<?= e($item['href']) ?>"
                       class="portal-nav-link<?= $activeNav === $key ? ' is-active' : '' ?>"
                       <?= $activeNav === $key ? 'aria-current="page"' : '' ?>>
                        <?= $item['icon'] ?>
                        <span class="portal-nav-label"><?= e($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>
