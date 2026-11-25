<?php
/**
 * smilir web console — front controller.
 *
 * Dev:   php -S 127.0.0.1:8099 public/index.php
 * Prod:  copy public/* to public_html/pipnix/ (or point the docroot at public/)
 */
declare(strict_types=1);

// Serve static files when using PHP's built-in server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($rawPath === '/lib.php' || str_starts_with($rawPath, '/views')) {
    http_response_code(404);
    exit('Not found');
}

require __DIR__ . '/lib.php';
require __DIR__ . '/actions.php';

// ------------------------------------------------------------------ routing
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$base = rtrim((string) Env::cfg('ui.base_path', ''), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');

$segments = array_values(array_filter(explode('/', $path), 'strlen'));
$page     = $segments[0] ?? '';

// ------------------------------------------------------------------ auth
if ($page === 'login') {
    if ($method === 'POST') {
        csrf_check();
        handle_login();
        return;
    }
    if (current_user()) {
        redirect('/');
    }
    render('login', ['title' => 'Sign in'], 'bare');
    return;
}

if ($method === 'POST' && $page === 'logout') {
    require_login();
    csrf_check();
    $_SESSION = [];
    session_destroy();
    redirect('/login');
    return;
}

require_login();

// ---------------------------------------------------- forced password change
$me = current_user();
if ((int) ($me['must_change_password'] ?? 0) === 1
    && !($page === 'account' && ($segments[1] ?? '') === 'password')) {
    flash('Choose a new password before continuing.', 'bad');
    redirect('/account/password');
}

// ------------------------------------------------------------------ write actions
if ($method === 'POST') {
    handle_post($page, $segments);
    return;
}

// ------------------------------------------------------------------ pages
switch ($page) {
    case '':
        dashboard_page();
        return;
    case 'dags':
        if (!isset($segments[1])) {
            dags_page();
            return;
        }
        if ($segments[1] === 'new') {
            dag_edit_page(null);
            return;
        }
        if ($segments[1] === 'import') {
            dag_import_page();
            return;
        }
        if ($segments[1] === 'schedule-preview') {
            dag_schedule_preview();
            return;
        }
        if (($segments[2] ?? '') === 'source') {
            dag_source((string) $segments[1]);
            return;
        }
        if (($segments[2] ?? '') === 'edit') {
            dag_edit_page((string) $segments[1]);
            return;
        }
        dag_page((string) $segments[1]);
        return;
    case 'task':
        if (ctype_digit((string) ($segments[1] ?? ''))) {
            if (($segments[2] ?? '') === 'attempt' && ctype_digit((string) ($segments[3] ?? ''))) {
                attempt_log_page((int) $segments[1], (int) $segments[3]);
                return;
            }
            task_page((int) $segments[1]);
            return;
        }
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Task not found',
            'message' => 'No task instance with that id.'], 'bare');
        return;
    case 'runs':
        if (!isset($segments[1])) {
            runs_page();
            return;
        }
        run_page((int) $segments[1]);
        return;
    case 'logs':
        if (($segments[2] ?? '') === 'download' && isset($segments[1])) {
            $attempt = isset($_GET['attempt']) && ctype_digit((string) $_GET['attempt'])
                ? (int) $_GET['attempt'] : null;
            log_download((int) $segments[1], $attempt);
            return;
        }
        logs_page(isset($segments[1]) ? (int) $segments[1] : (isset($_GET['task']) ? (int) $_GET['task'] : 0));
        return;
    case 'docs':
        docs_page((string) ($segments[1] ?? ''));
        return;
    case 'search':
        search_page();
        return;
    case 'python':
        if (isset($segments[1]) && ($segments[2] ?? '') === 'log') {
            venv_log_page((string) $segments[1]);
            return;
        }
        python_page($segments[1] ?? null);
        return;
    case 'account':
        if (($segments[1] ?? '') === 'password') {
            account_password_page();
            return;
        }
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Page not found',
            'message' => 'That page does not exist in the console.'], 'bare');
        return;
    case 'admin':
        require_role('admin');
        if (($segments[1] ?? '') === 'audit') {
            audit_page();
            return;
        }
        if (($segments[1] ?? '') === 'settings') {
            settings_page();
            return;
        }
        if (($segments[1] ?? '') === 'updates') {
            updates_page();
            return;
        }
        admin_page();
        return;
    default:
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Page not found',
            'message' => 'That page does not exist in the console.'], 'bare');
}

