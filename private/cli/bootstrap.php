<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$appRoot = dirname(__DIR__, 2);
define('APP_ROOT', $appRoot);
define('PRIVATE_ROOT', $appRoot . '/private');

require_once APP_ROOT . '/includes/functions.php';
