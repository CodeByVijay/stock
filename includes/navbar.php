<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var list<array{label:string,href:?string}>|null $navBreadcrumb */
$navBreadcrumb = $navBreadcrumb ?? null;
?>
<header class="portal-header">
    <div class="portal-header__start">
        <button type="button"
                id="portal-nav-open"
                class="portal-header__menu-btn"
                aria-label="Toggle navigation menu"
                aria-expanded="true"
                aria-controls="portal-sidebar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
        </button>
        <?php if ($navBreadcrumb !== null && $navBreadcrumb !== []): ?>
            <nav class="portal-breadcrumb" aria-label="Breadcrumb">
                <?php foreach ($navBreadcrumb as $i => $crumb): ?>
                    <?php if ($i > 0): ?>
                        <span class="portal-breadcrumb__sep" aria-hidden="true">/</span>
                    <?php endif; ?>
                    <?php if (!empty($crumb['href'])): ?>
                        <a href="<?= e($crumb['href']) ?>"><?= e($crumb['label']) ?></a>
                    <?php else: ?>
                        <span class="portal-breadcrumb__current"><?= e($crumb['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php else: ?>
            <h1 class="portal-header__title"><?= e($pageTitle) ?></h1>
        <?php endif; ?>
    </div>
    <div class="portal-header__actions">
        <span class="portal-header__user"><?= e(current_admin_display_name()) ?></span>
        <a href="profile.php" class="portal-btn portal-btn--ghost hidden sm:inline-flex">Account</a>
        <a href="logout.php" class="portal-btn portal-btn--ghost" title="Sign out of the portal">Sign out</a>
    </div>
</header>
