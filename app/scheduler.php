#!/usr/bin/env php
<?php
/**
 * smilir tick — run this from cron every minute:
 *   * * * * * /usr/local/bin/php /home/USER/pipnix/app/scheduler.php >> /home/USER/pipnix/logs/cron.log 2>&1
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!Env::isCli()) {
    http_response_code(403);
    exit(PN_BRAND . ': CLI only');
}

try {
    Scheduler::tick();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . PN_BRAND . '] tick failed: ' . $e->getMessage() . "\n"
        . $e->getTraceAsString() . "\n");
    exit(1);
}
