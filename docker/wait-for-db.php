#!/usr/bin/env php
<?php
/**
 * Block until MySQL accepts connections (used by the container entrypoint).
 *
 * Compose's depends_on covers the common case, but MySQL first-boot
 * initialization can take a while and a naive start fails on "connection
 * refused"; this retries with a clear message instead.
 */
declare(strict_types=1);

$host = getenv('PIPNIX_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PIPNIX_DB_PORT') ?: 3306);
$name = getenv('PIPNIX_DB_NAME') ?: 'pipnix';
$user = getenv('PIPNIX_DB_USER') ?: 'pipnix';
$pass = getenv('PIPNIX_DB_PASS') ?: '';
$wait = (int) (getenv('PIPNIX_DB_WAIT') ?: 90);

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
$deadline = microtime(true) + $wait;
$last = '';

for (;;) {
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');
        fwrite(STDOUT, "[smilir] database: ready ({$host}:{$port}/{$name})\n");
        exit(0);
    } catch (Throwable $e) {
        $last = $e->getMessage();
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "[smilir] database: not reachable after {$wait}s — {$last}\n");
            exit(1);
        }
        sleep(1);
    }
}
