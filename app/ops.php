#!/usr/bin/env php
<?php
/**
 * pipnix ops — operator CLI (works before the web UI exists).
 *
 *   php app/ops.php dags
 *   php app/ops.php runs [--dag=NAME] [--n=20]
 *   php app/ops.php tasks --run=<dag_run_id>
 *   php app/ops.php trigger --dag=NAME [--run-id=manual__...]
 *   php app/ops.php pause|resume --dag=NAME
 *   php app/ops.php clear --run=<task_run_id>
 *   php app/ops.php logs --run=<task_run_id> [--tail=100]
 *   php app/ops.php tick
 *   php app/ops.php venv:check [--name=NAME]
 *   php app/ops.php venv:list [--name=NAME]
 *   php app/ops.php venv:install --name=NAME pkg[==ver] ...
 *   php app/ops.php venv:upgrade --name=NAME
 *   php app/ops.php venv:requirements --name=NAME --file=requirements.txt
 *   php app/ops.php venv:create --name=NAME --path=/path/to/venv [--python=python3]
 *   php app/ops.php venv:register --name=NAME --path=/path/to/existing/venv
 *   php app/ops.php venv:delete --name=NAME
 *   php app/ops.php update:check
 *   php app/ops.php update:apply [--archive=FILE]
 *   php app/ops.php update:rollback
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!Env::isCli()) {
    http_response_code(403);
    exit('pipnix: CLI only');
}

$argvCopy = array_slice($argv, 1);
$cmd  = $argvCopy[0] ?? 'help';
$opts = [];
$pos  = [];
foreach (array_slice($argvCopy, 1) as $arg) {
    if (preg_match('/^--([a-zA-Z0-9:_-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $pos[] = $arg;
    }
}

try {
    exit(dispatch($cmd, $opts, $pos));
} catch (Throwable $e) {
    fwrite(STDERR, "pipnix: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------

function dispatch(string $cmd, array $opts, array $pos): int
{
    switch ($cmd) {
        case 'help':
            echo helpText();
            return 0;
        case 'dags':
            return cmdDags();
        case 'runs':
            return cmdRuns($opts);
        case 'tasks':
            return cmdTasks($opts);
        case 'trigger':
            return cmdTrigger($opts);
        case 'pause':
            return cmdPause($opts, false);
        case 'resume':
            return cmdPause($opts, true);
        case 'clear':
            return cmdClear($opts);
        case 'logs':
            return cmdLogs($opts);
        case 'tick':
            Scheduler::tick();
            echo "tick done\n";
            return 0;
        case 'venv:check':
            return cmdVenvCheck($opts);
        case 'venv:list':
            return cmdVenvList($opts);
        case 'venv:install':
            return cmdVenvInstall($opts, $pos);
        case 'venv:uninstall':
            return cmdVenvUninstall($opts, $pos);
        case 'venv:upgrade':
            return cmdVenvUpgrade($opts);
        case 'venv:requirements':
            return cmdVenvRequirements($opts);
        case 'venv:create':
            return cmdVenvCreate($opts);
        case 'venv:register':
            return cmdVenvRegister($opts);
        case 'venv:delete':
            return cmdVenvDelete($opts);
        case 'update:check':
            return cmdUpdateCheck();
        case 'update:apply':
            return cmdUpdateApply($opts);
        case 'update:rollback':
            return cmdUpdateRollback();
        case 'prune':
            return cmdPrune($opts);
        default:
            fwrite(STDERR, "unknown command '{$cmd}'\n\n" . helpText());
            return 64;
    }
}

function helpText(): string
{
    return <<<TXT
pipnix ops

  dags                              list DAGs
  runs [--dag=NAME] [--n=20]        recent dag runs
  tasks --run=<dag_run_id>          tasks of one run
  trigger --dag=NAME [--run-id=..]  create a manual run now
  pause  --dag=NAME                 disable scheduling
  resume --dag=NAME                 enable scheduling
  clear --run=<task_run_id>         reset a task (+ downstream) for a rerun
  logs --run=<task_run_id> [--tail=100]
  tick                              run one scheduler tick
  venv:check [--name=NAME]          refresh interpreter + package inventory
  venv:list [--name=NAME]           registered venvs, or one venv's packages
  venv:install --name=NAME pkg[==ver] ...
  venv:uninstall --name=NAME pkg
  venv:upgrade --name=NAME          pip install --upgrade for outdated packages
  venv:requirements --name=NAME --file=FILE
  venv:create --name=NAME --path=DIR [--python=BIN]
  venv:register --name=NAME --path=DIR [--default]
                                    register a venv that already exists
  venv:delete --name=NAME           remove the registration (files stay)
  update:check                      compare with GitHub (release or branch)
  update:apply [--archive=FILE]     download + apply + migrate + backup
  update:rollback                   restore the newest backup (files only)
  prune [--days=N] [--dry-run]       drop finished runs + their logs older
                                    than N days (audit rows are kept)
TXT;
}

// ------------------------------------------------------------------- dags

function cmdDags(): int
{
    $rows = Db::all(
        'SELECT d.*,
                (SELECT COUNT(*) FROM pn_task t WHERE t.dag_id = d.id) AS tasks,
                (SELECT r.state FROM pn_dag_run r WHERE r.dag_id = d.id
                  ORDER BY r.id DESC LIMIT 1) AS last_state
           FROM pn_dag d ORDER BY d.name'
    );
    if ($rows === []) {
        echo "no dags loaded yet (put a module in dags/ and run a tick)\n";
        return 0;
    }
    table(['NAME', 'SCHEDULE', 'ACTIVE', 'TASKS', 'LAST', 'NEXT RUN'], array_map(
        static fn (array $r): array => [
            $r['name'],
            $r['schedule_type'] === 'interval' ? 'every ' . $r['schedule'] . 'm' : $r['schedule'],
            (int) $r['is_active'] === 1 ? 'yes' : 'paused',
            (string) $r['tasks'],
            (string) ($r['last_state'] ?? '-'),
            (string) ($r['next_run_at'] ?? '-'),
        ],
        $rows
    ));
    return 0;
}

function cmdRuns(array $opts): int
{
    $n = (int) ($opts['n'] ?? 20);
    $params = [];
    $where = '';
    if (!empty($opts['dag'])) {
        $where = 'WHERE d.name = ?';
        $params[] = (string) $opts['dag'];
    }
    $rows = Db::all(
        "SELECT r.*, d.name AS dag_name,
                (SELECT COUNT(*) FROM pn_task_run tr WHERE tr.dag_run_id = r.id) AS tasks,
                (SELECT COUNT(*) FROM pn_task_run tr WHERE tr.dag_run_id = r.id
                  AND tr.state IN ('success','failed','skipped','upstream_failed','timed_out')) AS done
           FROM pn_dag_run r JOIN pn_dag d ON d.id = r.dag_id
           {$where}
          ORDER BY r.id DESC LIMIT {$n}",
        $params
    );
    if ($rows === []) {
        echo "no runs\n";
        return 0;
    }
    table(['ID', 'DAG', 'RUN ID', 'STATE', 'TASKS', 'STARTED', 'FINISHED'], array_map(
        static fn (array $r): array => [
            (string) $r['id'], (string) $r['dag_name'], (string) $r['run_id'],
            (string) $r['state'], $r['done'] . '/' . $r['tasks'],
            (string) ($r['started_at'] ?? '-'), (string) ($r['finished_at'] ?? '-'),
        ],
        $rows
    ));
    return 0;
}

function cmdTasks(array $opts): int
{
    $runId = (int) ($opts['run'] ?? 0);
    if ($runId <= 0) {
        fwrite(STDERR, "usage: tasks --run=<dag_run_id>\n");
        return 64;
    }
    $rows = Db::all(
        'SELECT tr.*, t.name AS task_name, t.depends_on
           FROM pn_task_run tr JOIN pn_task t ON t.id = tr.task_id
          WHERE tr.dag_run_id = ? ORDER BY tr.id',
        [$runId]
    );
    if ($rows === []) {
        echo "no task runs for dag_run {$runId}\n";
        return 0;
    }
    table(['ID', 'TASK', 'STATE', 'ATT', 'EXIT', 'STARTED', 'FINISHED', 'ERROR'], array_map(
        static fn (array $r): array => [
            (string) $r['id'], (string) $r['task_name'], (string) $r['state'],
            (string) $r['attempt'], (string) ($r['exit_code'] ?? '-'),
            (string) ($r['started_at'] ?? '-'), (string) ($r['finished_at'] ?? '-'),
            trim((string) ($r['error'] ?? '')),
        ],
        $rows
    ));
    return 0;
}

// ---------------------------------------------------------------- actions

function cmdTrigger(array $opts): int
{
    $name = (string) ($opts['dag'] ?? '');
    $dag = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        fwrite(STDERR, "unknown dag '{$name}'\n");
        return 1;
    }
    $runId = (string) ($opts['run-id'] ?? ('manual__' . date('Y-m-d\TH:i:s')));
    $id = Scheduler::createRun((int) $dag['id'], $runId);
    Db::run('UPDATE pn_dag SET last_run_at = ? WHERE id = ?', [Env::now(), $dag['id']]);
    $n = (int) (Db::one('SELECT COUNT(*) AS n FROM pn_task_run WHERE dag_run_id = ?', [$id])['n'] ?? 0);
    echo "created dag_run #{$id} ({$runId}) with {$n} tasks\n";
    echo "run `php app/ops.php tick` (or wait for cron) to dispatch\n";
    return 0;
}

function cmdPause(array $opts, bool $resume): int
{
    $name = (string) ($opts['dag'] ?? '');
    $dag = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        fwrite(STDERR, "unknown dag '{$name}'\n");
        return 1;
    }
    Db::run('UPDATE pn_dag SET is_active = ?, updated_at = ? WHERE id = ?',
        [$resume ? 1 : 0, Env::now(), $dag['id']]);
    echo ($resume ? 'resumed' : 'paused') . " {$name}\n";
    return 0;
}

function cmdClear(array $opts): int
{
    $id = (int) ($opts['run'] ?? 0);
    if ($id <= 0) {
        fwrite(STDERR, "usage: clear --run=<task_run_id>\n");
        return 64;
    }
    try {
        $res = TaskOps::clear($id);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        return 1;
    }
    echo "cleared {$res['count']} task run(s) in dag_run #{$res['dag_run_id']} (task {$res['task']} + downstream)\n";
    echo "run `php app/ops.php tick` to dispatch\n";
    return 0;
}

function cmdLogs(array $opts): int
{
    $id = (int) ($opts['run'] ?? 0);
    $tail = max(1, (int) ($opts['tail'] ?? 100));
    if ($id <= 0) {
        fwrite(STDERR, "usage: logs --run=<task_run_id> [--tail=100]\n");
        return 64;
    }
    $tr = Db::one('SELECT id, log_path FROM pn_task_run WHERE id = ?', [$id]);
    if (!$tr || empty($tr['log_path']) || !is_file((string) $tr['log_path'])) {
        fwrite(STDERR, "no log for task_run {$id}\n");
        return 1;
    }
    $lines = file((string) $tr['log_path'], FILE_IGNORE_NEW_LINES) ?: [];
    foreach (array_slice($lines, -$tail) as $line) {
        echo $line, "\n";
    }
    return 0;
}

// ----------------------------------------------------------------- venvs

function cmdVenvCheck(array $opts): int
{
    $name = $opts['name'] ?? null;
    $venvs = $name
        ? Db::all('SELECT * FROM pn_venv WHERE name = ?', [(string) $name])
        : Db::all('SELECT * FROM pn_venv ORDER BY is_default DESC, name');
    if ($venvs === []) {
        echo "no venvs registered\n";
        return 0;
    }
    foreach ($venvs as $venv) {
        $path = rtrim((string) $venv['path'], '/');
        $py = is_file($path . '/bin/python') ? $path . '/bin/python' : $path . '/bin/python3';
        if (!is_file($py)) {
            Db::run('UPDATE pn_venv SET status = ?, last_checked_at = ?, updated_at = ? WHERE id = ?',
                ['missing', Env::now(), Env::now(), $venv['id']]);
            echo "{$venv['name']}: MISSING ({$py})\n";
            continue;
        }
        [$rcV, $outV, $errV] = runCapture([$py, '--version']);
        $pythonVersion = null;
        if (preg_match('/Python\s+([0-9][0-9A-Za-z.\-]*)/', $outV . ' ' . $errV, $m)) {
            $pythonVersion = $m[1];
        }

        [$rcP, $outP] = runCapture([$py, '-m', 'pip', '--version']);
        $pipVersion = null;
        if ($rcP === 0 && preg_match('/pip\s+([0-9][0-9A-Za-z.\-]*)/', $outP, $m)) {
            $pipVersion = $m[1];
        }

        [$rcL, $listJson, $errL] = runCapture([$py, '-m', 'pip', 'list', '--format=json']);
        $pkgs = json_decode($listJson, true);
        if (!is_array($pkgs)) {
            Db::run('UPDATE pn_venv SET status = ?, last_checked_at = ?, updated_at = ? WHERE id = ?',
                ['error', Env::now(), Env::now(), $venv['id']]);
            echo "{$venv['name']}: pip list failed — {$errL}\n";
            continue;
        }

        [$rcO, $outdatedJson] = runCapture([$py, '-m', 'pip', 'list', '--outdated',
            '--format=json', '--disable-pip-version-check', '--retries', '0', '--timeout', '5']);
        $latest = [];
        foreach (json_decode($outdatedJson, true) ?: [] as $o) {
            $latest[strtolower((string) $o['name'])] = (string) $o['latest_version'];
        }

        Db::tx(static function () use ($venv, $pkgs, $latest): void {
            Db::run('DELETE FROM pn_venv_package WHERE venv_id = ?', [$venv['id']]);
            foreach ($pkgs as $pkg) {
                $pname = strtolower((string) $pkg['name']);
                Db::run(
                    'INSERT INTO pn_venv_package (venv_id, name, version, latest_version, updated_at)
                     VALUES (?,?,?,?,?)',
                    [$venv['id'], $pname, (string) $pkg['version'], $latest[$pname] ?? null, Env::now()]
                );
            }
        });

        $status = $latest === [] ? 'healthy' : 'outdated';
        Db::run(
            'UPDATE pn_venv SET python_version = ?, pip_version = ?, status = ?,
                last_checked_at = ?, updated_at = ? WHERE id = ?',
            [$pythonVersion, $pipVersion, $status, Env::now(), Env::now(), $venv['id']]
        );
        echo "{$venv['name']}: {$pythonVersion}; " . count($pkgs) . ' packages, '
            . count($latest) . " outdated\n";
    }
    return 0;
}

function cmdVenvList(array $opts): int
{
    $name = (string) ($opts['name'] ?? '');
    if ($name === '') {
        $venvs = Db::all('SELECT * FROM pn_venv ORDER BY is_default DESC, name');
        if ($venvs === []) {
            echo "no venvs registered — create one:\n"
                . "  php app/ops.php venv:create --name=NAME --path=DIR --python=/opt/alt/python311/bin/python3\n"
                . "or register an existing one:\n"
                . "  php app/ops.php venv:register --name=NAME --path=DIR\n";
            return 0;
        }
        table(['NAME', 'PATH', 'DEFAULT', 'STATUS', 'PYTHON'], array_map(
            static fn (array $v): array => [
                (string) $v['name'],
                (string) $v['path'],
                ((int) $v['is_default'] === 1) ? 'yes' : '',
                (string) $v['status'],
                (string) ($v['python_version'] ?? '—'),
            ],
            $venvs
        ));
        return 0;
    }
    $venv = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$name]);
    if (!$venv) {
        fwrite(STDERR, "unknown venv '{$name}'\n");
        return 1;
    }
    $rows = Db::all('SELECT * FROM pn_venv_package WHERE venv_id = ? ORDER BY name', [$venv['id']]);
    if ($rows === []) {
        echo "no package inventory yet — run: php app/ops.php venv:check --name={$name}\n";
        return 0;
    }
    table(['PACKAGE', 'VERSION', 'LATEST'], array_map(
        static fn (array $r): array => [
            (string) $r['name'], (string) $r['version'], (string) ($r['latest_version'] ?? ''),
        ],
        $rows
    ));
    return 0;
}

function cmdVenvInstall(array $opts, array $pos): int
{
    $name = (string) ($opts['name'] ?? '');
    $venv = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$name]);
    if (!$venv) {
        fwrite(STDERR, "unknown venv '{$name}'\n");
        return 1;
    }
    if ($pos === []) {
        fwrite(STDERR, "usage: venv:install --name=NAME pkg[==ver] ...\n");
        return 64;
    }
    $path = rtrim((string) $venv['path'], '/');
    $py = is_file($path . '/bin/python') ? $path . '/bin/python' : $path . '/bin/python3';
    $cmd = array_merge([$py, '-m', 'pip', 'install'], $pos);
    echo '$ ' . implode(' ', array_map('escapeshellarg', $cmd)) . "\n";
    $rc = procRunPassthrough($cmd);
    echo "pip exited with {$rc}\n";
    if ($rc === 0) {
        $opts2 = ['name' => $name];
        cmdVenvCheck($opts2);
    }
    return $rc === 0 ? 0 : 1;
}

function cmdVenvUninstall(array $opts, array $pos): int
{
    $name = (string) ($opts['name'] ?? '');
    $venv = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$name]);
    if (!$venv) {
        fwrite(STDERR, "unknown venv '{$name}'\n");
        return 1;
    }
    if ($pos === []) {
        fwrite(STDERR, "usage: venv:uninstall --name=NAME pkg\n");
        return 64;
    }
    $path = rtrim((string) $venv['path'], '/');
    $py = is_file($path . '/bin/python') ? $path . '/bin/python' : $path . '/bin/python3';
    $cmd = array_merge([$py, '-m', 'pip', 'uninstall', '-y'], $pos);
    echo '$ ' . implode(' ', array_map('escapeshellarg', $cmd)) . "\n";
    $rc = procRunPassthrough($cmd);
    echo "pip exited with {$rc}\n";
    if ($rc === 0) {
        cmdVenvCheck(['name' => $name]);
    }
    return $rc === 0 ? 0 : 1;
}

/** Upgrade every package pip reports as outdated. */
function cmdVenvUpgrade(array $opts): int
{
    $name = (string) ($opts['name'] ?? '');
    $venv = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$name]);
    if (!$venv) {
        fwrite(STDERR, "unknown venv '{$name}'\n");
        return 1;
    }
    $path = rtrim((string) $venv['path'], '/');
    $py = is_file($path . '/bin/python') ? $path . '/bin/python' : $path . '/bin/python3';
    if (!is_file($py)) {
        fwrite(STDERR, "interpreter missing at {$py}\n");
        return 1;
    }

    [$rc, $out, $err] = runCapture([$py, '-m', 'pip', 'list', '--outdated', '--format=json',
        '--disable-pip-version-check']);
    if ($rc !== 0) {
        fwrite(STDERR, "pip list --outdated failed: " . trim($err . ' ' . $out) . "\n");
        return 1;
    }
    $outdated = json_decode($out, true);
    if (!is_array($outdated) || $outdated === []) {
        echo "all packages in '{$name}' are up to date\n";
        return 0;
    }
    $names = array_map(static fn (array $p): string => (string) $p['name'], $outdated);
    echo 'outdated: ' . implode(', ', $names) . "\n";
    $cmd = array_merge([$py, '-m', 'pip', 'install', '--upgrade'], $names);
    echo '$ ' . implode(' ', array_map('escapeshellarg', $cmd)) . "\n";
    $rc = procRunPassthrough($cmd);
    echo "pip exited with {$rc}\n";
    if ($rc === 0) {
        cmdVenvCheck(['name' => $name]);
    }
    return $rc === 0 ? 0 : 1;
}

