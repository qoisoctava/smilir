<?php
/**
 * smilir web console — write actions & management pages.
 * Included from index.php; every handler checks CSRF + role itself.
 */
declare(strict_types=1);

// ================================================================== POST

function handle_post(string $page, array $segments): void
{
    if ($page === 'account' && ($segments[1] ?? '') === 'password') {
        post_account_password();
        return;
    }
    if ($page === 'dags') {
        $sub = $segments[1] ?? '';
        if ($sub === 'new') {
            post_dag_save(null);
            return;
        }
        if ($sub === 'import') {
            if (($segments[2] ?? '') === 'confirm') {
                post_import_confirm();
            } else {
                post_import_upload();
            }
            return;
        }
        $verb = $segments[2] ?? '';
        if ($verb === 'edit') {
            post_dag_save($sub);
        } elseif ($verb === 'delete') {
            post_dag_delete($sub);
        } elseif ($verb === 'trigger') {
            post_dag_trigger($sub);
        } elseif ($verb === 'pause') {
            post_dag_pause($sub, false);
        } elseif ($verb === 'resume') {
            post_dag_pause($sub, true);
        } elseif ($verb === 'star') {
            post_dag_star($sub);
        }
        return;
    }
    if ($page === 'task') {
        $id = (int) ($segments[1] ?? 0);
        $verb = $segments[2] ?? '';
        if ($verb === 'clear') {
            post_task_clear($id);
        } elseif ($verb === 'mark-success') {
            post_task_mark($id);
        }
        return;
    }
    if ($page === 'python') {
        $sub = $segments[1] ?? '';
        if ($sub === 'new') {
            post_venv_new();
            return;
        }
        if ($sub === 'create') {
            post_venv_create();
            return;
        }
        $verb = $segments[2] ?? '';
        if ($verb === 'check') {
            post_venv_check($sub);
        } elseif ($verb === 'install') {
            post_venv_install($sub);
        } elseif ($verb === 'uninstall') {
            post_venv_uninstall($sub);
        } elseif ($verb === 'upgrade') {
            post_venv_upgrade($sub);
        } elseif ($verb === 'requirements') {
            post_venv_requirements($sub);
        } elseif ($verb === 'delete') {
            post_venv_delete($sub);
        }
        return;
    }
    if ($page === 'admin' && ($segments[1] ?? '') === 'users') {
        $verb = $segments[2] ?? '';
        if ($verb === 'new') {
            post_user_new();
        } elseif (ctype_digit($verb)) {
            $what = $segments[3] ?? '';
            if ($what === 'toggle') {
                post_user_toggle((int) $verb);
            } elseif ($what === 'password') {
                post_user_password((int) $verb);
            } elseif ($what === 'role') {
                post_user_role((int) $verb);
            }
        }
        return;
    }
    if ($page === 'admin' && ($segments[1] ?? '') === 'updates') {
        $verb = $segments[2] ?? '';
        if ($verb === 'check') {
            post_update_check();
        } elseif ($verb === 'apply') {
            post_update_apply();
        } elseif ($verb === 'rollback') {
            post_update_rollback();
        } elseif ($verb === 'upload') {
            post_update_upload();
        }
        return;
    }
    if ($page === 'admin' && ($segments[1] ?? '') === 'settings') {
        if (($segments[2] ?? '') === 'purge') {
            post_settings_purge();
        } else {
            post_settings_save();
        }
        return;
    }
    redirect('/');
}

// ================================================================== dags

