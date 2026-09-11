<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private PDO $db,
        private string $directory
    ) {
    }

    public function migrate(): void
    {
        $this->ensureMigrationTable();

        $files = glob(rtrim($this->directory, '/\\') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration)) {
                throw new RuntimeException('Migration invalide : ' . basename($file));
            }

            $version = trim((string)($migration['version'] ?? ''));
            $up = $migration['up'] ?? null;
            if ($version === '' || !is_callable($up)) {
                throw new RuntimeException('Migration incomplète : ' . basename($file));
            }
            if ($this->applied($version)) {
                continue;
            }

            $this->db->exec('BEGIN IMMEDIATE');
            try {
                if ($this->applied($version)) {
                    $this->db->exec('COMMIT');
                    continue;
                }

                $up($this->db);
                $mark = $this->db->prepare(
                    'INSERT INTO schema_migrations(version) VALUES(:version)'
                );
                $mark->execute(['version' => $version]);
                $this->db->exec('COMMIT');
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->exec('ROLLBACK');
                }
                throw $e;
            }
        }
    }

    private function ensureMigrationTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    private function applied(string $version): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM schema_migrations WHERE version=:version LIMIT 1'
        );
        $stmt->execute(['version' => $version]);
        return $stmt->fetchColumn() !== false;
    }
}
