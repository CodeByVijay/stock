<?php

declare(strict_types=1);

/**
 * Evaluate prediction results whose horizon has ended.
 *
 *   php private/cron/evaluate-predictions.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/cli/bootstrap.php';
require_once PRIVATE_ROOT . '/services/predictions.php';

try {
    $count = prediction_evaluate_due_outcomes(db());
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Evaluated {$count} prediction result(s)." . PHP_EOL;
exit(0);
