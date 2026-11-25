#!/usr/bin/env php
<?php
/**
 * Render app/config.php from PIPNIX_* environment variables.
 *
 * Runs at container start. An existing config.php is left untouched unless
 * PIPNIX_FORCE_CONFIG=1, so a bind-mounted config keeps working.
 */
declare(strict_types=1);

$appDir = getenv('PIPNIX_APP_DIR') ?: '/var/www/pipnix';
$file = $appDir . '/app/config.php';

if (is_file($file) && getenv('PIPNIX_FORCE_CONFIG') !== '1') {
    fwrite(STDOUT, "[smilir] config: keeping existing app/config.php\n");
    exit(0);
}

$env = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

$to = array_values(array_filter(array_map('trim', explode(',', (string) $env('PIPNIX_ALERTS_TO', '')))));

$config = [
    'db' => [
        'host' => $env('PIPNIX_DB_HOST', 'db'),
        'port' => (int) $env('PIPNIX_DB_PORT', '3306'),
        'name' => $env('PIPNIX_DB_NAME', 'pipnix'),
        'user' => $env('PIPNIX_DB_USER', 'pipnix'),
        'pass' => $env('PIPNIX_DB_PASS', ''),
    ],
    'timezone'  => $env('PIPNIX_TIMEZONE', 'UTC'),
    'python_bin' => $env('PIPNIX_PYTHON_BIN', '/usr/bin/python3'),
    'default_venv' => $env('PIPNIX_DEFAULT_VENV'),
    'lock' => [
        'name'    => $env('PIPNIX_LOCK_NAME', 'pipnix'),
        'timeout' => (int) $env('PIPNIX_LOCK_TIMEOUT', '50'),
    ],
    'tick' => [
        'max_launches_per_tick' => (int) $env('PIPNIX_MAX_LAUNCHES', '4'),
        'stale_runner_grace'    => (int) $env('PIPNIX_STALE_GRACE', '15'),
    ],
    'alerts' => [
        'enabled' => $env('PIPNIX_ALERTS_ENABLED', '0') === '1',
        'from'    => $env('PIPNIX_ALERTS_FROM', 'smilir@example.com'),
        'to'      => $to,
    ],
    'ui' => [
        'title'     => $env('PIPNIX_UI_TITLE', 'smilir'),
        'tagline'   => $env('PIPNIX_UI_TAGLINE', "Less Complexity.\nMore Efficiency."),
        'logo'      => $env('PIPNIX_UI_LOGO', '/assets/logo-white.png'),
        'icon'      => $env('PIPNIX_UI_ICON', '/assets/app-icon-32.png'),
        'base_path' => $env('PIPNIX_BASE_PATH', ''),
    ],
    'updater' => [
        'enabled'      => $env('PIPNIX_UPDATER_ENABLED', '1') === '1',
        'repo'         => $env('PIPNIX_UPDATE_REPO', 'qoisoctava/pipnix'),
        'channel'      => $env('PIPNIX_UPDATE_CHANNEL', 'branch'),
        'branch'       => $env('PIPNIX_UPDATE_BRANCH', 'main'),
        'token'        => $env('PIPNIX_UPDATE_TOKEN'),
        'allow_docker' => $env('PIPNIX_UPDATE_ALLOW_DOCKER', '0') === '1',
    ],
];

$php = "<?php\n"
    . "/**\n"
    . " * Generated at container start from PIPNIX_* environment variables.\n"
    . " * Change .env (or docker-compose.yml) instead of editing this file.\n"
    . " */\n"
    . "declare(strict_types=1);\n\n"
    . 'return ' . var_export($config, true) . ";\n";

if (@file_put_contents($file, $php) === false) {
    fwrite(STDERR, "[smilir] config: cannot write {$file}\n");
    exit(1);
}
@chmod($file, 0640);
fwrite(STDOUT, "[smilir] config: wrote {$file}\n");
