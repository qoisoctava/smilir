<?php
/**
 * smilir bootstrap — config, timezone and class loading.
 * Every entry point (scheduler, runner, dagcheck, UI) requires this file.
 */
declare(strict_types=1);

define('PN_BASE', dirname(__DIR__));
define('PN_APP', __DIR__);
define('PN_SRC', __DIR__ . '/src');
define('PN_VERSION', '1.0.0');

// Brand layer: these two are the single source of truth for the display name.
// Override per installation with `ui.title` / `ui.tagline` in config.php.
define('PN_BRAND', 'smilir');
define('PN_TAGLINE', "Less Complexity.\nMore Efficiency.");

$cfgFile = PN_APP . '/config.php';
$GLOBALS['pn_config'] = require (is_file($cfgFile) ? $cfgFile : PN_APP . '/config.example.php');

date_default_timezone_set((string) ($GLOBALS['pn_config']['timezone'] ?? 'UTC'));

foreach (['Env', 'Db', 'Lock', 'Cron', 'Logger', 'Alerter', 'Audit',
          'DagWriter', 'DagLoader', 'Scheduler', 'Runner', 'TaskOps',
          'PasswordPolicy', 'Settings', 'Updater'] as $class) {
    $file = PN_SRC . '/' . $class . '.php';
    if (!is_file($file)) {
        fwrite(STDERR, PN_BRAND . ": missing class file {$file}\n");
        exit(70);
    }
    require_once $file;
}

foreach (['logs', 'logs/runs', 'storage', 'dags', 'jobs'] as $dir) {
    $path = PN_BASE . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}
