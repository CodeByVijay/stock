<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo 'PHP ' . PHP_VERSION . ' (CLI)' . "\n";

try {
    db();
    echo "database: OK\n";
} catch (Throwable $e) {
    echo 'database: FAIL — ' . $e->getMessage() . "\n";
    exit(1);
}

foreach (glob(PRIVATE_ROOT . '/services/*.php') ?: [] as $file) {
    $out = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
    if ($out !== null && strpos($out, 'No syntax errors') === false) {
        echo trim($out) . "\n";
        exit(1);
    }
}

echo "All service PHP files: syntax OK\n";
exit(0);
