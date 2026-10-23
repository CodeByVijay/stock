<?php

declare(strict_types=1);

function portal_auth_head(string $pageTitle): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(page_title($pageTitle)) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/portal.css?v=20260323e">
    <link rel="stylesheet" href="assets/css/pro-shell.css?v=20260323e">
</head>
<body class="portal-auth-shell">
    <?php
}

function portal_auth_card_open(string $title, string $lead = ''): void
{
    ?>
<div class="portal-auth-wrap portal-animate-in">
    <div class="portal-auth-card">
        <p class="portal-auth-eyebrow">Research portal</p>
        <h1><?= e($title) ?></h1>
        <?php if ($lead !== ''): ?>
            <p class="portal-auth-lead"><?= e($lead) ?></p>
        <?php endif; ?>
    <?php
}

function portal_auth_card_close(): void
{
    ?>
    </div>
</div>
</body>
</html>
    <?php
}
