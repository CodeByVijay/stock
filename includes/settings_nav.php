<?php

declare(strict_types=1);

/** @var string $settingsNav */
$settingsNav = $settingsNav ?? 'email';
?>
<nav class="settings-tabs" aria-label="Settings">
    <a href="settings-upstox.php" class="<?= $settingsNav === 'upstox' ? 'is-active' : '' ?>">Integrations</a>
    <a href="settings-email.php" class="<?= $settingsNav === 'email' ? 'is-active' : '' ?>">Notifications</a>
</nav>
