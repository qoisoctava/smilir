<?php
/**
 * smilir web console — shared helpers.
 */
declare(strict_types=1);

// ---------------------------------------------------------------- app boot
// Where is app/? Checked in order:
//   1. PIPNIX_APP env var (also readable from Apache `SetEnv` directives)
//   2. an upward walk that covers every documented layout:
//        pipnix/public/index.php          → pipnix/app        (repo / subdomain docroot)
//        public_html/pipnix/index.php     → /home/USER/pipnix/app   (cPanel copy)
//        public_html/index.php (public/* copied to the root) → /home/USER/pipnix/app
$appEnv = getenv('PIPNIX_APP') ?: ($_SERVER['PIPNIX_APP'] ?? null);
$appCandidates = [];
if ($appEnv) {
    $appCandidates[] = rtrim((string) $appEnv, '/');
}
for ($i = 1; $i <= 4; $i++) {
    $up = dirname(__DIR__, $i);
    $appCandidates[] = $up . '/app';         // repo layout / docroot = public/
    $appCandidates[] = $up . '/pipnix/app';  // cPanel copy
}
$appCandidates = array_values(array_unique(array_filter($appCandidates)));
$appDir = null;
foreach ($appCandidates as $cand) {
    if (is_file($cand . '/bootstrap.php')) {
        $appDir = $cand;
        break;
    }
}
if ($appDir === null) {
    http_response_code(500);
    echo 'smilir: cannot locate app/bootstrap.php.' . "\n\n"
        . "Checked:\n" . implode("\n", array_map('htmlspecialchars', $appCandidates)) . "\n\n"
        . 'Fix: upload the app so /home/USER/pipnix/app/bootstrap.php exists, or set the '
        . 'PIPNIX_APP environment variable to the absolute path of the app directory '
        . "(e.g. 'SetEnv PIPNIX_APP /home/USER/pipnix/app' in the .htaccess).";
    exit;
}
require_once $appDir . '/bootstrap.php';

// ---------------------------------------------------------------- session
session_name('pipnix_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);
session_start();

// ---------------------------------------------------------------- hardening
// Native form controls (checkboxes, selects) follow the dark theme.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// Uncaught exception → branded 500 page; details go to the error log only.
set_exception_handler(static function (Throwable $e): void {
    error_log('[' . PN_BRAND . '] uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    render_error_page(500, 'Something went wrong',
        'An unexpected error occurred. The details were written to the server log.');
});

// Fatal errors (max execution time, OOM) cannot throw — catch them on shutdown.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        && !headers_sent()) {
        error_log('[' . PN_BRAND . '] fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        http_response_code(500);
        render_error_page(500, 'Something went wrong',
            'An unexpected error occurred. The details were written to the server log.');
    }
});

/** Branded error page that must never itself fail. */
function render_error_page(int $code, string $title, string $message): void
{
    try {
        if (!headers_sent()) {
            http_response_code($code);
        }
        $view = __DIR__ . '/views/error.php';
        $layout = __DIR__ . '/views/bare.php';
        if (!is_file($view) || !is_file($layout)) {
            echo "<h1>{$code} — " . htmlspecialchars($title, ENT_QUOTES) . "</h1>";
            return;
        }
        ob_start();
        require $view;
        $content = (string) ob_get_clean();
        // bare.php expects $title + $flash + $content
        $flash = null;
        require $layout;
    } catch (Throwable $t) {
        echo "<h1>{$code} — error</h1>";
    }
}

// Dev-only convenience: `PIPNIX_UI_DEV=1 php -S …` skips the login form.
// Guarded to the built-in server so it can never affect a real deployment.
if (PHP_SAPI === 'cli-server' && getenv('PIPNIX_UI_DEV') === '1'
    && empty($_SESSION['user'])
    && !str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/login')
    && !str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/logout')) {
    $_SESSION['user'] = ['id' => 0, 'username' => 'dev', 'role' => 'admin'];
}

// ---------------------------------------------------------------- helpers
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    $base = rtrim((string) Env::cfg('ui.base_path', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

// ---------------------------------------------------------------- branding
// Everything user-facing reads the name/logo from here, so a rebrand is a
// config change rather than a search-and-replace. Defaults live in
// app/bootstrap.php (PN_BRAND / PN_TAGLINE).

function brand_name(): string
{
    return (string) Env::cfg('ui.title', PN_BRAND);
}

function brand_tagline(): string
{
    return (string) Env::cfg('ui.tagline', PN_TAGLINE);
}

function brand_logo(): string
{
    return (string) Env::cfg('ui.logo', '/assets/logo-white.png');
}

function brand_icon(): string
{
    return (string) Env::cfg('ui.icon', '/assets/app-icon-32.png');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
        render_error_page(419, 'Session expired',
            'The form token did not match — go back, reload the page and try again.');
        exit;
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        redirect('/login');
    }
}

function require_role(string $role): void
{
    $user = current_user();
    $order = ['viewer' => 0, 'operator' => 1, 'admin' => 2];
    if (!$user || ($order[$user['role']] ?? -1) < ($order[$role] ?? 99)) {
        http_response_code(403);
        render('error', ['code' => 403, 'title' => 'No access',
            'message' => 'Your role cannot open this page.'], 'bare');
        exit;
    }
}

/** Render a view inside the shell (or standalone for login/error). */
function render(string $view, array $data = [], string $layout = 'shell'): void
{
    $viewFile = __DIR__ . '/views/' . $view . '.php';
    $layoutFile = __DIR__ . '/views/' . $layout . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        exit('view missing: ' . e($view));
    }
    $data['user'] = current_user();
    $data['flash'] = take_flash();
    extract($data, EXTR_SKIP);
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    require $layoutFile;
}