// ================================================================== auth
function handle_login(): void
{
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // simple throttling per session
    $fails = (int) ($_SESSION['login_fails'] ?? 0);
    if ($fails >= 5 && (time() - (int) ($_SESSION['login_blocked_at'] ?? 0)) < 30) {
        flash('Too many attempts — try again in 30 seconds.', 'bad');
        render('login', ['title' => 'Sign in'], 'bare');
        return;
    }

    $user = Db::one('SELECT * FROM pn_user WHERE username = ? AND is_active = 1', [$username]);
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        $_SESSION['login_fails'] = $fails + 1;
        $_SESSION['login_blocked_at'] = time();
        usleep(400000);
        flash('Invalid username or password.', 'bad');
        render('login', ['title' => 'Sign in', 'username' => $username], 'bare');
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'       => (int) $user['id'],
        'username' => (string) $user['username'],
        'role'     => (string) $user['role'],
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
    ];
    unset($_SESSION['login_fails']);
    Db::run('UPDATE pn_user SET last_login_at = ? WHERE id = ?', [Env::now(), $user['id']]);
    if ((int) ($user['must_change_password'] ?? 0) === 1) {
        flash('This account still uses a temporary password — set a new one to continue.', 'bad');
        redirect('/account/password');
    }
    redirect('/');
}