/** @return array{0:bool,1:?array,2:?string} */
function build_dag_from_post(array $post, ?string $existingName): array
{
    $name = $existingName ?? slug((string) ($post['name'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $name)) {
        return [false, null, 'invalid Dag name (lowercase letters, digits, dot, dash)'];
    }
    if ($existingName === null && Db::one('SELECT id FROM pn_dag WHERE name = ?', [$name])) {
        return [false, null, "a Dag named '{$name}' already exists"];
    }

    $scheduleRaw = trim((string) ($post['schedule'] ?? ''));
    $type = 'cron';
    $schedule = $scheduleRaw;
    if ($scheduleRaw === '') {
        return [false, null, 'schedule is required'];
    }
    if (str_starts_with($scheduleRaw, 'interval:')) {
        $type = 'interval';
        $schedule = (string) max(1, (int) substr($scheduleRaw, 9));
    } elseif (!Cron::isValid($scheduleRaw)) {
        return [false, null, "invalid cron expression '{$scheduleRaw}'"];
    }

    $venvName = trim((string) ($post['venv'] ?? ''));
    $venvId = null;
    if ($venvName !== '') {
        $v = Db::one('SELECT id FROM pn_venv WHERE name = ?', [$venvName]);
        if (!$v) {
            return [false, null, "unknown venv '{$venvName}'"];
        }
        $venvId = (int) $v['id'];
    }

    $names  = (array) ($post['tn'] ?? []);
    $funcs  = (array) ($post['tf'] ?? []);
    $cmds   = (array) ($post['tc'] ?? []);
    $deps   = (array) ($post['td'] ?? []);
    $retry  = (array) ($post['tr'] ?? []);
    $delay  = (array) ($post['tdl'] ?? []);
    $time   = (array) ($post['tt'] ?? []);
    $mailRaw = (array) ($post['tem'] ?? []);
    $mail = [];
    $rowIdx = 0;
    foreach ($mailRaw as $flag) {
        if ((string) $flag === '0') {
            $mail[$rowIdx] = 0;
            $rowIdx++;
        } else {
            $mail[$rowIdx - 1] = 1;
        }
    }

    $tasks = [];
    foreach ($names as $i => $rawName) {
        $tname = strtolower(trim((string) $rawName));
        if ($tname === '') {
            continue; // blank row
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $tname)) {
            return [false, null, "invalid task name '{$tname}'"];
        }
        if (isset($tasks[$tname])) {
            return [false, null, "duplicate task '{$tname}'"];
        }
        $func = trim((string) ($funcs[$i] ?? ''));
        $cmd  = trim((string) ($cmds[$i] ?? ''));
        if ($func !== '' && $cmd !== '') {
            return [false, null, "task '{$tname}': fill func OR command, not both"];
        }
        if ($func === '' && $cmd === '') {
            return [false, null, "task '{$tname}' needs a func or command"];
        }
        if ($func !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)+$/', $func)) {
            return [false, null, "task '{$tname}': func must look like module.function"];
        }
        $taskDeps = [];
        foreach (explode(',', (string) ($deps[$i] ?? '')) as $d) {
            $d = strtolower(trim($d));
            if ($d !== '') {
                $taskDeps[] = $d;
            }
        }
        $tasks[$tname] = [
            'name'             => $tname,
            'runner'           => $func !== '' ? 'python' : 'shell',
            'func'             => $func !== '' ? $func : null,
            'command'          => $cmd,
            'venv_id'          => $venvId,
            'depends_on'       => array_values(array_unique($taskDeps)),
            'retries'          => max(0, min(10, (int) ($retry[$i] ?? 0))),
            'retry_delay'      => max(0, min(86400, (int) ($delay[$i] ?? 300))),
            'timeout'          => max(0, min(86400, (int) ($time[$i] ?? 0))),
            'email_on_failure' => !empty($mail[$i]) ? 1 : 0,
        ];
    }
    if ($tasks === []) {
        return [false, null, 'at least one task is required'];
    }

    foreach ($tasks as $tname => $t) {
        foreach ($t['depends_on'] as $d) {
            if (!isset($tasks[$d])) {
                return [false, null, "task '{$tname}' depends on unknown task '{$d}'"];
            }
        }
    }
    // cycle check — iterative DFS with a color map (0 new, 1 in-progress, 2 done)
    $color = [];
    foreach (array_keys($tasks) as $start) {
        if (($color[$start] ?? 0) !== 0) {
            continue;
        }
        $stack = [[$start, 0]];
        $color[$start] = 1;
        while ($stack) {
            $top = count($stack) - 1;
            [$node, $i] = $stack[$top];
            $deps = $tasks[$node]['depends_on'];
            if ($i >= count($deps)) {
                $color[$node] = 2;
                array_pop($stack);
                continue;
            }
            $stack[$top][1]++;
            $child = $deps[$i];
            $state = $color[$child] ?? 0;
            if ($state === 1) {
                return [false, null, "dependency cycle: {$node} → {$child}"];
            }
            if ($state === 0) {
                $color[$child] = 1;
                $stack[] = [$child, 0];
            }
        }
    }

    return [true, [
        'name'            => $name,
        'schedule_type'   => $type,
        'schedule'        => $schedule,
        'max_concurrency' => max(1, min(16, (int) ($post['max_concurrency'] ?? 1))),
        'venv'            => $venvName !== '' ? $venvName : null,
        'default_venv_id' => $venvId,
        'tasks'           => array_values($tasks),
    ], null];
}

