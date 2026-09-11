<?php
declare(strict_types=1);

return [
    'version' => '2026-09-10_login_rate_limit_v1',
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_rate_limits (
                key_hash TEXT PRIMARY KEY,
                failures INTEGER NOT NULL DEFAULT 0 CHECK(failures >= 0),
                first_failure_at INTEGER NOT NULL,
                blocked_until INTEGER,
                updated_at INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_login_rate_limits_updated
             ON login_rate_limits(updated_at)'
        );
    },
];
