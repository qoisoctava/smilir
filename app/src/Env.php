<?php
declare(strict_types=1);

/**
 * Config access + filesystem helpers.
 */
final class Env
{
    /** @return mixed */
    public static function cfg(?string $key = null, $default = null)
    {
        $cfg = $GLOBALS['pn_config'] ?? [];
        if ($key === null) {
            return $cfg;
        }
        $cur = $cfg;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public static function path(string $sub = ''): string
    {
        return PN_BASE . ($sub === '' ? '' : '/' . ltrim($sub, '/'));
    }

    public static function logs(): string
    {
        return self::path('logs');
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Absolute path to a PHP **CLI** binary, safe to spawn from the web SAPI.
     *
     * PHP_BINARY cannot be trusted from the web:
     *   - PHP-FPM exports its own `php-fpm` binary (spawning it hangs forever),
     *   - LiteSpeed exports `lsphp`, which is often an old PHP 5.6 build
     *     (the child then dies with "Unsupported declare 'strict_types'").
     * So every candidate is probed with `-r 'echo PHP_VERSION;'` and only a
     * PHP >= 8.1 build is accepted. Order: `php_bin` config → PHP_BINARY →
     * EA-PHP / alt-php / LiteSpeed paths → "php".
     */
    public static function phpCli(): string
    {
        $configured = self::cfg('php_bin');
        if (is_string($configured) && $configured !== '') {
            // An explicit value wins as long as it is an executable path or a
            // bare command name resolved through PATH.
            if (!str_contains($configured, '/') || self::usableBinary($configured)) {
                return $configured;
            }
        }

        $candidates = [];
        $binary = (string) (defined('PHP_BINARY') ? PHP_BINARY : '');
        if ($binary !== '') {
            $candidates[] = $binary;
        }

        $patterns = [
            '/opt/cpanel/ea-php*/root/usr/bin/php',
            '/opt/cpanel/ea-php*/root/usr/bin/lsphp',
            '/opt/alt/php*/usr/bin/php',
            '/usr/local/lsws/lsphp*/bin/lsphp',
            '/usr/local/bin/php8*',
            (defined('PHP_BINDIR') ? PHP_BINDIR : '/usr/bin') . '/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $found) {
                $candidates[] = $found;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (!self::usableBinary($candidate)) {
                continue;
            }
            $version = self::binaryVersion($candidate);
            if ($version !== null && version_compare($version, '8.1', '>=')) {
                return $candidate;
            }
        }

        return 'php';
    }

    /**
     * Reconcile the exit code of a spawned child.
     *
     * PHP < 8.3 makes proc_close() return -1 as soon as proc_get_status()
     * cached the exit code (bugs.php.net #75272), which made a successful
     * `pip install` print "pip exited with -1" and skip the post-install
     * inventory refresh. The status array holds the real code.
     */
    public static function settleExit(int $rc, ?int $statusExit): int
    {
        if ($rc < 0 && $statusExit !== null && $statusExit >= 0) {
            return $statusExit;
        }
        return $rc;
    }

    /** True for an executable that is php/lsphp/php<N>, never php-fpm/php-cgi. */
    private static function usableBinary(string $path): bool
    {
        if ($path === '' || !is_file($path) || !is_executable($path)) {
            return false;
        }
        return !preg_match('/php-(fpm|cgi)$/i', basename($path));
    }

    /** `PHP_VERSION` of a candidate binary, or null when it cannot be run. */
    private static function binaryVersion(string $bin): ?string
    {
        $cmd = escapeshellarg($bin) . ' -r ' . escapeshellarg('echo PHP_VERSION;') . ' 2>/dev/null';
        $proc = @proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + 2.0;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 9);
                break;
            }
            usleep(20000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);

        $out = trim($out);
        return preg_match('/^\d+\.\d+\.\d+/', $out, $m) ? $m[0] : null;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Resolve the interpreter + venv path for a task/dag.
     *
     * @return array{bin:string, venv:?string, name:?string}
     */
    public static function python(?int $venvId = null, ?string $venvName = null): array
    {
        $row = null;
        if ($venvId !== null) {
            $row = Db::one('SELECT * FROM pn_venv WHERE id = ?', [$venvId]);
        }
        if (!$row && $venvName !== null && $venvName !== '') {
            $row = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$venvName]);
        }
        if (!$row) {
            $row = Db::one('SELECT * FROM pn_venv WHERE is_default = 1 ORDER BY id LIMIT 1');
        }
        if ($row) {
            $base = rtrim((string) $row['path'], '/');
            foreach (['/bin/python3', '/bin/python'] as $suffix) {
                if (is_file($base . $suffix)) {
                    return ['bin' => $base . $suffix, 'venv' => $base,
                            'name' => (string) $row['name'], 'error' => null];
                }
            }
            // Registered but the interpreter is gone (path typo, moved host,
            // deleted venv). Say so instead of failing with a bare exit 127.
            return [
                'bin'   => (string) (self::cfg('python_bin') ?: 'python3'),
                'venv'  => $base,
                'name'  => (string) $row['name'],
                'error' => sprintf("venv '%s' has no python at %s/bin/python3",
                                   $row['name'], $base),
            ];
        }

        $fallbackVenv = self::cfg('default_venv');
        if (is_string($fallbackVenv) && $fallbackVenv !== '') {
            $base = rtrim($fallbackVenv, '/');
            if (is_file($base . '/bin/python') || is_file($base . '/bin/python3')) {
                return ['bin' => $base . '/bin/python', 'venv' => $base,
                        'name' => null, 'error' => null];
            }
            return [
                'bin'   => (string) (self::cfg('python_bin') ?: 'python3'),
                'venv'  => $base,
                'name'  => null,
                'error' => sprintf("default_venv '%s' has no python at %s/bin/python3",
                                   $base, $base),
            ];
        }

        return [
            'bin'   => (string) (self::cfg('python_bin') ?: 'python3'),
            'venv'  => null,
            'name'  => null,
            'error' => null,
        ];
    }

    /**
     * Environment injected into python subprocesses (zero-install shim).
     *
     * @return array<string,string>
     */
    public static function pyEnv(?array $py = null): array
    {
        // PN_BASE itself must be on the path for package-style funcs like
        // "jobs.reporting.extract" (jobs/ is a package → the parent dir is
        // what Python needs); lib/, dags/, jobs/ cover top-level modules.
        $paths = [
            PN_BASE,
            PN_BASE . '/lib',
            PN_BASE . '/dags',
            PN_BASE . '/jobs',
        ];
        if (!empty($py['venv'])) {
            $paths[] = rtrim((string) $py['venv'], '/') . '/lib';
        }
        $env = [
            'PATH'              => (isset($py['venv']) && $py['venv']
                                        ? rtrim((string) $py['venv'], '/') . '/bin:'
                                        : '') . (getenv('PATH') ?: '/usr/bin:/bin'),
            'PYTHONPATH'        => implode(':', $paths),
            'PYTHONNOUSERSITE'  => '1',
            'PYTHONDONTWRITEBYTECODE' => '1',
            'HOME'              => getenv('HOME') ?: PN_BASE,
            'LANG'              => getenv('LANG') ?: 'C.UTF-8',
        ];
        if (!empty($py['venv'])) {
            $env['VIRTUAL_ENV'] = (string) $py['venv'];
        }
        return $env;
    }
}
