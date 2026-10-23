<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$pageTitle = 'ETFs';
$activeNav = 'etfs';
$instrumentType = 'etf';
$detailsPage = 'stock-details.php';

require __DIR__ . '/includes/instrument_list_view.php';