function post_dag_save(?string $existingName): void
{
    require_role('admin');
    csrf_check();

    [$ok, $dag, $error] = build_dag_from_post($_POST, $existingName);
    $back = $existingName ? "/dags/{$existingName}/edit" : '/dags/new';
    if (!$ok) {
        flash('Cannot save: ' . (string) $error, 'bad');
        redirect($back);
    }

    $path = Env::path('dags') . '/' . $dag['name'] . '.py';
    $backup = null;
    if (is_file($path)) {
        $dir = Env::path('storage/backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $backup = $dir . '/' . $dag['name'] . '-' . date('Ymd-His') . '.py';
        copy($path, $backup);
    }

    $tmp = $path . '.tmp';
    file_put_contents($tmp, DagWriter::toPython($dag));
    rename($tmp, $path);

    $res = DagLoader::loadFile($path);
    if (empty($res['ok'])) {
        if ($backup && is_file($backup)) {
            rename($backup, $path);
        } else {
            @unlink($path);
        }
        audit('dag.save.failed', $dag['name']);
        flash('Loader rejected the file: ' . (string) ($res['error'] ?? 'unknown'), 'bad');
        redirect($back);
    }

    DagLoader::sync([$res['dag']]);
    audit('dag.saved', $dag['name']);
    flash('Dag saved and validated.');
    redirect('/dags/' . rawurlencode($dag['name']));
}

function post_dag_delete(string $name): void
{
    require_role('admin');
    csrf_check();
    $dag = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        flash('No such Dag.', 'bad');
        redirect('/dags');
    }
    if (is_file((string) $dag['source_path'])) {
        $dir = Env::path('storage/backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        copy((string) $dag['source_path'], $dir . '/' . $name . '-deleted-' . date('Ymd-His') . '.py');
        @unlink((string) $dag['source_path']);
    }
    Db::run('DELETE FROM pn_dag WHERE id = ?', [$dag['id']]);
    audit('dag.deleted', $name);
    flash("Dag '{$name}' deleted (runs removed, file backed up).");
    redirect('/dags');
}

function post_dag_trigger(string $name): void
{
    require_role('operator');
    csrf_check();
    $dag = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        flash('No such Dag.', 'bad');
        redirect('/dags');
    }
    $runId = 'manual__' . date('Y-m-d\TH:i:s');
    $id = Scheduler::createRun((int) $dag['id'], $runId);
    Db::run('UPDATE pn_dag SET last_run_at = ? WHERE id = ?', [Env::now(), $dag['id']]);
    audit('dag.triggered', $name . ' run#' . $id);
    flash("Run created ({$runId}) — the next tick dispatches it.");
    redirect('/dags/' . rawurlencode($name));
}

function post_dag_pause(string $name, bool $resume): void
{
    require_role('operator');
    csrf_check();
    $dag = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        flash('No such Dag.', 'bad');
        redirect('/dags');
    }
    Db::run('UPDATE pn_dag SET is_active = ?, updated_at = ? WHERE id = ?',
        [$resume ? 1 : 0, Env::now(), $dag['id']]);
    audit($resume ? 'dag.resumed' : 'dag.paused', $name);
    flash($resume ? "Dag '{$name}' resumed." : "Dag '{$name}' paused.");
    redirect('/dags/' . rawurlencode($name));
}

function post_dag_star(string $name): void
{
    csrf_check();
    $dag = Db::one('SELECT id, is_favorite FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        flash('No such Dag.', 'bad');
        redirect('/dags');
    }
    $new = (int) $dag['is_favorite'] === 1 ? 0 : 1;
    Db::run('UPDATE pn_dag SET is_favorite = ? WHERE id = ?', [$new, $dag['id']]);
    audit($new === 1 ? 'dag.starred' : 'dag.unstarred', $name);
    flash($new === 1 ? "Dag '{$name}' pinned to the dashboard." : "Dag '{$name}' unpinned.");
    redirect(($_POST['from'] ?? '') === 'list'
        ? '/dags'
        : '/dags/' . rawurlencode($name));
}

// ================================================================== tasks

function post_task_clear(int $id): void
{
    require_role('operator');
    csrf_check();
    try {
        $res = TaskOps::clear($id);
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'bad');
        redirect('/runs');
    }
    audit('task.cleared', "task_run #{$id} ({$res['task']})");
    flash("Cleared {$res['count']} task run(s) — the next tick re-dispatches.");
    redirect('/runs/' . $res['dag_run_id']);
}

function post_task_mark(int $id): void
{
    require_role('operator');
    csrf_check();
    try {
        $res = TaskOps::markSuccess($id);
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'bad');
        redirect('/runs');
    }
    audit('task.marked_success', "task_run #{$id} ({$res['task']})");
    flash("Marked success; {$res['count']} downstream task(s) requeued.");
    redirect('/runs/' . $res['dag_run_id']);
}

// ================================================================== import