function cmdVenvRequirements(array $opts): int
{
    $name = (string) ($opts['name'] ?? '');
    $file = (string) ($opts['file'] ?? '');
    $venv = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$name]);
    if (!$venv) {
        fwrite(STDERR, "unknown venv '{$name}'\n");
        return 1;
    }
    if (!is_file($file)) {
        fwrite(STDERR, "requirements file not found: {$file}\n");
        return 1;
    }
    $path = rtrim((string) $venv['path'], '/');
    $py = is_file($path . '/bin/python') ? $path . '/bin/python' : $path . '/bin/python3';
    $cmd = [$py, '-m', 'pip', 'install', '-r', $file];
    echo '$ ' . implode(' ', array_map('escapeshellarg', $cmd)) . "\n";
    $rc = procRunPassthrough($cmd);
    echo "pip exited with {$rc}\n";
    if ($rc === 0) {
        cmdVenvCheck(['name' => $name]);
    }
    return $rc === 0 ? 0 : 1;
}

function cmdVenvCreate(array $opts): int
{
    $name = (string) ($opts['name'] ?? '');
    $path = rtrim((string) ($opts['path'] ?? ''), '/');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $name)) {
        fwrite(STDERR, "usage: venv:create --name=NAME --path=DIR [--python=BIN]\n");
        return 64;
    }
    if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || $path === '/') {
        fwrite(STDERR, "path must be an absolute directory\n");
        return 64;
    }
    if (Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        fwrite(STDERR, "venv '{$name}' is already registered\n");
        return 1;
    }
    if (is_dir($path) && count((array) scandir($path)) > 2) {
        fwrite(STDERR, "{$path} already exists and is not empty\n");
        return 1;
    }
    if (file_exists($path) && !is_dir($path)) {
        fwrite(STDERR, "{$path} exists and is not a directory\n");
        return 1;
    }

    $python = (string) ($opts['python'] ?? Env::cfg('python_bin', 'python3'));
    echo "$ " . implode(' ', array_map('escapeshellarg', [$python, '-m', 'venv', $path])) . "\n";
    [$rc, $out, $err] = runCapture([$python, '-m', 'venv', $path]);
    if ($out !== '') {
        echo $out;
    }
    if ($rc !== 0) {
        fwrite(STDERR, "venv creation failed: " . trim($err . ' ' . $out) . "\n");
        return 1;
    }
    if (!is_file($path . '/bin/python3') && !is_file($path . '/bin/python')) {
        fwrite(STDERR, "venv created without an interpreter at {$path}/bin\n");
        return 1;
    }

    $hasDefault = (int) (Db::one('SELECT COUNT(*) AS n FROM pn_venv WHERE is_default = 1')['n'] ?? 0);
    Db::run(
        'INSERT INTO pn_venv (name, path, is_default, status, created_at, updated_at)
         VALUES (?,?,?,?,?,?)',
        [$name, $path, $hasDefault === 0 ? 1 : 0, 'healthy', Env::now(), Env::now()]
    );
    echo "venv: {$name} → {$path}" . ($hasDefault === 0 ? ' (default)' : '') . "\n";
    cmdVenvCheck(['name' => $name]);
    return 0;
}

