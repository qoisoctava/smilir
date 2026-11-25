<?php
declare(strict_types=1);

/**
 * Built-in updater — pulls source archives from GitHub.
 *
 * Trust model: this replaces PHP files, so it is admin-only, requires CSRF,
 * and only ever talks to https://api.github.com / codeload.github.com. A
 * private fork can set updater.token in app/config.php (never the UI).
 *
 * Safety rails:
 *  - user data is never touched: app/config.php, .env, dags/, jobs/, logs/,
 *    storage/ and .git/ are excluded from an update
 *  - every overwritten file is first copied to storage/updates/backup-<ts>/,
 *    and `update:rollback` restores the newest backup
 *  - each file is replaced through a temp file + rename, so a request never
 *    reads a half-written file
 *  - schema migrations run through the idempotent install.php afterwards
 *
 * Channels:
 *  - "release": the newest GitHub release tag (recommended once tags exist)
 *  - "branch":  the newest commit of a branch (works before the first release)
 */
final class Updater
{
    public const CACHE_TTL = 600;      // 10 minutes
    public const MAX_ARCHIVE = 52428800; // 50 MB
    private const KEEP_BACKUPS = 3;

    // ------------------------------------------------------------- settings
    public static function config(): array
    {
        $pick = static function (string $key, mixed $default): mixed {
            try {
                $stored = Settings::get('updater.' . $key);
            } catch (Throwable) {
                $stored = null;
            }
            return ($stored === null || $stored === '') ? Env::cfg('updater.' . $key, $default) : $stored;
        };

        return [
            'enabled'  => (bool) $pick('enabled', true),
            'repo'     => (string) $pick('repo', 'qoisoctava/smilir'),
            'channel'  => (string) $pick('channel', 'branch'),
            'branch'   => (string) $pick('branch', 'main'),
            'token'    => (string) Env::cfg('updater.token', ''),
            'allow_docker' => (bool) Env::cfg('updater.allow_docker', false),
        ];
    }

    public static function isDocker(): bool
    {
        return is_file('/.dockerenv');
    }

    public static function dockerBlocked(): bool
    {
        return self::isDocker() && !self::config()['allow_docker'];
    }