function post_import_upload(): void
{
    require_role('admin');
    csrf_check();
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('Choose a .py file to upload.', 'bad');
        redirect('/dags/import');
    }
    if ((int) $file['size'] > 262144) {
        flash('File too large (max 256 KB).', 'bad');
        redirect('/dags/import');
    }
    if (strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'py') {
        flash('Only .py DAG modules are accepted.', 'bad');
        redirect('/dags/import');
    }
    $dir = Env::path('storage/imports');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $dir . '/import-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.py';
    if (!move_uploaded_file((string) $file['tmp_name'], $tmp)) {
        flash('Upload failed (permissions?).', 'bad');
        redirect('/dags/import');
    }

    $res = DagLoader::loadFile($tmp);
    if (empty($res['ok'])) {
        @unlink($tmp);
        audit('dag.import.rejected', (string) $file['name']);
        flash('Invalid DAG: ' . (string) ($res['error'] ?? 'unknown'), 'bad');
        redirect('/dags/import');
    }
    $_SESSION['import'] = [
        'path'     => $tmp,
        'dag'      => $res['dag'],
        'warnings' => $res['warnings'] ?? [],
        'source'   => (string) $file['name'],
    ];
    redirect('/dags/import');
}

function post_import_confirm(): void
{
    require_role('admin');
    csrf_check();
    $import = $_SESSION['import'] ?? null;
    if (!$import) {
        flash('No import in progress.', 'bad');
        redirect('/dags/import');
    }
    unset($_SESSION['import']);
    $dag = $import['dag'];
    $name = (string) $dag['name'];
    $target = Env::path('dags') . '/' . $name . '.py';
    $backup = null;
    if (is_file($target)) {
        $dir = Env::path('storage/backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $backup = $dir . '/' . $name . '-preimport-' . date('Ymd-His') . '.py';
        copy($target, $backup);
    }
    if (!rename((string) $import['path'], $target)) {
        flash('Could not move the file into dags/.', 'bad');
        redirect('/dags/import');
    }
    $res = DagLoader::loadFile($target);
    if (empty($res['ok'])) {
        if ($backup && is_file($backup)) {
            rename($backup, $target);
        } else {
            @unlink($target);
        }
        audit('dag.import.failed', $name);
        flash('Import rejected at commit: ' . (string) ($res['error'] ?? 'unknown'), 'bad');
        redirect('/dags/import');
    }
    DagLoader::sync([$res['dag']]);
    audit('dag.imported', $name . ' ← ' . (string) $import['source']);
    flash("Dag '{$name}' imported.");
    redirect('/dags/' . rawurlencode($name));
}

// ================================================================== venvs

function post_venv_new(): void
{
    require_role('admin');
    csrf_check();
    $name = slug((string) ($_POST['name'] ?? ''));
    $path = rtrim((string) ($_POST['path'] ?? ''), '/');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $name)) {
        flash('Invalid environment name.', 'bad');
        redirect('/python');
    }
    if (Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        flash("Environment '{$name}' already exists.", 'bad');
        redirect('/python');
    }
    if (!is_dir($path)
        || (!is_file($path . '/bin/python') && !is_file($path . '/bin/python3'))) {
        flash('That directory has no bin/python — check the path.', 'bad');
        redirect('/python');
    }
    $makeDefault = !empty($_POST['default']);
    if ($makeDefault) {
        Db::run('UPDATE pn_venv SET is_default = 0');
    }
    Db::run(
        'INSERT INTO pn_venv (name, path, is_default, status, created_at, updated_at)
         VALUES (?,?,?,?,?,?)',
        [$name, $path, $makeDefault ? 1 : 0, 'healthy', Env::now(), Env::now()]
    );
    audit('venv.registered', $name . ' ' . $path);
    flash("Environment '{$name}' registered.");
    redirect('/python/' . rawurlencode($name));
}

function post_venv_check(string $name): void
{
    require_role('admin');
    csrf_check();
    if (!Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        flash('No such environment.', 'bad');
        redirect('/python');
    }
    [$rc, $out] = run_ops_sync(['venv:check', '--name=' . $name], 120);
    audit('venv.checked', $name);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $summary = $lines ? end($lines) : 'no output';
    flash($rc === 0 ? ('Check: ' . $summary) : ('Check failed: ' . $summary), $rc === 0 ? 'ok' : 'bad');
    redirect('/python/' . rawurlencode($name));
}

