<?php
declare(strict_types=1);

/**
 * Console-editable settings, stored in pn_setting.
 *
 * Only values that make sense to change at runtime live here. Scheduler
 * plumbing (cron tick, lock name, timezone) stays in app/config.php — the
 * container writes that file from the environment.
 *
 * Every getter falls back to config/default so an install with an empty
 * pn_setting table behaves exactly like before.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            foreach (Db::all('SELECT k, v FROM pn_setting') as $row) {
                self::$cache[(string) $row['k']] = (string) $row['v'];
            }
        } catch (Throwable) {
            // Table missing (pre-migration): behave as if nothing is set.
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        return $value === null ? $default : ($value === '1' || $value === 'true');
    }

    public static function set(string $key, string $value): void
    {
        Db::run(
            'INSERT INTO pn_setting (k, v, updated_at) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)',
            [$key, $value, Env::now()]
        );
        self::$cache[$key] = $value;
    }

    /** Effective values shown on the Settings page. */
    public static function effective(): array
    {
        return [
            'alerts.enabled'  => self::bool('alerts.enabled', (bool) Env::cfg('alerts.enabled', false)),
            'alerts.from'     => (string) self::get('alerts.from', (string) Env::cfg('alerts.from', 'smilir@localhost')),
            'alerts.to'       => (string) self::get('alerts.to', implode(', ', (array) Env::cfg('alerts.to', []))),
            'retention.days'  => self::int('retention.days', (int) Env::cfg('retention.days', 30)),
            'task.retries'    => self::int('task.retries', (int) Env::cfg('task_defaults.retries', 0)),
            'task.retry_delay'=> self::int('task.retry_delay', (int) Env::cfg('task_defaults.retry_delay', 300)),
            'task.timeout'    => self::int('task.timeout', (int) Env::cfg('task_defaults.timeout', 0)),
            'task.email'      => self::bool('task.email', (bool) Env::cfg('task_defaults.email_on_failure', false)),
            'updater.enabled' => self::bool('updater.enabled', (bool) Env::cfg('updater.enabled', true)),
            'updater.repo'    => (string) self::get('updater.repo', (string) Env::cfg('updater.repo', 'qoisoctava/pipnix')),
            'updater.channel' => (string) self::get('updater.channel', (string) Env::cfg('updater.channel', 'branch')),
            'updater.branch'  => (string) self::get('updater.branch', (string) Env::cfg('updater.branch', 'main')),
        ];
    }

    /** Forget the cache (used after a bulk update in the same request). */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
