#!/usr/bin/env bash
# Optional long-running drain worker for local Ubuntu dev (e.g. if Apache cannot spawn proc_open).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${PHP_CLI:-/usr/bin/php}"
cd "$ROOT"
exec "$PHP" private/cron/process-queue.php --drain --max-jobs=1 --max-loops=50000