function post_venv_install(string $name): void
{
    require_role('admin');
    csrf_check();
    if (!Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        flash('No such environment.', 'bad');
        redirect('/python');
    }
    $spec = trim((string) ($_POST['spec'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.\-]+(\s*(==|>=|<=|!=|~=|>|<)\s*[A-Za-z0-9_.\-!+]+)?$/', $spec)) {
        flash('Package spec looks invalid (allowed: name, name==1.2.3, name>=1.0).', 'bad');
        redirect('/python/' . rawurlencode($name));
    }
    spawn_ops(['venv:install', '--name=' . $name, $spec], venv_log_path($name));
    audit('venv.install.started', $name . ' ' . $spec);
    flash("Install started for '{$spec}' — refreshing the log below.");
    redirect('/python/' . rawurlencode($name) . '/log');
}

function post_venv_uninstall(string $name): void
{
    require_role('admin');
    csrf_check();
    if (!Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        flash('No such environment.', 'bad');
        redirect('/python');
    }
    $pkg = slug((string) ($_POST['pkg'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $pkg)) {
        flash('Invalid package name.', 'bad');
        redirect('/python/' . rawurlencode($name));
    }
    spawn_ops(['venv:uninstall', '--name=' . $name, $pkg], venv_log_path($name));
    audit('venv.uninstall.started', $name . ' ' . $pkg);
    flash("Uninstall started for '{$pkg}'.");
    redirect('/python/' . rawurlencode($name) . '/log');
}

function post_venv_upgrade(string $name): void
{
    require_role('admin');
    csrf_check();
    if (!Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        flash('No such environment.', 'bad');
        redirect('/python');
    }
    @unlink(venv_log_path($name));
    spawn_ops(['venv:upgrade', '--name=' . $name], venv_log_path($name));
    audit('venv.upgrade.started', $name);
    flash('Upgrade started — outdated packages are installed with pip --upgrade.');
    redirect('/python/' . rawurlencode($name) . '/log');
}

function post_venv_requirements(string $name): void
{
    require_role('admin');
    csrf_check();
    if (!Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        flash('No such environment.', 'bad');
        redirect('/python');
    }
    $file = $_FILES['requirements'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('Choose a requirements.txt file.', 'bad');
        redirect('/python/' . rawurlencode($name));
    }
    if ((int) $file['size'] > 262144) {
        flash('requirements.txt is too large (max 256 KB).', 'bad');
        redirect('/python/' . rawurlencode($name));
    }
    if (strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'txt') {
        flash('Only .txt requirement files are accepted.', 'bad');
        redirect('/python/' . rawurlencode($name));
    }
    $dir = Env::path('storage/imports');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $target = $dir . '/requirements-' . slug($name) . '-' . date('Ymd-His') . '.txt';
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        flash('Upload failed (permissions?).', 'bad');
        redirect('/python/' . rawurlencode($name));
    }
    @unlink(venv_log_path($name));
    spawn_ops(['venv:requirements', '--name=' . $name, '--file=' . $target], venv_log_path($name));
    audit('venv.requirements.started', $name . ' ← ' . (string) $file['name']);
    flash('Requirements install started — this can take a few minutes.');
    redirect('/python/' . rawurlencode($name) . '/log');
}

function post_venv_create(): void
{
    require_role('admin');
    csrf_check();
    $name = slug((string) ($_POST['name'] ?? ''));
    $path = rtrim(trim((string) ($_POST['path'] ?? '')), '/');
    $python = trim((string) ($_POST['python'] ?? '')) ?: (string) Env::cfg('python_bin', 'python3');

    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $name)) {
        flash('Invalid environment name.', 'bad');
        redirect('/python');
    }
    if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || $path === '/') {
        flash('Path must be an absolute directory (e.g. /home/USER/venvs/reporting).', 'bad');
        redirect('/python');
    }
    if (is_dir($path) && count((array) scandir($path)) > 2) {
        flash('That directory already exists and is not empty — register it instead.', 'bad');
        redirect('/python');
    }

    [$rc, $out] = run_ops_sync(['venv:create', '--name=' . $name, '--path=' . $path,
        '--python=' . $python], 120);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $summary = $lines ? end($lines) : 'no output';
    audit('venv.created', $name . ' ' . $path);
    flash($rc === 0 ? ('Environment created: ' . $summary) : ('Create failed: ' . $summary),
        $rc === 0 ? 'ok' : 'bad');
    redirect($rc === 0 ? '/python/' . rawurlencode($name) : '/python');
}

function post_venv_delete(string $name): void
{
    require_role('admin');
    csrf_check();
    if (!Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        flash('No such environment.', 'bad');
        redirect('/python');
    }
    [$rc, $out] = run_ops_sync(['venv:delete', '--name=' . $name], 60);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $summary = $lines ? end($lines) : 'no output';
    audit('venv.deleted', $name);
    flash($rc === 0 ? $summary : ('Not removed: ' . $summary), $rc === 0 ? 'ok' : 'bad');
    redirect('/python');
}

