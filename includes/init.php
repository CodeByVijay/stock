<?php

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('PRIVATE_ROOT')) {
    define('PRIVATE_ROOT', APP_ROOT . '/private');
}

require_once APP_ROOT . '/includes/functions.php';

start_secure_session();
