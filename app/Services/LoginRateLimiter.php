<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class LoginRateLimiter
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 600;
    private const BLOCK_SECONDS = 600;

    public function __construct(private PDO $db)
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS login_rate_limits (
                key_hash TEXT PRIMARY KEY,
                failures INTEGER NOT NULL DEFAULT 0 CHECK(failures >= 0),
                first_failure_at INTEGER NOT NULL,
                blocked_until INTEGER,
                updated_at INTEGER NOT NULL
            )'
        );
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_login_rate_limits_updated ON login_rate_limits(updated_at)');
    }

    public function isBlocked(string $identifier, string $ip): bool
    {
        $now = time();
        $key = $this->key($identifier, $ip);
        $this->purgeOldRows($now);

        $stmt = $this->db->prepare('SELECT blocked_until FROM login_rate_limits WHERE key_hash=:key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $blockedUntil = $stmt->fetchColumn();

        return $blockedUntil !== false && $blockedUntil !== null && (int)$blockedUntil > $now;
    }

    public function registerFailure(string $identifier, string $ip): void
    {
        $now = time();
        $key = $this->key($identifier, $ip);

        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare(
                'SELECT failures,first_failure_at,blocked_until
                 FROM login_rate_limits
                 WHERE key_hash=:key
                 LIMIT 1'
            );
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || $now - (int)$row['first_failure_at'] > self::WINDOW_SECONDS) {
                $failures = 1;
                $firstFailureAt = $now;
            } else {
                $failures = (int)$row['failures'] + 1;
                $firstFailureAt = (int)$row['first_failure_at'];
            }

            $blockedUntil = $failures >= self::MAX_FAILURES ? $now + self::BLOCK_SECONDS : null;

            $upsert = $this->db->prepare(
                'INSERT INTO login_rate_limits(key_hash,failures,first_failure_at,blocked_until,updated_at)
                 VALUES(:key,:failures,:first_failure_at,:blocked_until,:updated_at)
                 ON CONFLICT(key_hash) DO UPDATE SET
                    failures=excluded.failures,
                    first_failure_at=excluded.first_failure_at,
                    blocked_until=excluded.blocked_until,
                    updated_at=excluded.updated_at'
            );
            $upsert->execute([
                'key' => $key,
                'failures' => $failures,
                'first_failure_at' => $firstFailureAt,
                'blocked_until' => $blockedUntil,
                'updated_at' => $now,
            ]);

            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public function clear(string $identifier, string $ip): void
    {
        $stmt = $this->db->prepare('DELETE FROM login_rate_limits WHERE key_hash=:key');
        $stmt->execute(['key' => $this->key($identifier, $ip)]);
    }

    private function key(string $identifier, string $ip): string
    {
        $normalizedIdentifier = mb_strtolower(trim($identifier));
        $normalizedIp = trim($ip) !== '' ? trim($ip) : 'unknown';
        return hash('sha256', $normalizedIp . "\n" . $normalizedIdentifier);
    }

    private function purgeOldRows(int $now): void
    {
        // Maintenance probabiliste pour éviter une écriture à chaque requête.
        if (random_int(1, 100) !== 1) {
            return;
        }

        $threshold = $now - 86400;
        $stmt = $this->db->prepare(
            'DELETE FROM login_rate_limits
             WHERE updated_at < :threshold
               AND (blocked_until IS NULL OR blocked_until < :now)'
        );
        $stmt->execute(['threshold' => $threshold, 'now' => $now]);
    }
}