// ================================================================== users
function post_user_new(): void
{
    require_role('admin');
    csrf_check();
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'viewer');

    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,31}$/', $username)) {
        flash('Invalid username (3-32 chars, lowercase).', 'bad');
        redirect('/admin');
    }
    if (Db::one('SELECT id FROM pn_user WHERE username = ?', [$username])) {
        flash('That username is taken.', 'bad');
        redirect('/admin');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Invalid email address.', 'bad');
        redirect('/admin');
    }
    if (strlen($password) < 8) {
        flash('Password must be at least 8 characters.', 'bad');
        redirect('/admin');
    }
    if (PasswordPolicy::isWeak($username, $password)) {
        flash('That password is too weak — use at least 8 characters and not the username.', 'bad');
        redirect('/admin');
    }
    if (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
        $role = 'viewer';
    }
    Db::run(
        'INSERT INTO pn_user (username, email, password_hash, role, is_active, created_at)
         VALUES (?,?,?,?,1,?)',
        [$username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT),
         $role, Env::now()]
    );
    audit('user.invited', $username . ' (' . $role . ')');
    flash("User '{$username}' created as {$role}.");
    redirect('/admin');
}

function post_user_toggle(int $id): void
{
    require_role('admin');
    csrf_check();
    $user = Db::one('SELECT * FROM pn_user WHERE id = ?', [$id]);
    if (!$user) {
        flash('No such user.', 'bad');
        redirect('/admin');
    }
    if ((int) $user['id'] === (int) (current_user()['id'] ?? 0)) {
        flash('You cannot deactivate your own account.', 'bad');
        redirect('/admin');
    }
    $active = (int) $user['is_active'] === 1 ? 0 : 1;
    Db::run('UPDATE pn_user SET is_active = ? WHERE id = ?', [$active, $id]);
    audit($active ? 'user.enabled' : 'user.disabled', (string) $user['username']);
    flash($active ? 'User enabled.' : 'User disabled.');
    redirect('/admin');
}

function post_user_role(int $id): void
{
    require_role('admin');
    csrf_check();
    $user = Db::one('SELECT * FROM pn_user WHERE id = ?', [$id]);
    if (!$user) {
        flash('No such user.', 'bad');
        redirect('/admin');
    }
    $role = (string) ($_POST['role'] ?? '');
    if (!in_array($role, ['admin', 'operator', 'viewer'], true)) {
        flash('Unknown role.', 'bad');
        redirect('/admin');
    }
    if ($id === (int) (current_user()['id'] ?? 0)) {
        flash('You cannot change your own role.', 'bad');
        redirect('/admin');
    }
    if ($user['role'] === 'admin' && $role !== 'admin') {
        $admins = (int) (Db::one(
            "SELECT COUNT(*) AS n FROM pn_user WHERE role = 'admin' AND is_active = 1")['n'] ?? 0);
        if ($admins <= 1) {
            flash('The last active admin cannot be demoted.', 'bad');
            redirect('/admin');
        }
    }
    Db::run('UPDATE pn_user SET role = ? WHERE id = ?', [$role, $id]);
    audit('user.role', (string) $user['username'] . ' → ' . $role);
    flash("Role for '{$user['username']}' is now {$role}.");
    redirect('/admin');
}

function post_user_password(int $id): void
{
    require_role('admin');
    csrf_check();
    $user = Db::one('SELECT * FROM pn_user WHERE id = ?', [$id]);
    if (!$user) {
        flash('No such user.', 'bad');
        redirect('/admin');
    }
    $password = (string) ($_POST['password'] ?? '');
    $problem = PasswordPolicy::problem((string) $user['username'], $password);
    if ($problem !== null) {
        flash($problem, 'bad');
        redirect('/admin');
    }
    // Resetting someone else's password hands out a temp secret → make them
    // pick their own before they can use the console.
    $force = $id === (int) (current_user()['id'] ?? 0) ? 0 : 1;
    Db::run('UPDATE pn_user SET password_hash = ?, must_change_password = ? WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $force, $id]);
    audit('user.password_reset', (string) $user['username']);
    flash($force === 1
        ? 'Password updated — the user must choose a new one at next sign-in.'
        : 'Password updated.');
    redirect('/admin');
}

/** Self-service change (also the forced first-login step). */
function post_account_password(): void
{
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    csrf_check();
    $row = Db::one('SELECT * FROM pn_user WHERE id = ?', [(int) $user['id']]);
    if (!$row) {
        $_SESSION = [];
        session_destroy();
        redirect('/login');
    }
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($current, (string) $row['password_hash'])) {
        flash('Current password is not correct.', 'bad');
        redirect('/account/password');
    }
    if ($new !== $confirm) {
        flash('The new passwords do not match.', 'bad');
        redirect('/account/password');
    }
    if (password_verify($new, (string) $row['password_hash'])) {
        flash('The new password must differ from the current one.', 'bad');
        redirect('/account/password');
    }
    $problem = PasswordPolicy::problem((string) $row['username'], $new);
    if ($problem !== null) {
        flash($problem, 'bad');
        redirect('/account/password');
    }

    Db::run('UPDATE pn_user SET password_hash = ?, must_change_password = 0 WHERE id = ?',
        [password_hash($new, PASSWORD_DEFAULT), (int) $row['id']]);
    $_SESSION['user']['must_change_password'] = 0;
    audit('user.password_changed', (string) $row['username']);
    flash('Password updated.');
    redirect('/');
}