function flash(string $message, string $kind = 'ok'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- helpers (web)
/** Minimum role required for a UI action. */
function can(string $action): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    $order = ['viewer' => 0, 'operator' => 1, 'admin' => 2];
    $need = [
        'trigger' => 'operator', 'pause' => 'operator', 'clear' => 'operator',
        'mark' => 'operator',
        'edit' => 'admin', 'import' => 'admin', 'delete' => 'admin',
        'venv' => 'admin', 'users' => 'admin', 'audit' => 'admin',
    ];
    return ($order[$user['role']] ?? -1) >= ($order[$need[$action] ?? 'admin'] ?? 2);
}

function client_ip(): ?string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return null;
}

function audit(string $action, string $object): void
{
    $user = current_user();
    Audit::record($user['username'] ?? 'system', $action, $object, client_ip());
}

function slug(string $value): string
{
    return strtolower(trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?? ''));
}

/** Run `php app/ops.php ...` detached, output appended to a log file. @return int pid */
function spawn_ops(array $args, string $logFile): int
{
    $cmd = escapeshellarg(Env::phpCli()) . ' ' . escapeshellarg(PN_APP . '/ops.php');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string) $arg);
    }
    @touch($logFile);
    $full = 'nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
    return (int) trim((string) @shell_exec($full));
}

/** Run `php app/ops.php ...` synchronously with a deadline. @return array{0:int,1:string} */
function run_ops_sync(array $args, int $timeout = 90): array
{
    $cmd = array_merge([Env::phpCli(), PN_APP . '/ops.php'], $args);
    $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes, PN_BASE);
    if (!is_resource($proc)) {
        return [127, 'cannot start ops'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = microtime(true) + $timeout;
    $exit = null;
    $timedOut = false;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $exit = (int) ($st['exitcode'] ?? -1);
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            @proc_terminate($proc, 9);
            $out .= "\n[" . PN_BRAND . "] timed out after {$timeout}s";
            break;
        }
        usleep(50000);
    }
    $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    // PHP < 8.3: proc_close() returns -1 once proc_get_status() cached the
    // exit code (bugs.php.net #75272). Keep the real code, so web actions do
    // not report "failed" for a child that succeeded.
    $rc = Env::settleExit($rc, $exit);
    if ($timedOut) {
        $rc = 124;
    }
    return [$rc, $out];
}

function venv_log_path(string $name): string
{
    return Env::logs() . '/venv-' . slug($name) . '.log';
}

// ---------------------------------------------------------------- helpers (data)
function state_class(string $state): string
{
    return match ($state) {
        'success' => 'ok',
        'failed', 'timed_out' => 'bad',
        'running' => 'run',
        'upstream_failed' => 'warn',
        'skipped' => 'skip',
        default => 'idle',
    };
}

function state_label(string $state): string
{
    return ucfirst(str_replace('_', ' ', $state));
}

function human_duration(?string $started, ?string $finished): string
{
    if (!$started) {
        return '—';
    }
    $from = strtotime($started);
    $to = $finished ? strtotime($finished) : time();
    $sec = max(0, $to - $from);
    if ($sec < 60) {
        return $sec . 's';
    }
    if ($sec < 3600) {
        return floor($sec / 60) . 'm ' . ($sec % 60) . 's';
    }
    return floor($sec / 3600) . 'h ' . floor(($sec % 3600) / 60) . 'm';
}

function human_schedule(array $dag): string
{
    if ($dag['schedule_type'] === 'interval') {
        return 'every ' . (int) $dag['schedule'] . ' min';
    }
    return cron_human((string) $dag['schedule']);
}

