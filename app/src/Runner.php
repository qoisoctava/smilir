<?php
declare(strict_types=1);

/**
 * Executes one task attempt (detached by the scheduler).
 * Writes the final state itself so a crashed tick never loses a result.
 */
final class Runner
{
    public static function run(int $taskRunId): int
    {
        $tr = Db::one(
            'SELECT tr.*, t.name AS task_name, t.runner, t.func, t.command, t.venv_id,
                    t.timeout, t.email_on_failure,
                    dr.run_id, d.name AS dag_name
               FROM pn_task_run tr
               JOIN pn_task    t  ON t.id  = tr.task_id
               JOIN pn_dag_run dr ON dr.id = tr.dag_run_id
               JOIN pn_dag     d  ON d.id  = dr.dag_id
              WHERE tr.id = ?',
            [$taskRunId]
        );
        if (!$tr) {
            fwrite(STDERR, PN_BRAND . " runner: unknown task_run {$taskRunId}\n");
            return 66;
        }
        if (!in_array($tr['state'], ['queued', 'running'], true)) {
            fwrite(STDERR, PN_BRAND . " runner: task_run {$taskRunId} is {$tr['state']}, skipping\n");
            return 0;
        }

        $logPath = Logger::pathFor(
            (string) $tr['dag_name'],
            (string) $tr['run_id'],
            (string) $tr['task_name'],
            (int) $tr['attempt']
        );
        Db::run('UPDATE pn_task_run SET state = ?, log_path = ?, started_at = COALESCE(started_at, ?),
                    updated_at = ? WHERE id = ?',
            ['running', $logPath, Env::now(), Env::now(), $taskRunId]);

        Logger::line($logPath, sprintf(
            PN_BRAND . ': start %s/%s attempt %d (runner=%s)',
            $tr['dag_name'], $tr['task_name'], $tr['attempt'], $tr['runner']
        ));

        [$exitCode, $timedOut] = self::execute($tr, $logPath);

        $state = $timedOut ? 'timed_out' : ($exitCode === 0 ? 'success' : 'failed');
        $error = null;
        if ($state !== 'success') {
            $error = $timedOut
                ? 'timeout exceeded'
                : trim(substr(Logger::tail($logPath, 4000), -4000));
            if ($error === '') {
                $error = 'exit code ' . $exitCode;
            }
        }

        Db::run(
            'UPDATE pn_task_run SET state = ?, exit_code = ?, finished_at = ?, error = ?,
                updated_at = ? WHERE id = ?',
            [$state, $exitCode, Env::now(), $error, Env::now(), $taskRunId]
        );
        Logger::line($logPath, sprintf(PN_BRAND . ': finished %s (exit %d)', $state, $exitCode));

        if ($state !== 'success') {
            $fresh = Db::one('SELECT * FROM pn_task_run WHERE id = ?', [$taskRunId]) ?? $tr;
            Alerter::maybeNotify($fresh, $tr, ['name' => $tr['dag_name']]);
        }
        return 0;
    }

    /** @return array{0:int,1:bool} [exit code, timed out] */
    private static function execute(array $tr, string $logPath): array
    {
        $py      = Env::python($tr['venv_id'] !== null ? (int) $tr['venv_id'] : null);
        $timeout = (int) $tr['timeout'];
        $resultFile = null;

        if (!empty($py['error'])) {
            Logger::line($logPath, PN_BRAND . ': ' . $py['error']
                . ' — check the venv path on the Python page, or pick another environment');
            return [127, false];
        }

        if ($tr['runner'] === 'python' && !empty($tr['func'])) {
            $resultFile = tempnam(Env::path('storage'), 'result_') ?: null;
            $cmd = [
                $py['bin'],
                PN_BASE . '/lib/runner.py',
                '--func', (string) $tr['func'],
                '--ds', substr((string) $tr['run_id'], 0, 10),
            ];
            if ($resultFile !== null) {
                $cmd[] = '--result';
                $cmd[] = $resultFile;
            }
            $env = Env::pyEnv($py);
            $env['PN_RUN_ID']  = (string) $tr['run_id'];
            $env['PN_ATTEMPT'] = (string) $tr['attempt'];
            $env['PN_TASK']    = (string) $tr['task_name'];
            $env['PN_DAG']     = (string) $tr['dag_name'];
            $res = self::exec($cmd, $env, $logPath, $timeout);
        } elseif ($tr['runner'] === 'http') {
            $url = trim((string) $tr['command']);
            $res = self::exec(['/bin/sh', '-c', 'curl -fsS -m ' . max(10, $timeout ?: 300)
                . ' ' . escapeshellarg($url)], Env::pyEnv($py), $logPath, $timeout);
        } else {
            // shell / php / python-with-command: run through sh with the venv first on PATH
            $res = self::exec(['/bin/sh', '-c', (string) $tr['command']],
                Env::pyEnv($py), $logPath, $timeout);
        }

        if ($resultFile !== null) {
            if (is_file($resultFile)) {
                $json = trim((string) @file_get_contents($resultFile));
                if ($json !== '') {
                    Db::run('UPDATE pn_task_run SET result_json = ? WHERE id = ?',
                        [$json, $tr['id']]);
                }
                @unlink($resultFile);
            }
        }
        return $res;
    }

    /**
     * Run a command, streaming output into the log, enforcing a timeout.
     *
     * @param string[] $cmd
     * @param array<string,string> $env
     * @return array{0:int,1:bool} [exit code, timed out]
     */
    private static function exec(array $cmd, array $env, string $logPath, int $timeout): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, PN_BASE, $env);
        if (!is_resource($proc)) {
            Logger::line($logPath, PN_BRAND . ': failed to start process');
            return [127, false];
        }
        $fh = @fopen($logPath, 'ab');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;
        $timedOut = false;
        while (true) {
            $chunk = (string) stream_get_contents($pipes[1]);
            $err   = (string) stream_get_contents($pipes[2]);
            if ($chunk !== '' || $err !== '') {
                if ($fh) {
                    fwrite($fh, $chunk . $err);
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = (int) ($status['exitcode'] ?? -1);
                break;
            }
            if ($deadline !== null && microtime(true) > $deadline) {
                $timedOut = true;
                @proc_terminate($proc, 9);
                $exit = 124;
                break;
            }
            usleep(20000);
        }

        $chunk = (string) stream_get_contents($pipes[1]);
        $err   = (string) stream_get_contents($pipes[2]);
        if (($chunk !== '' || $err !== '') && $fh) {
            fwrite($fh, $chunk . $err);
        }
        if ($fh) {
            fclose($fh);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [$exit, $timedOut];
    }
}