// ================================================================== dashboard
function dashboard_page(): void
{
    $ranges = [
        '24h' => ['Last 24 Hours', 86400],
        '7d'  => ['Last 7 Days', 604800],
        '30d' => ['Last 30 Days', 2592000],
    ];
    $range = (string) ($_GET['range'] ?? '24h');
    if (!isset($ranges[$range])) {
        $range = '24h';
    }
    $since = date('Y-m-d H:i:s', time() - $ranges[$range][1]);

    $stats = [
        'failed_dags' => (int) (Db::one(
            'SELECT COUNT(*) AS n FROM pn_dag d WHERE (
                SELECT r.state FROM pn_dag_run r WHERE r.dag_id = d.id
                 ORDER BY r.id DESC LIMIT 1) = "failed"')['n'] ?? 0),
        'running_dags' => (int) (Db::one(
            "SELECT COUNT(DISTINCT dag_id) AS n FROM pn_dag_run WHERE state = 'running'")['n'] ?? 0),
        'active_dags' => (int) (Db::one(
            'SELECT COUNT(*) AS n FROM pn_dag WHERE is_active = 1')['n'] ?? 0),
    ];

    $dagRunMix = [];
    foreach (Db::all('SELECT state, COUNT(*) n FROM pn_dag_run
                       WHERE started_at >= ? GROUP BY state', [$since]) as $row) {
        $dagRunMix[$row['state']] = (int) $row['n'];
    }
    $taskMix = [];
    foreach (Db::all('SELECT tr.state, COUNT(*) n
                        FROM pn_task_run tr
                        JOIN pn_dag_run r ON r.id = tr.dag_run_id
                       WHERE r.started_at >= ?
                       GROUP BY tr.state', [$since]) as $row) {
        $taskMix[$row['state']] = (int) $row['n'];
    }

    $slots = [
        'used' => (int) (Db::one(
            "SELECT COUNT(*) AS n FROM pn_task_run WHERE state = 'running'")['n'] ?? 0),
        'total' => (int) (Db::one(
            'SELECT COALESCE(SUM(max_concurrency), 0) AS n FROM pn_dag WHERE is_active = 1')['n'] ?? 0),
    ];

    $heartbeat = Env::path('storage') . '/heartbeat';
    $lastTick = is_file($heartbeat)
        ? (int) filemtime($heartbeat)
        : (is_file(Env::logs() . '/cron.log') ? (int) filemtime(Env::logs() . '/cron.log') : null);
    $health = [
        'database' => true,
        'scheduler' => $lastTick !== null && (time() - $lastTick) < 180,
        'runner' => function_exists('proc_open'),
    ];

    $failures = Db::all(
        "SELECT tr.id, tr.state, tr.attempt, tr.finished_at,
                t.name AS task_name, d.name AS dag_name, r.run_id
           FROM pn_task_run tr
           JOIN pn_task t     ON t.id = tr.task_id
           JOIN pn_dag_run r  ON r.id = tr.dag_run_id
           JOIN pn_dag d      ON d.id = r.dag_id
          WHERE tr.state IN ('failed','timed_out')
          ORDER BY tr.finished_at DESC, tr.id DESC LIMIT 6"
    );

    $favorites = Db::all(
        'SELECT name FROM pn_dag WHERE is_favorite = 1 ORDER BY name LIMIT 10'
    );

    render('dashboard', [
        'title' => 'Home',
        'nav' => 'home',
        'stats' => $stats,
        'dagRunMix' => $dagRunMix,
        'taskMix' => $taskMix,
        'slots' => $slots,
        'health' => $health,
        'lastTick' => $lastTick,
        'failures' => $failures,
        'favorites' => $favorites,
        'range' => $range,
        'rangeLabel' => $ranges[$range][0],
        'rangeSince' => $since,
    ]);
}

// ================================================================== dags
function dags_page(): void
{
    $search = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT d.*,
                   (SELECT COUNT(*) FROM pn_task t WHERE t.dag_id = d.id) AS tasks,
                   (SELECT r.state FROM pn_dag_run r WHERE r.dag_id = d.id
                     ORDER BY r.id DESC LIMIT 1) AS last_state,
                   (SELECT r.started_at FROM pn_dag_run r WHERE r.dag_id = d.id
                     ORDER BY r.id DESC LIMIT 1) AS last_started
              FROM pn_dag d';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE d.name LIKE ?';
        $params[] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY d.name';

    render('dags', [
        'title' => 'Dags',
        'nav' => 'dags',
        'dags' => Db::all($sql, $params),
        'search' => $search,
    ]);
}

function dag_page(string $name): void
{
    $dag = Db::one('SELECT * FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Dag not found',
            'message' => 'No Dag named "' . $name . '".'], 'bare');
        return;
    }
    $tasks = Db::all('SELECT * FROM pn_task WHERE dag_id = ? ORDER BY id', [$dag['id']]);
    $runs = Db::all('SELECT * FROM pn_dag_run WHERE dag_id = ? ORDER BY id DESC LIMIT 12',
        [$dag['id']]);

    // grid: task × run
    $grid = [];
    if ($runs) {
        $ids = array_column($runs, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::all("SELECT task_id, dag_run_id, state FROM pn_task_run
                           WHERE dag_run_id IN ($in)", $ids) as $cell) {
            $grid[(int) $cell['task_id']][(int) $cell['dag_run_id']] = $cell['state'];
        }
    }
    $venv = $dag['default_venv_id']
        ? Db::one('SELECT name FROM pn_venv WHERE id = ?', [$dag['default_venv_id']]) : null;

    $tab = (string) ($_GET['tab'] ?? 'grid');
    if (!in_array($tab, ['grid', 'graph', 'gantt', 'code'], true)) {
        $tab = 'grid';
    }

    // Latest run + its task states drive the Graph and Gantt tabs.
    $latest = $runs[0] ?? null;
    $latestTasks = [];
    if ($latest && $tab !== 'code') {
        $latestTasks = Db::all(
            'SELECT tr.state, tr.started_at, tr.finished_at, tr.attempt, tr.exit_code,
                    tr.error, tr.result_json, tr.log_path, t.id AS task_id, t.name
               FROM pn_task_run tr JOIN pn_task t ON t.id = tr.task_id
              WHERE tr.dag_run_id = ? ORDER BY t.id',
            [(int) $latest['id']]
        );
    }
    $graph = $tab === 'graph' ? dag_graph_layout($tasks, $latestTasks) : [];

    $source = null;
    if ($tab === 'code') {
        $path = (string) $dag['source_path'];
        $root = realpath(Env::path('dags'));
        $real = realpath($path);
        if ($root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            && is_file($real) && filesize($real) <= 1048576) {
            $source = [
                'path' => $real,
                'name' => basename($real),
                'size' => (int) filesize($real),
                'lines' => preg_split('/\R/', (string) file_get_contents($real)) ?: [],
            ];
        }
    }

    render('dag', [
        'title' => $dag['name'],
        'nav' => 'dags',
        'dag' => $dag,
        'tasks' => $tasks,
        'runs' => $runs,
        'grid' => $grid,
        'venvName' => $venv['name'] ?? null,
        'tab' => $tab,
        'latest' => $latest,
        'latestTasks' => $latestTasks,
        'graph' => $graph,
        'source' => $source,
    ]);
}

/** Layered DAG layout: longest path from a root decides the column. */
function dag_graph_layout(array $tasks, array $latestTasks): array
{
    $byName = [];
    foreach ($tasks as $t) {
        $byName[$t['name']] = [
            'id' => (int) $t['id'],
            'deps' => array_values(array_filter(array_map('trim',
                explode(',', (string) $t['depends_on'])))),
            'state' => 'idle',
            'duration' => '—',
            'attempt' => 1,
        ];
    }
    foreach ($latestTasks as $tr) {
        $key = (string) $tr['name'];
        if (!isset($byName[$key])) {
            continue;
        }
        $byName[$key]['state'] = (string) $tr['state'];
        $byName[$key]['attempt'] = (int) $tr['attempt'];
        if ($tr['started_at']) {
            $byName[$key]['duration'] = human_duration((string) $tr['started_at'],
                $tr['finished_at'] !== null ? (string) $tr['finished_at'] : null);
        }
    }

    $level = [];
    $resolve = function (string $n, array $seen = []) use (&$resolve, &$level, $byName): int {
        if (isset($level[$n])) {
            return $level[$n];
        }
        if (isset($seen[$n])) {
            return 0;
        }
        $seen[$n] = true;
        $max = -1;
        foreach ($byName[$n]['deps'] ?? [] as $dep) {
            if (isset($byName[$dep])) {
                $max = max($max, $resolve($dep, $seen));
            }
        }
        return $level[$n] = $max + 1;
    };
    foreach (array_keys($byName) as $n) {
        $resolve($n);
    }

    $perLevel = [];
    foreach ($byName as $n => $info) {
        $info['name'] = $n;
        $perLevel[$level[$n]][] = $info;
    }
    ksort($perLevel);

    return [
        'levels' => $perLevel,
        'nodes' => $byName,
        'cols' => $perLevel === [] ? 0 : max(array_keys($perLevel)) + 1,
        'rows' => $perLevel === [] ? 0 : max(array_map('count', $perLevel)),
    ];
}

/** JSON preview for the DAG editor: human sentence + the next 5 runs. */
function dag_schedule_preview(): void
{
    $raw = trim((string) ($_GET['schedule'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');

    if ($raw === '') {
        echo json_encode(['ok' => false, 'error' => 'Enter a cron expression or interval:N']);
        return;
    }
    if (str_starts_with($raw, 'interval:')) {
        $minutes = (int) substr($raw, 9);
        if ($minutes < 1) {
            echo json_encode(['ok' => false, 'error' => 'interval must be >= 1 minute']);
            return;
        }
        $next = [];
        $t = time();
        for ($i = 0; $i < 5; $i++) {
            $t += $minutes * 60;
            $next[] = date('Y-m-d H:i', $t);
        }
        echo json_encode([
            'ok' => true, 'kind' => 'interval',
            'human' => 'every ' . $minutes . ' min', 'next' => $next,
        ]);
        return;
    }
    if (!Cron::isValid($raw)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid cron expression (5 fields: min hour dom mon dow)']);
        return;
    }
    $next = [];
    $t = new DateTimeImmutable('now');
    for ($i = 0; $i < 5; $i++) {
        $n = Cron::nextRun($raw, $t);
        if (!$n) {
            break;
        }
        $next[] = $n->format('Y-m-d H:i');
        $t = $n;
    }
    echo json_encode([
        'ok' => true, 'kind' => 'cron', 'human' => cron_human($raw), 'next' => $next,
    ]);
}

/** Download the DAG module exactly as it is stored in dags/. */
function dag_source(string $name): void
{
    $dag = Db::one('SELECT source_path FROM pn_dag WHERE name = ?', [$name]);
    if (!$dag) {
        http_response_code(404);
        exit('not found');
    }
    $root = realpath(Env::path('dags'));
    $real = realpath((string) $dag['source_path']);
    if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
        || !is_file($real)) {
        http_response_code(404);
        exit('source missing');
    }
    header('Content-Type: text/x-python; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($real) . '"');
    header('Content-Length: ' . filesize($real));
    readfile($real);
    exit;
}

// ================================================================== task instance
function task_page(int $id): void
{
    $tr = Db::one(
        'SELECT tr.*, t.name AS task_name, t.func, t.command, t.runner, t.timeout,
                t.retries, t.retry_delay, t.depends_on, t.email_on_failure,
                v.name AS venv_name,
                r.run_id, r.state AS run_state, r.started_at AS run_started,
                d.name AS dag_name, d.id AS dag_id
           FROM pn_task_run tr
           JOIN pn_task t     ON t.id = tr.task_id
           JOIN pn_dag_run r  ON r.id = tr.dag_run_id
           JOIN pn_dag d      ON d.id = r.dag_id
           LEFT JOIN pn_venv v ON v.id = t.venv_id
          WHERE tr.id = ?', [$id]);
    if (!$tr) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Task not found',
            'message' => 'No task instance with id ' . $id . '.'], 'bare');
        return;
    }

    $attempts = Logger::attemptsFor((string) $tr['dag_name'], (string) $tr['run_id'],
        (string) $tr['task_name']);
    $resolved = $tr['func']
        ? $tr['func'] . '(ds="' . $tr['run_id'] . '")'
        : str_replace('{ds}', (string) $tr['run_id'], (string) $tr['command']);
    $tail = [];
    if (!empty($tr['log_path']) && is_file((string) $tr['log_path'])) {
        $tail = tail_lines((string) $tr['log_path'], 30);
    }

    render('task', [
        'title' => $tr['task_name'] . ' · ' . $tr['state'],
        'nav' => 'runs',
        'tr' => $tr,
        'attempts' => $attempts,
        'resolved' => $resolved,
        'tail' => $tail,
    ]);
}

/** Reuse the logs viewer for one stored attempt file. */
function attempt_log_page(int $id, int $attempt): void
{
    $tr = Db::one(
        'SELECT tr.*, t.name AS task_name, d.name AS dag_name, r.run_id
           FROM pn_task_run tr
           JOIN pn_task t    ON t.id = tr.task_id
           JOIN pn_dag_run r ON r.id = tr.dag_run_id
           JOIN pn_dag d     ON d.id = r.dag_id
          WHERE tr.id = ?', [$id]);
    if (!$tr) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Log not found',
            'message' => 'No task instance with id ' . $id . '.'], 'bare');
        return;
    }
    $path = Logger::pathFor((string) $tr['dag_name'], (string) $tr['run_id'],
        (string) $tr['task_name'], $attempt);
    if (!is_file($path)) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Log not found',
            'message' => 'Attempt ' . $attempt . ' has no log file.'], 'bare');
        return;
    }
    render('logs', [
        'title' => 'Logs · a' . $attempt,
        'nav' => 'runs',
        'tr' => $tr,
        'lines' => tail_lines($path, 500),
        'attempt' => $attempt,
        'logPath' => $path,
    ]);
}

