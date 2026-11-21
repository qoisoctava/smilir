<?php
/**
 * Env::phpCli() — never hand PHP-FPM/CGI to a spawned child.
 *
 *   php tests/php/env_test.php
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

$failures = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "ok   {$label}\n";
        return;
    }
    echo "FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    $failures++;
}

$bin = Env::phpCli();

check('returns a non-empty path', $bin !== '');
check('resolved binary exists', is_file($bin), $bin);
check('resolved binary is executable', is_executable($bin), $bin);
check('is not php-fpm', !preg_match('/php-fpm$/i', basename($bin)), $bin);
check('is not php-cgi', !preg_match('/php-cgi$/i', basename($bin)), $bin);

$out = trim((string) @shell_exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo "cli-ok";') . ' 2>/dev/null'));
check('actually runs as CLI', $out === 'cli-ok', $out);

$version = trim((string) @shell_exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_VERSION;') . ' 2>/dev/null'));
check('resolved PHP is >= 8.1 (not a legacy lsphp)', version_compare($version, '8.1', '>='), $version);

// The explicit override must win when it points at a real CLI binary.
$GLOBALS['pn_config']['php_bin'] = $bin;
check('config php_bin override is honoured', Env::phpCli() === $bin);
unset($GLOBALS['pn_config']['php_bin']);

// A bogus/FPM path must be rejected instead of returned.
$GLOBALS['pn_config']['php_bin'] = '/nonexistent/php-fpm';
check('bogus php_bin falls back to a real CLI', Env::phpCli() !== '/nonexistent/php-fpm');
unset($GLOBALS['pn_config']['php_bin']);

// PHP < 8.3 returns -1 from proc_close() after proc_get_status() cached the
// exit code; settleExit() must keep the real child status.
check('settleExit keeps a normal exit code', Env::settleExit(0, 0) === 0);
check('settleExit keeps a failing exit code', Env::settleExit(2, 2) === 2);
check('settleExit repairs proc_close -1 from status', Env::settleExit(-1, 0) === 0);
check('settleExit repairs proc_close -1 from failure status', Env::settleExit(-1, 3) === 3);
check('settleExit keeps rc -1 when status is unknown', Env::settleExit(-1, null) === -1);
echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
