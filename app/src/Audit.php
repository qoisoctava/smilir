<?php
declare(strict_types=1);

/**
 * Audit trail for every state-changing action (web UI and CLI).
 */
final class Audit
{
    public static function record(string $actor, string $action, string $object, ?string $ip = null): void
    {
        try {
            Db::run(
                'INSERT INTO pn_audit (created_at, actor, action, object, ip) VALUES (?,?,?,?,?)',
                [Env::now(), substr($actor, 0, 64), substr($action, 0, 64),
                 substr($object, 0, 255), $ip !== null ? substr($ip, 0, 45) : null]
            );
        } catch (Throwable $e) {
            // auditing must never break the action itself
            error_log('[' . PN_BRAND . '] audit write failed: ' . $e->getMessage());
        }
    }

    /** @return array<int,array> newest first */
    public static function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Db::all("SELECT * FROM pn_audit ORDER BY id DESC LIMIT {$limit}");
    }
}