// ================================================================== runs
function runs_page(): void
{
    $dagFilter = trim((string) ($_GET['dag'] ?? ''));
    $stateFilter = trim((string) ($_GET['state'] ?? ''));

    $sql = 'SELECT r.*, d.name AS dag_name,
                   (SELECT COUNT(*) FROM pn_task_run tr WHERE tr.dag_run_id = r.id) AS tasks,
                   (SELECT COUNT(*) FROM pn_task_run tr WHERE tr.dag_run_id = r.id
                     AND tr.state IN ("success","failed","skipped","upstream_failed","timed_out")) AS done
              FROM pn_dag_run r JOIN pn_dag d ON d.id = r.dag_id WHERE 1=1';
    $params = [];
    if ($dagFilter !== '') {
        $sql .= ' AND d.name = ?';
        $params[] = $dagFilter;
    }
    if ($stateFilter !== '') {
        $sql .= ' AND r.state = ?';
        $params[] = $stateFilter;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 50';

    render('runs', [
        'title' => 'Runs',
        'nav' => 'runs',
        'runs' => Db::all($sql, $params),
        'dags' => Db::all('SELECT name FROM pn_dag ORDER BY name'),
        'dagFilter' => $dagFilter,
        'stateFilter' => $stateFilter,
    ]);
}

function run_page(int $id): void
{
    $run = Db::one('SELECT r.*, d.name AS dag_name FROM pn_dag_run r
                      JOIN pn_dag d ON d.id = r.dag_id WHERE r.id = ?', [$id]);
    if (!$run) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Run not found',
            'message' => 'No run with id ' . $id . '.'], 'bare');
        return;
    }
    $tasks = Db::all(
        'SELECT tr.*, t.name AS task_name, t.depends_on
           FROM pn_task_run tr JOIN pn_task t ON t.id = tr.task_id
          WHERE tr.dag_run_id = ? ORDER BY tr.id',
        [$id]
    );
    render('run', [
        'title' => 'Run #' . $run['id'],
        'nav' => 'runs',
        'run' => $run,
        'tasks' => $tasks,
    ]);
}