// ================================================================== settings

function post_settings_save(): void
{
    require_role('admin');
    csrf_check();

    $from = trim((string) ($_POST['alerts_from'] ?? ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        flash('Alert sender must be a valid email address.', 'bad');
        redirect('/admin/settings');
    }
    $rawTo = trim((string) ($_POST['alerts_to'] ?? ''));
    $to = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $rawTo) ?: [])));
    foreach ($to as $addr) {
        if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            flash("Recipient '{$addr}' is not a valid email address.", 'bad');
            redirect('/admin/settings');
        }
    }

    $retention = max(1, min(3650, (int) ($_POST['retention_days'] ?? 30)));
    $retries   = max(0, min(10, (int) ($_POST['task_retries'] ?? 0)));
    $delay     = max(0, min(86400, (int) ($_POST['task_retry_delay'] ?? 300)));
    $timeout   = max(0, min(86400, (int) ($_POST['task_timeout'] ?? 0)));

    $updaterRepo = trim((string) ($_POST['updater_repo'] ?? 'qoisoctava/smilir'));
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $updaterRepo)) {
        flash('Update repository must look like owner/name.', 'bad');
        redirect('/admin/settings');
    }
    $updaterChannel = (string) ($_POST['updater_channel'] ?? 'branch');
    if (!in_array($updaterChannel, ['branch', 'release'], true)) {
        $updaterChannel = 'branch';
    }
    $updaterBranch = trim((string) ($_POST['updater_branch'] ?? 'main'));
    if (!preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $updaterBranch)) {
        flash('Invalid branch name.', 'bad');
        redirect('/admin/settings');
    }

    $before = Settings::effective();
    Settings::set('updater.enabled', !empty($_POST['updater_enabled']) ? '1' : '0');
    Settings::set('updater.repo', $updaterRepo);
    Settings::set('updater.channel', $updaterChannel);
    Settings::set('updater.branch', $updaterBranch);
    Settings::set('alerts.enabled', !empty($_POST['alerts_enabled']) ? '1' : '0');
    Settings::set('alerts.from', $from);
    Settings::set('alerts.to', implode(',', $to));
    Settings::set('retention.days', (string) $retention);
    Settings::set('task.retries', (string) $retries);
    Settings::set('task.retry_delay', (string) $delay);
    Settings::set('task.timeout', (string) $timeout);
    Settings::set('task.email', !empty($_POST['task_email']) ? '1' : '0');

    $defaultsChanged = $before['task.retries'] !== $retries
        || $before['task.retry_delay'] !== $delay
        || $before['task.timeout'] !== $timeout
        || $before['task.email'] !== !empty($_POST['task_email']);
    if ($defaultsChanged) {
        // The loader caches by source hash: invalidate it so the new defaults
        // reach DAGs whose Python source did not change.
        Db::run('UPDATE pn_dag SET source_hash = NULL');
    }

    audit('settings.changed', 'panel');
    flash('Settings saved.' . ($defaultsChanged
        ? ' Task defaults apply to every DAG on the next tick.'
        : ''));
    redirect('/admin/settings');
}

function post_settings_purge(): void
{
    require_role('admin');
    csrf_check();
    $days = max(1, min(3650, (int) ($_POST['days'] ?? Settings::int('retention.days', 30))));
    [$rc, $out] = run_ops_sync(['prune', '--days=' . $days], 120);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $summary = $lines ? implode(' · ', array_slice($lines, 1, 3)) : ($rc === 0 ? 'nothing to prune' : 'failed');
    audit('settings.purged', 'days=' . $days);
    flash($rc === 0 ? ('Purge finished: ' . $summary) : ('Purge failed: ' . $summary),
        $rc === 0 ? 'ok' : 'bad');
    redirect('/admin/settings');
}

// ================================================================== updater

function post_update_check(): void
{
    require_role('admin');
    csrf_check();
    $r = Updater::check(true);
    if (empty($r['ok'])) {
        audit('updater.checked', 'failed');
        flash('Update check failed: ' . ($r['error'] ?? 'unknown error'), 'bad');
    } elseif (!empty($r['update'])) {
        audit('updater.checked', (string) $r['remote']['version']);
        flash('Update available: ' . $r['remote']['version'] . '.');
    } else {
        audit('updater.checked', 'up-to-date');
        flash('Already up to date (' . $r['current']['version'] . ').');
    }
    redirect('/admin/updates');
}

