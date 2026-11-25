#!/usr/bin/env php
<?php
/**
 * smilir runner — executes ONE task attempt.
 * Launched detached by the scheduler: php runner.php --run=<task_run_id>
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!Env::isCli()) {
    http_response_code(403);
    exit(PN_BRAND . ': CLI only');
}

$id = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--run=')) {
        $id = (int) substr($arg, 6);
    }
}
if ($id <= 0) {
    fwrite(STDERR, "usage: runner.php --run=<task_run_id>\n");
    exit(64);
}

try {
    exit(Runner::run($id));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . PN_BRAND . '] runner failed: ' . $e->getMessage() . "\n"
        . $e->getTraceAsString() . "\n");
    exit(1);
}