// ================================================================== logs
function log_download(int $taskRunId, ?int $attempt = null): void
{
    $tr = Db::one(
        'SELECT tr.log_path, t.name AS task_name, d.name AS dag_name, r.run_id
           FROM pn_task_run tr
           JOIN pn_task t    ON t.id = tr.task_id
           JOIN pn_dag_run r ON r.id = tr.dag_run_id
           JOIN pn_dag d     ON d.id = r.dag_id
          WHERE tr.id = ?', [$taskRunId]);
    if (!$tr) {
        http_response_code(404);
        exit('log not found');
    }
    $path = $attempt !== null
        ? Logger::pathFor((string) $tr['dag_name'], (string) $tr['run_id'],
            (string) $tr['task_name'], $attempt)
        : (string) ($tr['log_path'] ?? '');
    $root = realpath(Env::logs());
    $real = $path !== '' ? realpath($path) : false;
    if ($root === false || $real === false
        || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
        http_response_code(404);
        exit('log not found');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($real) . '"');
    header('Content-Length: ' . filesize($real));
    readfile($real);
    exit;
}

function logs_page(int $taskRunId): void
{
    if ($taskRunId === 0) {
        logs_index_page();
        return;
    }
    $tr = Db::one(
        'SELECT tr.*, t.name AS task_name, d.name AS dag_name, r.run_id
           FROM pn_task_run tr
           JOIN pn_task t    ON t.id = tr.task_id
           JOIN pn_dag_run r ON r.id = tr.dag_run_id
           JOIN pn_dag d     ON d.id = r.dag_id
          WHERE tr.id = ?', [$taskRunId]);
    if (!$tr) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Log not found',
            'message' => 'No task instance with id ' . $taskRunId . '.'], 'bare');
        return;
    }
    $lines = $tr['log_path'] ? tail_lines((string) $tr['log_path'], 500) : [];
    render('logs', [
        'title' => 'Logs',
        'nav' => 'logs',
        'tr' => $tr,
        'lines' => $lines,
    ]);
}

