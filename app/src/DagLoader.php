<?php
declare(strict_types=1);

/**
 * Discovers DAG files, loads them (Python-first), validates and syncs to DB.
 *
 * Cache: a file is only executed again when its sha256 changes.
 */
final class DagLoader
{
    private const MAX_LOAD_SECONDS = 10.0;

    /** @return array<int,array> valid DAG definitions */
    public static function loadAll(): array
    {
        $dir   = Env::path('dags');
        $files = glob($dir . '/*.{py,php,yaml,yml}', GLOB_BRACE) ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $res = self::loadFile($file);
            if (!empty($res['ok'])) {
                $out[] = $res['dag'];
            } else {
                error_log('[' . PN_BRAND . '] dag ' . basename($file) . ': ' . ($res['error'] ?? 'invalid'));
            }
        }
        return $out;
    }

    /** @return array{ok:bool,dag?:array,error?:string,warnings?:array} */
    public static function loadFile(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'file not found'];
        }
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $hash = (string) hash_file('sha256', $path);

        $cached = Db::one('SELECT * FROM pn_dag WHERE source_path = ?', [$path]);
        if ($cached && $cached['source_hash'] === $hash) {
            return ['ok' => true, 'dag' => self::fromDb($cached), 'cached' => true];
        }

        $loaded = match ($ext) {
            'py'          => self::runPythonLoader($path),
            'php'         => self::runPhpLoader($path),
            'yaml', 'yml' => ['ok' => false, 'error' => 'YAML import is not enabled in this build'],
            default       => ['ok' => false, 'error' => 'unsupported file type'],
        };
        if (empty($loaded['ok'])) {
            return $loaded;
        }

        $dag = self::normalize((array) $loaded['dag'], $path, $ext, $hash);
        if (isset($dag['error'])) {
            return ['ok' => false, 'error' => $dag['error']];
        }
        $dag['warnings'] = $loaded['warnings'] ?? [];
        return ['ok' => true, 'dag' => $dag];
    }

    /** Rebuild a dag definition from DB rows (cache hit). */
    private static function fromDb(array $row): array
    {
        $tasks = Db::all('SELECT * FROM pn_task WHERE dag_id = ? ORDER BY id', [$row['id']]);
        return [
            'name'            => (string) $row['name'],
            'schedule_type'   => (string) $row['schedule_type'],
            'schedule'        => (string) $row['schedule'],
            'max_concurrency' => (int) $row['max_concurrency'],
            'venv'            => null,
            'default_venv_id' => $row['default_venv_id'] !== null ? (int) $row['default_venv_id'] : null,
            'source_path'     => (string) $row['source_path'],
            'source_hash'     => (string) $row['source_hash'],
            'loader'          => (string) $row['loader'],
            'tasks'           => array_map(static fn (array $t): array => [
                'name'             => (string) $t['name'],
                'runner'           => (string) $t['runner'],
                'func'             => $t['func'] !== null ? (string) $t['func'] : null,
                'command'          => (string) $t['command'],
                'venv_id'          => $t['venv_id'] !== null ? (int) $t['venv_id'] : null,
                'depends_on'       => $t['depends_on'] === '' ? [] : explode(',', (string) $t['depends_on']),
                'retries'          => (int) $t['retries'],
                'retry_delay'      => (int) $t['retry_delay'],
                'timeout'          => (int) $t['timeout'],
                'email_on_failure' => (int) $t['email_on_failure'],
            ], $tasks),
        ];
    }

    // ------------------------------------------------------------ loaders

    /** @return array{ok:bool,dag?:array,error?:string,traceback?:string} */
    private static function runPythonLoader(string $path): array
    {
        $py  = Env::python();
        if (!empty($py['error'])) {
            return ['ok' => false, 'error' => $py['error']
                . " — fix the venv path, or unset it as default (Python page)"];
        }
        $cmd = [$py['bin'], PN_BASE . '/lib/dag_loader.py', $path];
        $env = Env::pyEnv($py);

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, Env::path('dags'), $env);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'cannot start python loader (' . $py['bin'] . ')'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + self::MAX_LOAD_SECONDS;
        $timedOut = false;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                @proc_terminate($proc, 9);
                break;
            }
            usleep(20000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit  = (int) ($status['exitcode'] ?? -1);
        proc_close($proc);

        if ($timedOut) {
            return ['ok' => false, 'error' => 'loader timed out after 10s'];
        }
        $json = json_decode(trim($out), true);
        if (!is_array($json)) {
            $error = 'loader produced no JSON';
            if (trim($err) === '' && trim($out) === '' && $exit !== 0) {
                // empty stderr + non-zero exit → the interpreter never even ran
                $error .= " (python exited with code $exit — check 'python_bin' in app/config.php)";
            }
            return ['ok' => false, 'error' => $error,
                    'traceback' => trim($err) ?: trim($out)];
        }
        if (empty($json['ok'])) {
            return ['ok' => false, 'error' => (string) ($json['error'] ?? 'loader error'),
                    'traceback' => (string) ($json['traceback'] ?? $err)];
        }
        return ['ok' => true, 'dag' => (array) ($json['dag'] ?? []),
                'warnings' => (array) ($json['warnings'] ?? [])];
    }

    /** Legacy: a PHP file that `return`s an array. */
    private static function runPhpLoader(string $path): array
    {
        $code = 'echo json_encode(["ok"=>true,"dag"=>include $argv[1]]);';
        $cmd  = [Env::phpCli(), '-r', $code, '--', $path];
        $out  = @shell_exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1');
        $json = json_decode(trim((string) $out), true);
        if (!is_array($json) || empty($json['ok'])) {
            return ['ok' => false, 'error' => 'php loader failed', 'traceback' => (string) $out];
        }
        return ['ok' => true, 'dag' => (array) $json['dag']];
    }

    // ----------------------------------------------------------- normalize

    private static function normalize(array $raw, string $path, string $ext, string $hash): array
    {
        $base = pathinfo($path, PATHINFO_FILENAME);
        $name = strtolower(trim((string) ($raw['name'] ?? $base)));
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,127}$/', $name)) {
            return ['error' => "invalid dag name '{$name}'"];
        }

        $scheduleRaw = trim((string) ($raw['schedule'] ?? ''));
        if ($scheduleRaw === '') {
            return ['error' => 'missing schedule'];
        }
        $type = 'cron';
        $schedule = $scheduleRaw;
        if (str_starts_with($scheduleRaw, 'interval:')) {
            $type = 'interval';
            $minutes = (int) substr($scheduleRaw, 9);
            if ($minutes < 1) {
                return ['error' => 'interval must be >= 1 minute'];
            }
            $schedule = (string) $minutes;
        } elseif (!Cron::isValid($scheduleRaw)) {
            return ['error' => "invalid cron expression '{$scheduleRaw}'"];
        }

        $venvId = null;
        $venvName = isset($raw['venv']) ? trim((string) $raw['venv']) : '';
        if ($venvName !== '') {
            $venv = Db::one('SELECT id FROM pn_venv WHERE name = ?', [$venvName]);
            if ($venv) {
                $venvId = (int) $venv['id'];
            } else {
                return ['error' => "unknown venv '{$venvName}'"];
            }
        }

        $tasksIn = $raw['tasks'] ?? null;
        if (!is_array($tasksIn) || $tasksIn === []) {
            return ['error' => 'no tasks defined'];
        }

        // Global defaults from the Settings page; a task key always wins.
        $defaults = [
            'retries'          => max(0, min(10, Settings::int('task.retries', (int) Env::cfg('task_defaults.retries', 0)))),
            'retry_delay'      => max(0, min(86400, Settings::int('task.retry_delay', (int) Env::cfg('task_defaults.retry_delay', 300)))),
            'timeout'          => max(0, min(86400, Settings::int('task.timeout', (int) Env::cfg('task_defaults.timeout', 0)))),
            'email_on_failure' => Settings::bool('task.email', (bool) Env::cfg('task_defaults.email_on_failure', false)) ? 1 : 0,
        ];

        $tasks = [];
        foreach ($tasksIn as $t) {
            if (!is_array($t)) {
                return ['error' => 'task entries must be dicts'];
            }
            $tname = strtolower(trim((string) ($t['name'] ?? '')));
            if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,127}$/', $tname)) {
                return ['error' => "invalid task name '{$tname}'"];
            }
            if (isset($tasks[$tname])) {
                return ['error' => "duplicate task '{$tname}'"];
            }
            $func = isset($t['func']) ? trim((string) $t['func']) : '';
            $cmd  = isset($t['command']) ? trim((string) $t['command']) : '';
            if ($func === '' && $cmd === '') {
                return ['error' => "task '{$tname}' needs func or command"];
            }
            $runner = (string) ($t['runner'] ?? ($func !== '' ? 'python' : 'shell'));
            if (!in_array($runner, ['shell', 'http', 'php', 'python'], true)) {
                return ['error' => "task '{$tname}' has unknown runner '{$runner}'"];
            }
            $tVenvId = $venvId;
            if (!empty($t['venv'])) {
                $tv = Db::one('SELECT id FROM pn_venv WHERE name = ?', [(string) $t['venv']]);
                if (!$tv) {
                    return ['error' => "task '{$tname}' references unknown venv '{$t['venv']}'"];
                }
                $tVenvId = (int) $tv['id'];
            }
            $deps = $t['depends_on'] ?? [];
            if (is_string($deps)) {
                $deps = array_filter(array_map('trim', explode(',', $deps)));
            }
            $tasks[$tname] = [
                'name'             => $tname,
                'runner'           => $runner,
                'func'             => $func !== '' ? $func : null,
                'command'          => $cmd,
                'venv_id'          => $tVenvId,
                'depends_on'       => array_values(array_unique(array_map('strtolower', $deps))),
                'retries'          => max(0, min(10, (int) ($t['retries'] ?? $defaults['retries']))),
                'retry_delay'      => max(0, min(86400, (int) ($t['retry_delay'] ?? $defaults['retry_delay']))),
                'timeout'          => max(0, min(86400, (int) ($t['timeout'] ?? $defaults['timeout']))),
                'email_on_failure' => !empty($t['email_on_failure'] ?? $defaults['email_on_failure']) ? 1 : 0,
            ];
        }

        foreach ($tasks as $tname => $t) {
            foreach ($t['depends_on'] as $dep) {
                if (!isset($tasks[$dep])) {
                    return ['error' => "task '{$tname}' depends on unknown task '{$dep}'"];
                }
            }
        }
        if (($cycle = self::findCycle($tasks)) !== null) {
            return ['error' => 'dependency cycle: ' . implode(' → ', $cycle)];
        }

        return [
            'name'            => $name,
            'schedule_type'   => $type,
            'schedule'        => $schedule,
            'max_concurrency' => max(1, min(16, (int) ($raw['max_concurrency'] ?? 1))),
            'venv'            => $venvName !== '' ? $venvName : null,
            'default_venv_id' => $venvId,
            'email'           => isset($raw['email']) ? (string) $raw['email'] : null,
            'source_path'     => $path,
            'source_hash'     => $hash,
            'loader'          => $ext === 'yml' ? 'yaml' : $ext,
            'tasks'           => array_values($tasks),
        ];
    }

    /** @return string[]|null task names in cycle order */
    private static function findCycle(array $tasks): ?array
    {
        $state = [];
        $stack = [];
        $visit = function (string $n) use (&$visit, &$state, &$stack, $tasks): ?array {
            if (($state[$n] ?? 0) === 1) {
                $i = array_search($n, $stack, true);
                return array_slice($stack, $i === false ? 0 : $i);
            }
            if (($state[$n] ?? 0) === 2) {
                return null;
            }
            $state[$n] = 1;
            $stack[] = $n;
            foreach ($tasks[$n]['depends_on'] as $dep) {
                if (($c = $visit($dep)) !== null) {
                    return $c;
                }
            }
            array_pop($stack);
            $state[$n] = 2;
            return null;
        };
        foreach (array_keys($tasks) as $n) {
            if (($c = $visit($n)) !== null) {
                return $c;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- sync

    /** Upsert definitions and return fresh dag rows. */
    public static function sync(array $defs): array
    {
        foreach ($defs as $dag) {
            $existing = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$dag['name']]);
            $mtime = date('Y-m-d H:i:s', (int) @filemtime($dag['source_path']));

            if (!$existing) {
                $dagId = Db::insert(
                    'INSERT INTO pn_dag (name, schedule_type, schedule, is_active,
                        max_concurrency, default_venv_id, source_path, source_hash,
                        source_mtime, loader, next_run_at, created_at, updated_at)
                     VALUES (?,?,?,1,?,?,?,?,?,?,NULL,?,?)',
                    [$dag['name'], $dag['schedule_type'], $dag['schedule'],
                     $dag['max_concurrency'], $dag['default_venv_id'], $dag['source_path'],
                     $dag['source_hash'], $mtime, $dag['loader'], Env::now(), Env::now()]
                );
            } else {
                $dagId = (int) $existing['id'];
                Db::run(
                    'UPDATE pn_dag SET schedule_type=?, schedule=?, max_concurrency=?,
                        default_venv_id=?, source_path=?, source_hash=?, source_mtime=?,
                        loader=?, updated_at=? WHERE id=?',
                    [$dag['schedule_type'], $dag['schedule'], $dag['max_concurrency'],
                     $dag['default_venv_id'], $dag['source_path'], $dag['source_hash'],
                     $mtime, $dag['loader'], Env::now(), $dagId]
                );
            }

            $keep = [];
            foreach ($dag['tasks'] as $t) {
                $keep[] = $t['name'];
                Db::run(
                    'INSERT INTO pn_task (dag_id, name, runner, func, command, venv_id,
                        depends_on, retries, retry_delay, timeout, email_on_failure)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE runner=VALUES(runner), func=VALUES(func),
                        command=VALUES(command), venv_id=VALUES(venv_id),
                        depends_on=VALUES(depends_on), retries=VALUES(retries),
                        retry_delay=VALUES(retry_delay), timeout=VALUES(timeout),
                        email_on_failure=VALUES(email_on_failure)',
                    [$dagId, $t['name'], $t['runner'], $t['func'], $t['command'],
                     $t['venv_id'], implode(',', $t['depends_on']), $t['retries'],
                     $t['retry_delay'], $t['timeout'], $t['email_on_failure']]
                );
            }

            // Remove tasks that disappeared, but keep any with run history.
            $stale = Db::all('SELECT id, name FROM pn_task WHERE dag_id = ?', [$dagId]);
            foreach ($stale as $row) {
                if (in_array($row['name'], $keep, true)) {
                    continue;
                }
                $runs = Db::one('SELECT COUNT(*) AS n FROM pn_task_run WHERE task_id = ?', [$row['id']]);
                if ((int) ($runs['n'] ?? 0) === 0) {
                    Db::run('DELETE FROM pn_task WHERE id = ?', [$row['id']]);
                } else {
                    error_log('[' . PN_BRAND . '] task removed from source but kept (has history): '
                        . $row['name']);
                }
            }
        }

        return Db::all('SELECT * FROM pn_dag ORDER BY id');
    }
}