/**
 * Register a virtualenv that already exists on disk (the web console calls
 * this "register"; the CLI only had venv:create, which refuses non-empty dirs).
 */
function cmdVenvRegister(array $opts): int
{
    $name = (string) ($opts['name'] ?? '');
    $path = rtrim((string) ($opts['path'] ?? ''), '/');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $name)) {
        fwrite(STDERR, "usage: venv:register --name=NAME --path=DIR [--default]\n");
        return 64;
    }
    if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || $path === '/') {
        fwrite(STDERR, "path must be an absolute directory\n");
        return 64;
    }
    if (Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        fwrite(STDERR, "venv '{$name}' is already registered\n");
        return 1;
    }
    if (!is_file($path . '/bin/python') && !is_file($path . '/bin/python3')) {
        fwrite(STDERR, "no interpreter at {$path}/bin — is that a virtualenv?\n"
            . "  create one first: php app/ops.php venv:create --name={$name} "
            . "--path={$path} --python=/opt/alt/python311/bin/python3\n");
        return 1;
    }

    $isDefault = !empty($opts['default']);
    if (!$isDefault) {
        $isDefault = (int) (Db::one('SELECT COUNT(*) AS n FROM pn_venv WHERE is_default = 1')['n'] ?? 0) === 0;
    }
    if ($isDefault) {
        Db::run('UPDATE pn_venv SET is_default = 0');
    }
    Db::run(
        'INSERT INTO pn_venv (name, path, is_default, status, created_at, updated_at)
         VALUES (?,?,?,?,?,?)',
        [$name, $path, $isDefault ? 1 : 0, 'healthy', Env::now(), Env::now()]
    );
    echo "venv: {$name} → {$path}" . ($isDefault ? ' (default)' : '') . "\n";
    cmdVenvCheck(['name' => $name]);
    return 0;
}