/** Landing page for the Logs nav item: the newest task runs + their logs. */
function logs_index_page(): void
{
    $state = (string) ($_GET['state'] ?? '');
    $where = '';
    $params = [];
    if (in_array($state, ['queued', 'running', 'retrying', 'success', 'failed',
                          'timed_out', 'upstream_failed', 'skipped'], true)) {
        $where = 'WHERE tr.state = ?';
        $params[] = $state;
    }
    $rows = Db::all(
        "SELECT tr.id, tr.state, tr.attempt, tr.started_at, tr.finished_at,
                tr.dag_run_id, r.run_id, d.name AS dag_name, t.name AS task_name,
                (tr.log_path IS NOT NULL AND tr.log_path != '') AS has_log
           FROM pn_task_run tr
           JOIN pn_dag_run r ON r.id = tr.dag_run_id
           JOIN pn_dag d     ON d.id = r.dag_id
           JOIN pn_task t    ON t.id = tr.task_id
           {$where}
          ORDER BY tr.id DESC
          LIMIT 100",
        $params
    );
    render('log_index', [
        'title' => 'Logs',
        'nav' => 'logs',
        'state' => $state,
        'rows' => $rows,
    ]);
}

// ================================================================== docs
function docs_page(string $slug): void
{
    $docs = [
        ''        => ['Panduan Lengkap', 'GUIDE'],
        'guide'   => ['Panduan Lengkap', 'GUIDE'],
        'cpanel'  => ['Deploying to cPanel', 'DEPLOY'],
        'deploy'  => ['Deploying to cPanel', 'DEPLOY'],
        'dags'    => ['Writing DAGs', 'DAGS'],
        'docker'  => ['Running with Docker', 'DOCKER'],
        'updates' => ['Updating ' . PN_BRAND, 'UPDATE'],
    ];
    if (!isset($docs[$slug])) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Doc not found',
            'message' => 'Available docs: guide, cpanel, dags, docker, updates.'], 'bare');
        return;
    }
    [$title, $file] = $docs[$slug];
    $path = Env::path('docs') . '/' . $file . '.md';
    if (!is_file($path)) {
        http_response_code(404);
        render('error', ['code' => 404, 'title' => 'Doc not found',
            'message' => 'The guide file is missing on this install.'], 'bare');
        return;
    }
    $markdown = (string) file_get_contents($path);
    // The page chrome already provides the visible H1. Keep the source title
    // for GitHub/readme use, but avoid rendering a duplicate heading in-app.
    $markdown = (string) preg_replace('/^\s*#\s+[^\r\n]+\R+/', '', $markdown, 1);
    render('doc', [
        'title' => $title,
        'nav' => 'docs',
        'slug' => match ($slug) {
            '', 'guide' => 'guide',
            'deploy' => 'cpanel',
            default => $slug,
        },
        'head' => $title,
        'html' => md_to_html($markdown),
    ]);
}

