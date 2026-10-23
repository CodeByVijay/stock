<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';
require_once PRIVATE_ROOT . '/services/upstox_instruments.php';

$pdo = db();
$n = instrument_deactivate_non_equity_cash_listings($pdo);
$symNse = upstox_backfill_empty_instrument_symbols($pdo, 'NSE');
$symBse = upstox_backfill_empty_instrument_symbols($pdo, 'BSE');

echo "Deactivated {$n} non-equity rows (mutual fund units, GOI bonds, etc.).\n";
echo "Backfilled symbols: NSE {$symNse}, BSE {$symBse}.\n";