function cmdVenvDelete(array $opts): int
{
    $name = (string) ($opts['name'] ?? '');
    $venv = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$name]);
    if (!$venv) {
        fwrite(STDERR, "unknown venv '{$name}'\n");
        return 1;
    }
    $id = (int) $venv['id'];
    $refs = Db::one(
        'SELECT (SELECT COUNT(*) FROM pn_dag WHERE default_venv_id = ?) +
                (SELECT COUNT(*) FROM pn_task WHERE venv_id = ?) AS n',
        [$id, $id]
    );
    if ((int) ($refs['n'] ?? 0) > 0) {
        fwrite(STDERR, "refusing to remove '{$name}': still used by "
            . (int) $refs['n'] . " dag/task reference(s)\n");
        return 1;
    }
    Db::run('DELETE FROM pn_venv WHERE id = ?', [$id]);
    echo "venv '{$name}' unregistered (files at {$venv['path']} were left in place)\n";
    return 0;
}

function cmdPrune(array $opts): int
{
    $days = (int) ($opts['days'] ?? Settings::int('retention.days', 30));
    if ($days < 1) {
        fwrite(STDERR, "usage: prune [--days=30] [--dry-run]\n");
        return 64;
    }
    $dry = isset($opts['dry-run']);
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

    $runs = Db::all(
        'SELECT id FROM pn_dag_run
          WHERE finished_at IS NOT NULL AND finished_at < ?',
        [$cutoff]
    );
    if ($runs === []) {
        echo "nothing to prune (nothing finished before {$cutoff})\n";
        return 0;
    }
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $runs);
    $in  = implode(',', $ids);

    $files = Db::all(
        "SELECT DISTINCT log_path FROM pn_task_run
          WHERE dag_run_id IN ({$in}) AND log_path IS NOT NULL AND log_path != ''"
    );
    $removed = 0;
    foreach ($files as $f) {
        $path = (string) $f['log_path'];
        if (str_contains($path, '..') || !str_starts_with($path, Env::logs() . '/')) {
            continue; // only ever delete inside logs/
        }
        if (!$dry && is_file($path) && @unlink($path)) {
            $removed++;
        }
    }

    $tr = Db::run("DELETE FROM pn_task_run WHERE dag_run_id IN ({$in})")->rowCount();
    $dr = Db::run("DELETE FROM pn_dag_run WHERE id IN ({$in})")->rowCount();

    $mode = $dry ? ' (dry run — nothing deleted)' : '';
    echo "prune --days={$days}{$mode}\n";
    echo "  dag runs:  {$dr}\n";
    echo "  task runs: {$tr}\n";
    echo "  log files: " . ($dry ? count($files) : $removed) . "\n";
    return 0;
}