// ================================================================== search
function search_page(): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = (string) ($_GET['type'] ?? 'all');
    if (!in_array($type, ['all', 'dags', 'runs', 'tasks'], true)) {
        $type = 'all';
    }

    $dags = $runs = $tasks = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        if ($type === 'all' || $type === 'dags') {
            $dags = Db::all(
                'SELECT name, is_active, is_favorite FROM pn_dag
                  WHERE name LIKE ? ORDER BY name LIMIT 20', [$like]);
        }
        if ($type === 'all' || $type === 'runs') {
            $runs = Db::all(
                'SELECT r.id, r.run_id, r.state, r.started_at, d.name AS dag_name
                   FROM pn_dag_run r JOIN pn_dag d ON d.id = r.dag_id
                  WHERE r.run_id LIKE ? OR d.name LIKE ?
                  ORDER BY r.id DESC LIMIT 20', [$like, $like]);
        }
        if ($type === 'all' || $type === 'tasks') {
            $tasks = Db::all(
                'SELECT tr.id, tr.state, tr.started_at, t.name AS task_name,
                        d.name AS dag_name, r.id AS run_db_id
                   FROM pn_task_run tr
                   JOIN pn_task t     ON t.id = tr.task_id
                   JOIN pn_dag_run r  ON r.id = tr.dag_run_id
                   JOIN pn_dag d      ON d.id = r.dag_id
                  WHERE t.name LIKE ? OR d.name LIKE ?
                  ORDER BY tr.id DESC LIMIT 20', [$like, $like]);
        }
    }

    render('search', [
        'title' => 'Search',
        'nav' => '',
        'q' => $q,
        'type' => $type,
        'dags' => $dags,
        'runs' => $runs,
        'tasks' => $tasks,
        'total' => count($dags) + count($runs) + count($tasks),
    ]);
}

// ================================================================== python
function python_page(?string $name): void
{
    $venvs = Db::all('SELECT v.*,
                        (SELECT COUNT(*) FROM pn_venv_package p WHERE p.venv_id = v.id) AS packages,
                        (SELECT COUNT(*) FROM pn_venv_package p WHERE p.venv_id = v.id
                          AND p.latest_version IS NOT NULL) AS outdated
                        FROM pn_venv v ORDER BY v.is_default DESC, v.name');
    $selected = null;
    $packages = [];
    if ($name !== null) {
        $selected = Db::one('SELECT * FROM pn_venv WHERE name = ?', [$name]);
        if (!$selected) {
            http_response_code(404);
            render('error', ['code' => 404, 'title' => 'Environment not found',
                'message' => 'No environment named "' . $name . '".'], 'bare');
            return;
        }
        $packages = Db::all('SELECT * FROM pn_venv_package WHERE venv_id = ? ORDER BY name',
            [$selected['id']]);
    }
    render('python', [
        'title' => 'Python',
        'nav' => 'python',
        'venvs' => $venvs,
        'selected' => $selected,
        'packages' => $packages,
    ]);
}

// ================================================================== account
function account_password_page(): void
{
    $profile = Db::one(
        'SELECT username, email, role, must_change_password, last_login_at, created_at
           FROM pn_user WHERE id = ?',
        [(int) (current_user()['id'] ?? 0)]
    ) ?: current_user();
    render('account_password', [
        'title' => 'Change password',
        'nav'   => '',
        'user'  => $profile,
        'profile' => $profile,
    ]);
}

// ================================================================== admin
function updates_page(): void
{
    render('updates', [
        'title' => 'Software updates',
        'nav' => 'admin',
        'state' => Updater::state(),
        'check' => Updater::cached(),
        'running' => Updater::isRunning(),
        'backup' => Updater::lastBackupMeta(),
        'log' => Updater::logTail(80),
        'checkReason' => Updater::checkUnavailableReason(),
        'applyReason' => Updater::applyUnavailableReason(),
        'config' => Updater::config(),
    ]);
}

function settings_page(): void
{
    render('settings', [
        'title' => 'Settings',
        'nav' => 'admin',
        'values' => Settings::effective(),
    ]);
}

function admin_page(): void
{
    $users = Db::all('SELECT * FROM pn_user ORDER BY id');
    $venvCount = (int) (Db::one('SELECT COUNT(*) AS n FROM pn_venv')['n'] ?? 0);
    render('admin', [
        'title' => 'Admin',
        'nav' => 'admin',
        'users' => $users,
        'venvCount' => $venvCount,
    ]);
}
