<?php

declare(strict_types=1);

require_once __DIR__ . '/portal_ui.php';

/** @var string $pageTitle */
/** @var string $activeNav */
$activeNav = $activeNav ?? 'dashboard';
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        surface: {
                            DEFAULT: '#070b14',
                            card: '#121a2b',
                            border: '#2a3548',
                            elevated: '#1a2438'
                        }
                    }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/portal.css?v=20260323b">
    <link rel="stylesheet" href="assets/css/pro-shell.css?v=20260323b">
    <link rel="stylesheet" href="assets/css/pro-list.css?v=20260323h">
    <?php if (!empty($loadDashboardCss)): ?>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <?php endif; ?>
</head>
<body class="portal-app bg-surface text-slate-100 min-h-screen dark">
<div class="portal-shell">