// ---------------------------------------------------------------- helpers

function table(array $headers, array $rows): void
{
    $widths = array_map('strlen', $headers);
    foreach ($rows as $row) {
        foreach ($row as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
        }
    }
    $fmt = static function (array $cells) use ($widths): string {
        $parts = [];
        foreach ($cells as $i => $cell) {
            $parts[] = str_pad((string) $cell, $widths[$i]);
        }
        return rtrim(implode('  ', $parts));
    };
    echo $fmt($headers), "\n";
    echo str_repeat('-', min(120, array_sum($widths) + 2 * (count($widths) - 1))), "\n";
    foreach ($rows as $row) {
        echo $fmt($row), "\n";
    }
}

/** @param string[] $cmd @return array{0:int,1:string,2:string} */
function runCapture(array $cmd): array
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes, PN_BASE);
    if (!is_resource($proc)) {
        return [127, '', 'cannot start command'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return [$rc, $out, $err];
}

/** @param string[] $cmd */
function procRunPassthrough(array $cmd): int
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes, PN_BASE);
    if (!is_resource($proc)) {
        return 127;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    while (true) {
        $o = (string) stream_get_contents($pipes[1]);
        $e = (string) stream_get_contents($pipes[2]);
        if ($o !== '') {
            fwrite(STDOUT, $o);
        }
        if ($e !== '') {
            fwrite(STDERR, $e);
        }
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        usleep(50000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}


// ---------------------------------------------------------------- updater

function cmdUpdateCheck(): int
{
    $r = Updater::check(true);
    if (empty($r['ok'])) {
        fwrite(STDERR, "update: " . ($r['error'] ?? 'unknown error') . "\n");
        return 1;
    }
    $cur = $r['current'];
    $rem = $r['remote'];
    $short = static fn (?string $sha): string => $sha ? substr($sha, 0, 7) : 'unknown';
    echo "channel: {$r['channel']} ({$r['repo']})\n";
    echo "current: {$cur['version']} · " . $short($cur['commit']) . "\n";
    echo "remote:  {$rem['version']} · " . $short($rem['commit'] ?? null) . "\n";
    if (!empty($rem['date'])) {
        echo "date:    {$rem['date']}\n";
    }
    if (!empty($rem['title'])) {
        echo "title:   {$rem['title']}\n";
    }
    echo "update:  " . (!empty($r['update']) ? "available" : "up to date") . "\n";
    return 0;
}

function cmdUpdateApply(array $opts): int
{
    if (($reason = Updater::applyUnavailableReason()) !== null) {
        fwrite(STDERR, "update: {$reason}\n");
        return 1;
    }
    if (!Updater::lock('apply')) {
        fwrite(STDERR, "another update is already running\n");
        return 1;
    }

    $archive = (string) ($opts['archive'] ?? '');
    $meta = ['channel' => 'upload'];

    if ($archive === '') {
        $check = Updater::check(true);
        if (empty($check['ok'])) {
            fwrite(STDERR, "update: " . ($check['error'] ?? 'check failed') . "\n");
            return 1;
        }
        if (empty($check['update'])) {
            echo "already up to date ({$check['current']['version']})\n";
            return 0;
        }
        [$archive, $err] = Updater::download($check['remote']);
        if ($err !== null || $archive === null) {
            fwrite(STDERR, "download failed: {$err}\n");
            return 1;
        }
        $meta = [
            'version' => $check['remote']['version'],
            'commit'  => $check['remote']['commit'],
            'channel' => $check['channel'],
        ];
        echo "downloaded: {$archive}\n";
    }

    if (!is_file($archive)) {
        fwrite(STDERR, "archive not found: {$archive}\n");
        return 1;
    }

    echo "applying {$archive} to " . PN_BASE . " …\n";
    $res = Updater::applyArchiveTo($archive, PN_BASE, true, $meta);
    if (empty($res['ok'])) {
        fwrite(STDERR, "update failed: " . ($res['error'] ?? 'unknown') . "\n");
        foreach (($res['failed'] ?? []) as $f) {
            fwrite(STDERR, "  failed: {$f}\n");
        }
        return 1;
    }
    $meta = is_array($res['meta'] ?? null) ? $res['meta'] : [];
    if ($meta !== []) {
        Updater::saveState($meta + ['applied_at' => Env::now()]);
    }
    @unlink(Updater::dir() . '/check.json');
    echo "files: {$res['copied']} copied, {$res['skipped']} protected/skipped\n";
    echo "backup: {$res['backup']}\n";
    if (($res['migrate_out'] ?? '') !== '') {
        echo $res['migrate_out'], "\n";
    }
    echo "migrations: rc=" . (string) ($res['migrate_rc'] ?? 'n/a') . "\n";
    Audit::record('cli', 'updater.applied',
        (string) (Updater::state()['version'] ?? PN_VERSION) . ' via ' . ($meta['channel'] ?? 'upload'));
    return 0;
}

function cmdUpdateRollback(): int
{
    if (($reason = Updater::applyUnavailableReason()) !== null) {
        fwrite(STDERR, "rollback: {$reason}\n");
        return 1;
    }
    if (!Updater::lock('rollback')) {
        fwrite(STDERR, "another update is already running\n");
        return 1;
    }
    $res = Updater::rollback();
    if (empty($res['ok'])) {
        fwrite(STDERR, "rollback failed: " . ($res['error'] ?? 'unknown') . "\n");
        return 1;
    }
    echo "restored: {$res['restored']} files from backup\n";
    if (($res['migrate_out'] ?? '') !== '') {
        echo $res['migrate_out'], "\n";
    }
    Audit::record('cli', 'updater.rolled_back', (string) ($res['meta']['dir'] ?? 'backup'));
    return 0;
}
