<?php

declare(strict_types=1);

function sync_log(string $provider, string $event, array $context = []): void
{
    $line = [
        'time' => gmdate('c'),
        'provider' => $provider,
        'event' => $event,
        'context' => $context,
    ];

    $dir = PRIVATE_ROOT . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $path = $dir . '/sync.log';
    @file_put_contents($path, json_encode($line, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}