    /** @return string|null a reason when checking GitHub is unavailable */
    public static function checkUnavailableReason(): ?string
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return 'The updater is disabled (Admin → Settings → Updates).';
        }
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $cfg['repo'])) {
            return 'Set a valid GitHub repository as owner/name.';
        }
        return null;
    }

    /** @return string|null a reason applying file updates is unavailable */
    public static function applyUnavailableReason(): ?string
    {
        if (($reason = self::checkUnavailableReason()) !== null) {
            return $reason;
        }
        if (self::dockerBlocked()) {
            return 'Running in Docker: rebuild the image instead (docker compose build --pull && docker compose up -d). Self-update is disabled unless updater.allow_docker is true.';
        }
        return null;
    }

    /** Backwards-compatible alias for operations that mutate installed files. */
    public static function unavailableReason(): ?string
    {
        return self::applyUnavailableReason();
    }

    // ---------------------------------------------------------------- state
    public static function state(): array
    {
        $data = self::readJson(self::dir() . '/state.json');
        return is_array($data) ? $data : [];
    }

    public static function saveState(array $state): void
    {
        self::writeJson(self::dir() . '/state.json', $state);
    }

    public static function localCommit(): ?string
    {
        $commit = trim((string) (self::state()['commit'] ?? ''));
        if ($commit !== '') {
            return $commit;
        }
        if (is_dir(PN_BASE . '/.git') && function_exists('shell_exec')) {
            $out = @shell_exec('git -C ' . escapeshellarg(PN_BASE) . ' rev-parse HEAD 2>/dev/null');
            $out = trim((string) $out);
            if (preg_match('/^[0-9a-f]{40}$/', $out)) {
                return $out;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- check
    public static function cached(): ?array
    {
        $data = self::readJson(self::dir() . '/check.json');
        return is_array($data) ? $data : null;
    }

    /** @return array{ok:bool,error?:string,update?:bool,...} */
    public static function check(bool $force = false): array
    {
        if (($reason = self::checkUnavailableReason()) !== null) {
            return ['ok' => false, 'error' => $reason];
        }
        if (!$force && ($cached = self::cached()) !== null
            && (time() - (int) ($cached['checked_at'] ?? 0)) < self::CACHE_TTL) {
            return $cached + ['cached' => true];
        }

        $cfg = self::config();
        $cfg['channel'] = strtolower($cfg['channel']) === 'release' ? 'release' : 'branch';
        $base = 'https://api.github.com/repos/' . $cfg['repo'];

        if ($cfg['channel'] === 'release') {
            [$code, $body, $err] = self::http($base . '/releases/latest');
            if ($code === 404) {
                return ['ok' => false, 'error' => 'No GitHub release yet — publish a tag (e.g. v1.0.0) or switch the channel to "branch".'];
            }
            if ($code !== 200) {
                return ['ok' => false, 'error' => 'GitHub API ' . $code . ($err !== '' ? ': ' . $err : '')];
            }
            $rel = json_decode($body, true);
            if (!is_array($rel) || empty($rel['tag_name'])) {
                return ['ok' => false, 'error' => 'Unexpected GitHub response.'];
            }
            $remote = [
                'version' => self::normalizeVersion((string) $rel['tag_name']),
                'commit'  => null,
                'date'    => (string) ($rel['published_at'] ?? ''),
                'title'   => (string) ($rel['name'] ?? $rel['tag_name']),
                'notes'   => (string) ($rel['body'] ?? ''),
                'url'     => (string) ($rel['html_url'] ?? ''),
                'archive'     => (string) ($rel['zipball_url'] ?? ''),
                'archive_tar' => (string) ($rel['tarball_url'] ?? ''),
            ];
            $localVersion = (string) (self::state()['version'] ?? PN_VERSION);
            $update = version_compare($remote['version'], self::normalizeVersion($localVersion), '>');
        } else {
            [$code, $body, $err] = self::http($base . '/commits/' . rawurlencode($cfg['branch']));
            if ($code === 404) {
                return ['ok' => false, 'error' => 'Branch not found: ' . $cfg['branch']];
            }
            if ($code !== 200) {
                return ['ok' => false, 'error' => 'GitHub API ' . $code . ($err !== '' ? ': ' . $err : '')];
            }
            $commit = json_decode($body, true);
            $sha = (string) ($commit['sha'] ?? '');
            if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
                return ['ok' => false, 'error' => 'Unexpected GitHub response.'];
            }
            $remote = [
                'version' => $cfg['branch'] . '@' . substr($sha, 0, 7),
                'commit'  => $sha,
                'date'    => (string) ($commit['commit']['author']['date'] ?? ''),
                'title'   => trim((string) strtok((string) ($commit['commit']['message'] ?? ''), "\n")),
                'notes'   => '',
                'url'     => 'https://github.com/' . $cfg['repo'] . '/commit/' . $sha,
                'archive'     => 'https://codeload.github.com/' . $cfg['repo'] . '/zip/' . $sha,
                'archive_tar' => 'https://codeload.github.com/' . $cfg['repo'] . '/tar.gz/' . $sha,
            ];
            // A plain "SHA differs" test cannot tell ahead from behind, so ask
            // GitHub to compare. If the local commit is not on GitHub at all
            // (unpushed checkout), do not offer a downgrade.
            $local = self::localCommit();
            $relation = 'unknown';
            $update = true;
            if ($local !== null && hash_equals($local, $sha)) {
                $relation = 'identical';
                $update = false;
            } elseif ($local !== null) {
                [$cmpCode, $cmpBody] = self::http($base . '/compare/' . $sha . '...' . $local);
                if ($cmpCode === 200) {
                    $cmp = json_decode($cmpBody, true);
                    $relation = (string) ($cmp['status'] ?? 'unknown');
                    $update = in_array($relation, ['behind', 'diverged'], true);
                } else {
                    $relation = 'local-only';
                    $update = false;
                }
            }
        }

        $result = [
            'ok' => true,
            'checked_at' => time(),
            'channel' => $cfg['channel'],
            'repo' => $cfg['repo'],
            'current' => [
                'version' => (string) (self::state()['version'] ?? PN_VERSION),
                'commit' => self::localCommit(),
            ],
            'remote' => $remote,
            'relation' => $relation ?? null,
            'update' => $update,
        ];
        self::writeJson(self::dir() . '/check.json', $result);
        return $result;
    }

    // -------------------------------------------------------------- download
    /** @return array{0:?string,1:?string} [archive path, error] */
    public static function download(array $remote): array
    {
        $zip = class_exists('ZipArchive');
        $url = (string) ($zip ? ($remote['archive'] ?? '') : ($remote['archive_tar'] ?? ''));
        if ($url === '') {
            return [null, 'No archive URL in the GitHub response.'];
        }
        $ext = $zip ? 'zip' : 'tar.gz';
        $dest = self::dir() . '/download-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
        [$code, , $err] = self::http($url, $dest, self::MAX_ARCHIVE);
        if ($code !== 200) {
            @unlink($dest);
            return [null, 'Download failed (HTTP ' . $code . ')' . ($err !== '' ? ': ' . $err : '')];
        }
        if (!is_file($dest) || filesize($dest) < 1024) {
            @unlink($dest);
            return [null, 'Downloaded archive is empty.'];
        }
        return [$dest, null];
    }

    // ----------------------------------------------------------------- apply
    /**
     * Apply an already-downloaded archive (release zip or tarball).
     * $workDir overrides storage/updates (used by tests).
     */
    public static function applyArchiveTo(string $archive, string $base, bool $migrate = true,
                                          array $meta = [], ?string $workDir = null): array
    {
        $base = rtrim($base, '/');
        $work = rtrim($workDir ?? self::dir(), '/');
        if (!is_dir($work) && !@mkdir($work, 0775, true) && !is_dir($work)) {
            return ['ok' => false, 'error' => 'Cannot create ' . $work];
        }

        foreach ([$base, $base . '/app', $base . '/public'] as $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                return ['ok' => false, 'error' => $dir . ' is not writable by the web server.'];
            }
        }

        $stage = $work . '/extract-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $root = self::extract($archive, $stage);
        if ($root === null) {
            self::removeTree($stage);
            return ['ok' => false, 'error' => 'Could not unpack the archive (zip/Phar support missing?).'];
        }

        $backup = $work . '/backup-' . date('Ymd-His');
        if (!is_dir($backup) && !@mkdir($backup, 0775, true) && !is_dir($backup)) {
            self::removeTree($stage);
            return ['ok' => false, 'error' => 'Cannot create the backup directory.'];
        }

        // Uploads have no commit metadata; take the version from the archive.
        if ($meta !== [] && empty($meta['version']) && is_file($root . '/app/bootstrap.php')) {
            if (preg_match("/PN_VERSION',\s*'([^']+)'/",
                    (string) file_get_contents($root . '/app/bootstrap.php'), $vm)) {
                $meta['version'] = $vm[1];
            }
        }
        if ($meta !== []) {
            $meta['version'] = (string) ($meta['version'] ?? PN_VERSION);
            $meta['channel'] = (string) ($meta['channel'] ?? 'upload');
        }

        $copied = 0;
        $skipped = 0;
        $failed = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if ($rel === '' || self::isProtectedPath($rel)) {
                $skipped++;
                continue;
            }
            $target = $base . '/' . $rel;
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $failed[] = $rel;
                continue;
            }
            if (is_file($target)) {
                $bdir = dirname($backup . '/' . $rel);
                if (!is_dir($bdir)) {
                    @mkdir($bdir, 0775, true);
                }
                @copy($target, $backup . '/' . $rel);
            }
            if (!self::replaceFile($file->getPathname(), $target)) {
                $failed[] = $rel;
            } else {
                $copied++;
            }
        }

        self::writeJson($backup . '/meta.json', [
            'from' => self::state(),
            'to' => $meta,
            'applied_at' => Env::now(),
            'copied' => $copied,
            'failed' => count($failed),
        ]);
        self::removeTree($stage);
        self::pruneBackups($work);

        $migrateRc = null;
        $migrateOut = '';
        if ($migrate && $failed === []) {
            [$migrateRc, $migrateOut] = self::migrate();
        }
        $migrateFailed = $migrateRc !== null && $migrateRc !== 0;
        $ok = $failed === [] && !$migrateFailed;

        // New normalisation rules must reach DAGs whose source did not change.
        try {
            Db::run('UPDATE pn_dag SET source_hash = NULL');
        } catch (Throwable) {
            // pre-migration / no DB in unit tests
        }

        if ($workDir === null) {
            self::log(sprintf('apply: %d copied, %d skipped, %d failed, migrations rc=%s',
                $copied, $skipped, count($failed), $migrateRc === null ? 'n/a' : (string) $migrateRc));
        }

        return [
            'ok' => $ok,
            'meta' => $meta,
            'copied' => $copied,
            'skipped' => $skipped,
            'failed' => $failed,
            'backup' => $backup,
            'migrate_rc' => $migrateRc,
            'migrate_out' => trim($migrateOut),
            'error' => $failed !== []
                ? count($failed) . ' file(s) could not be replaced'
                : ($migrateFailed ? 'schema migrations failed (rc=' . $migrateRc . ')' : null),
        ];
    }

    public static function isProtectedPath(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') {
            return true;
        }
        if ($rel === '.env' || $rel === '.env.local' || $rel === 'app/config.php') {
            return true;
        }
        foreach (['.git/', 'logs/', 'storage/', 'dags/', 'jobs/', 'dist/', 'venvs/'] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------- rollback
    /** @return array{ok:bool,error?:string,restored?:int,...} */
    public static function rollback(): array
    {
        $dirs = glob(self::dir() . '/backup-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach ($dirs as $backup) {
            $meta = self::readJson($backup . '/meta.json');
            if (!is_array($meta) || !empty($meta['rolled_back'])) {
                continue;
            }
            $restored = 0;
            $failed = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backup, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile() || $file->getFilename() === 'meta.json') {
                    continue;
                }
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($backup))), '/');
                if (self::isProtectedPath($rel)) {
                    continue;
                }
                $target = PN_BASE . '/' . $rel;
                $dir = dirname($target);
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    $failed[] = $rel;
                    continue;
                }
                if (!self::replaceFile($file->getPathname(), $target)) {
                    $failed[] = $rel;
                } else {
                    $restored++;
                }
            }
            [$rc, $out] = self::migrate();

            $meta['rolled_back'] = Env::now();
            $meta['rollback_restored'] = $restored;
            self::writeJson($backup . '/meta.json', $meta);

            $from = is_array($meta['from'] ?? null) ? $meta['from'] : [];
            if ($from !== []) {
                self::saveState($from + ['rolled_back_at' => Env::now()]);
            } else {
                @unlink(self::dir() . '/state.json');
            }

            try {
                Db::run('UPDATE pn_dag SET source_hash = NULL');
            } catch (Throwable) {
            }

            self::log(sprintf('rollback: %d restored, %d failed, migrations rc=%d',
                $restored, count($failed), $rc));

            return [
                'ok' => $failed === [],
                'restored' => $restored,
                'failed' => $failed,
                'migrate_rc' => $rc,
                'migrate_out' => trim($out),
                'meta' => $meta,
                'error' => $failed === [] ? null : count($failed) . ' file(s) could not be restored',
            ];
        }
        return ['ok' => false, 'error' => 'No backup available to roll back.'];
    }

    public static function lastBackupMeta(): ?array
    {
        $dirs = glob(self::dir() . '/backup-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach ($dirs as $backup) {
            $meta = self::readJson($backup . '/meta.json');
            if (is_array($meta) && empty($meta['rolled_back'])) {
                $meta['dir'] = basename($backup);
                return $meta;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ lock
    public static function isRunning(): bool
    {
        $file = self::dir() . '/.lock';
        if (!is_file($file)) {
            return false;
        }
        $data = self::readJson($file);
        $started = (int) ($data['started_at'] ?? filemtime($file) ?? 0);
        if (time() - $started > 900) {
            @unlink($file);
            return false;
        }
        return true;
    }

    public static function lock(string $what): bool
    {
        if (self::isRunning()) {
            return false;
        }
        $file = self::dir() . '/.lock';
        self::writeJson($file, ['pid' => getmypid(), 'what' => $what, 'started_at' => time()]);
        register_shutdown_function(static function () use ($file): void {
            @unlink($file);
        });
        return true;
    }

    // ------------------------------------------------------------------- log
    public static function log(string $line): void
    {
        @file_put_contents(self::dir() . '/update.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
    }

    /** @return string[] */
    public static function logTail(int $lines = 120): array
    {
        $file = self::dir() . '/update.log';
        if (!is_file($file)) {
            return [];
        }
        $all = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        return array_slice($all, -$lines);
    }

    // ------------------------------------------------------------- internals
    public static function dir(): string
    {
        $dir = Env::path('storage/updates');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /** @return array{0:int,1:string,2:string} [http code, body, error] */
    private static function http(string $url, ?string $dest = null, int $maxBytes = 2097152): array
    {
        $headers = ['User-Agent: ' . PN_BRAND . '-updater/' . PN_VERSION, 'Accept: application/vnd.github+json'];
        $token = trim((string) Env::cfg('updater.token', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if (function_exists('curl_init')) {
            $fh = $dest !== null ? @fopen($dest, 'wb') : null;
            if ($dest !== null && $fh === false) {
                return [0, '', 'cannot write ' . $dest];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RETURNTRANSFER => $dest === null,
            ]);
            if ($fh !== null) {
                curl_setopt($ch, CURLOPT_FILE, $fh);
            }
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string) curl_error($ch);
            curl_close($ch);
            if ($fh !== null) {
                fclose($fh);
            }
            if ($ok === false) {
                if ($dest !== null) {
                    @unlink($dest);
                }
                return [0, '', $err];
            }
            if ($dest !== null) {
                if ((int) @filesize($dest) > $maxBytes) {
                    @unlink($dest);
                    return [0, '', 'archive exceeds the size limit'];
                }
                return [$code, '', ''];
            }
            $body = (string) $ok;
            if (strlen($body) > $maxBytes) {
                return [0, '', 'response too large'];
            }
            return [$code, $body, ''];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 180,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return [0, '', 'request failed'];
        }
        $code = 200;
        if (isset($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($dest !== null) {
            if (@file_put_contents($dest, $body) === false) {
                return [0, '', 'cannot write ' . $dest];
            }
            return [$code, '', ''];
        }
        return [$code, $body, ''];
    }

    /** @return string|null extracted root (the dir containing app/) */
    private static function extract(string $archive, string $stage): ?string
    {
        if (!is_dir($stage) && !@mkdir($stage, 0775, true) && !is_dir($stage)) {
            return null;
        }
        $ok = false;
        if (class_exists('ZipArchive') && preg_match('/\.zip$/i', $archive)) {
            $zip = new ZipArchive();
            if ($zip->open($archive) === true) {
                $ok = $zip->extractTo($stage);
                $zip->close();
            }
        } else {
            try {
                $phar = new PharData($archive);
                $phar->extractTo($stage);
                $ok = true;
            } catch (Throwable) {
                $ok = false;
            }
        }
        if (!$ok) {
            return null;
        }
        if (is_file($stage . '/app/bootstrap.php')) {
            return $stage;
        }
        foreach ((array) glob($stage . '/*', GLOB_ONLYDIR) as $child) {
            if (is_file($child . '/app/bootstrap.php')) {
                return $child;
            }
        }
        return null;
    }

    private static function replaceFile(string $source, string $target): bool
    {
        $tmp = dirname($target) . '/.pnnew-' . bin2hex(random_bytes(4));
        if (!@copy($source, $tmp)) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** @return array{0:int,1:string} */
    private static function migrate(): array
    {
        $cmd = [Env::phpCli(), PN_APP . '/install.php'];
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, PN_BASE);
        if (!is_resource($proc)) {
            return [127, 'cannot start install.php'];
        }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    private static function pruneBackups(string $work): void
    {
        $dirs = glob($work . '/backup-*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach (array_slice($dirs, self::KEEP_BACKUPS) as $old) {
            self::removeTree($old);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private static function readJson(string $file): mixed
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private static function writeJson(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