/** Turn the common cron shapes into a sentence; fall back to the expression. */
function cron_human(string $expr): string
{
    $expr = trim($expr);
    if ($expr === '* * * * *') {
        return 'every minute';
    }
    if (preg_match('#^\*/(\d+) \* \* \* \*$#', $expr, $m)) {
        return 'every ' . (int) $m[1] . ' minutes';
    }
    $parts = preg_split('/\s+/', $expr) ?: [];
    if (count($parts) !== 5) {
        return $expr;
    }
    [$min, $hour, $dom, $mon, $dow] = $parts;
    $time = (ctype_digit($min) && ctype_digit($hour))
        ? sprintf('%02d:%02d', (int) $hour, (int) $min)
        : null;
    $days = ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday',
             '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday'];

    if ($time !== null && $dom === '*' && $mon === '*' && $dow === '*') {
        return 'daily at ' . $time;
    }
    if ($time !== null && $dom === '*' && $mon === '*' && ctype_digit($dow)) {
        return 'weekly on ' . ($days[$dow] ?? ('day ' . $dow)) . ' at ' . $time;
    }
    if ($time !== null && ctype_digit($dom) && $mon === '*' && $dow === '*') {
        return 'monthly on day ' . (int) $dom . ' at ' . $time;
    }
    return 'cron ' . $expr . ($time !== null ? ' (' . $time . ')' : '');
}

function next_run_text(array $dag): string
{
    if ((int) $dag['is_active'] !== 1) {
        return 'paused';
    }
    if ($dag['schedule_type'] === 'interval') {
        return $dag['next_run_at'] ? substr((string) $dag['next_run_at'], 0, 16) : '—';
    }
    $next = Cron::nextRun((string) $dag['schedule'], new DateTimeImmutable('now'));
    return $next ? $next->format('Y-m-d H:i') : '—';
}

function tail_lines(string $path, int $limit = 400): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    return array_slice($lines, -$limit);
}

// ---------------------------------------------------------------- mini markdown
/**
 * Render the bundled guides (docs/*.md) to HTML. Supports the subset used by
 * those files: headings, fenced code, pipe tables, lists, rules, inline
 * code/bold/links. Everything is HTML-escaped first, so the content can never
 * inject markup.
 */
function md_to_html(string $md): string
{
    $lines = preg_split('/\R/', trim($md)) ?: [];
    $out = [];
    $inCode = false;
    $code = [];
    $list = null;        // 'ul' | 'ol' | null
    $listItems = [];
    $table = [];         // collected rows of the current table

    $flushList = static function () use (&$list, &$listItems, &$out): void {
        if ($list === null) {
            return;
        }
        $out[] = '<' . $list . '>';
        foreach ($listItems as $item) {
            $out[] = '<li>' . md_inline($item) . '</li>';
        }
        $out[] = '</' . $list . '>';
        $list = null;
        $listItems = [];
    };
    $flushTable = static function () use (&$table, &$out): void {
        if ($table === []) {
            return;
        }
        $out[] = '<table class="md-table"><thead><tr>';
        foreach ($table[0] as $cell) {
            $out[] = '<th>' . md_inline($cell) . '</th>';
        }
        $out[] = '</tr></thead><tbody>';
        foreach (array_slice($table, 1) as $row) {
            $out[] = '<tr>';
            foreach ($row as $cell) {
                $out[] = '<td>' . md_inline($cell) . '</td>';
            }
            $out[] = '</tr>';
        }
        $out[] = '</tbody></table>';
        $table = [];
    };

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '```')) {
            if ($inCode) {
                $out[] = '</code></pre>';
                $inCode = false;
            } else {
                $flushList();
                $flushTable();
                $out[] = '<pre class="log"><code>';
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $out[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            continue;
        }

        $t = trim($line);
        if ($t === '') {
            // keep an open list across blank lines (numbering must survive),
            // but close the current table
            $flushTable();
            continue;
        }
        if (str_starts_with($t, '|') && str_ends_with($t, '|')) {
            $cells = array_map('trim', explode('|', trim($t, '|')));
            if (isset($table[0]) && preg_match('/^:?-{3,}:?$/', str_replace(' ', '', $cells[0] ?? ''))) {
                continue; // separator row
            }
            $table[] = $cells;
            continue;
        }
        $flushTable();

        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $flushList();
            $level = strlen($m[1]);
            $out[] = '<h' . $level . '>' . md_inline($m[2]) . '</h' . $level . '>';
            continue;
        }
        if ($t === '---') {
            $flushList();
            $out[] = '<hr>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            if ($list !== 'ul') {
                $flushList();
                $list = 'ul';
            }
            $listItems[] = $m[1];
            continue;
        }
        if (preg_match('/^\d+\.\s+(.*)$/', $t, $m)) {
            if ($list !== 'ol') {
                $flushList();
                $list = 'ol';
            }
            $listItems[] = $m[1];
            continue;
        }
        // indented continuation of the previous list item (soft wrap)
        if ($list !== null && preg_match('/^\s{2,}\S/', $line)) {
            $listItems[count($listItems) - 1] .= ' ' . $t;
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $flushList();
            $out[] = '<blockquote>' . md_inline($m[1]) . '</blockquote>';
            continue;
        }
        $flushList();
        $out[] = '<p>' . md_inline($t) . '</p>';
    }
    if ($inCode) {
        $out[] = '</code></pre>';
    }
    $flushList();
    $flushTable();
    return implode("\n", $out);
}

/** Inline markdown (already-escaped text in, safe HTML out). */
function md_inline(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a class="link" href="$2">$1</a>', $text) ?? $text;
    return $text;
}