function post_update_apply(): void
{
    require_role('admin');
    csrf_check();
    if (($reason = Updater::applyUnavailableReason()) !== null) {
        flash($reason, 'bad');
        redirect('/admin/updates');
    }
    if (Updater::isRunning()) {
        flash('An update is already running.', 'bad');
        redirect('/admin/updates');
    }
    spawn_ops(['update:apply'], Updater::dir() . '/update.log');
    audit('updater.started', 'github');
    flash('Update started — this page refreshes until it finishes.');
    redirect('/admin/updates');
}

function post_update_rollback(): void
{
    require_role('admin');
    csrf_check();
    if (($reason = Updater::applyUnavailableReason()) !== null) {
        flash($reason, 'bad');
        redirect('/admin/updates');
    }
    if (Updater::isRunning()) {
        flash('An update is already running.', 'bad');
        redirect('/admin/updates');
    }
    spawn_ops(['update:rollback'], Updater::dir() . '/update.log');
    audit('updater.rollback_started', 'backup');
    flash('Rollback started — files only; database migrations are not reverted.');
    redirect('/admin/updates');
}

function post_update_upload(): void
{
    require_role('admin');
    csrf_check();
    if (($reason = Updater::applyUnavailableReason()) !== null) {
        flash($reason, 'bad');
        redirect('/admin/updates');
    }
    if (Updater::isRunning()) {
        flash('An update is already running.', 'bad');
        redirect('/admin/updates');
    }
    $file = $_FILES['archive'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('Choose a release zip or tar.gz.', 'bad');
        redirect('/admin/updates');
    }
    if ((int) $file['size'] > 33554432) {
        flash('Archive is too large (max 32 MB).', 'bad');
        redirect('/admin/updates');
    }
    $name = strtolower((string) $file['name']);
    if (!preg_match('/\.(zip|tar|gz|tgz)$/', $name)) {
        flash('Only .zip, .tar, .tar.gz or .tgz archives are accepted.', 'bad');
        redirect('/admin/updates');
    }
    $dest = Updater::dir() . '/upload-' . date('Ymd-His') . '-'
        . bin2hex(random_bytes(3)) . '.' . pathinfo($name, PATHINFO_EXTENSION);
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        flash('Upload failed (permissions?).', 'bad');
        redirect('/admin/updates');
    }
    spawn_ops(['update:apply', '--archive=' . $dest], Updater::dir() . '/update.log');
    audit('updater.uploaded', (string) $file['name']);
    flash('Archive uploaded — applying in the background.');
    redirect('/admin/updates');
}

// ================================================================== pages

function dag_edit_page(?string $name): void
{
    require_role('admin');
    $dag = null;
    $tasks = [];
    if ($name !== null) {
        $dag = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$name]);
        if (!$dag) {
            http_response_code(404);
            render('error', ['code' => 404, 'title' => 'Dag not found',
                'message' => 'No Dag named "' . $name . '".'], 'bare');
            return;
        }
        $tasks = Db::all('SELECT * FROM pn_task WHERE dag_id = ? ORDER BY id', [$dag['id']]);
    }
    $venvName = null;
    if ($dag && $dag['default_venv_id'] !== null) {
        $v = Db::one('SELECT name FROM pn_venv WHERE id = ?', [$dag['default_venv_id']]);
        $venvName = $v['name'] ?? null;
    }
    render('dag_edit', [
        'title' => $name ? 'Edit ' . $name : 'New Dag',
        'nav' => 'dags',
        'dag' => $dag,
        'tasks' => $tasks,
        'venvName' => $venvName,
        'venvs' => Db::all('SELECT name FROM pn_venv ORDER BY name'),
    ]);
}

function dag_import_page(): void
{
    require_role('admin');
    if (isset($_GET['cancel']) && !empty($_SESSION['import']['path'])) {
        @unlink((string) $_SESSION['import']['path']);
        unset($_SESSION['import']);
        flash('Import cancelled.');
        redirect('/dags/import');
    }
    render('dag_import', [
        'title' => 'Import Dag',
        'nav' => 'dags',
        'import' => $_SESSION['import'] ?? null,
    ]);
}

function venv_log_page(string $name): void
{
    require_role('admin');
    if (!Db::one('SELECT id FROM pn_venv WHERE name = ?', [$name])) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Environment not found',
            'message' => 'No environment named "' . $name . '".'], 'bare');
        return;
    }
    render('venv_log', [
        'title' => 'pip log',
        'nav' => 'python',
        'name' => $name,
        'lines' => tail_lines(venv_log_path($name), 500),
    ]);
}

function audit_page(): void
{
    require_role('admin');
    render('audit', [
        'title' => 'Audit log',
        'nav' => 'admin',
        'rows' => Audit::recent(200),
    ]);
}
