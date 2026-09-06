<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class ArchiveService
{
    private string $databasePath;
    private string $archiveDir;

    /** @var string[] */
    private array $studentDataTables = [
        'attempt_answers',
        'attempt_questions',
        'student_badges',
        'attempts',
        'student_login_history',
        'students',
    ];

    public function __construct()
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/config/config.php';
        $this->databasePath = (string)$config['database'];
        $this->archiveDir = dirname($this->databasePath) . '/archives';
    }

    /**
     * Archive la base courante puis recrée current.sqlite à partir de cette archive
     * en supprimant uniquement les élèves et leurs données dépendantes.
     *
     * Toutes les autres tables et leurs données sont conservées à l'identique.
     *
     * @return array{archive:string,archive_path:string,removed_tables:string[]}
     */
    public function archiveAndResetStudents(): array
    {
        if (!is_file($this->databasePath)) {
            throw new RuntimeException('La base current.sqlite est introuvable.');
        }

        if (!is_dir($this->archiveDir)
            && !mkdir($this->archiveDir, 0775, true)
            && !is_dir($this->archiveDir)) {
            throw new RuntimeException('Impossible de créer le dossier database/archives.');
        }

        $archiveName = date('Y-m-d') . '.sqlite';
        $archivePath = $this->archiveDir . '/' . $archiveName;

        if (file_exists($archivePath)) {
            throw new RuntimeException(
                'Une archive existe déjà pour aujourd’hui : ' . $archiveName .
                '. Aucune donnée n’a été modifiée.'
            );
        }

        $token = bin2hex(random_bytes(6));
        $nextPath = dirname($this->databasePath) . '/.next-' . $token . '.sqlite';
        $oldPath = dirname($this->databasePath) . '/.old-' . $token . '.sqlite';
        $archiveCreated = false;
        $swapCompleted = false;

        try {
            $source = $this->open($this->databasePath);
            $source->exec('PRAGMA wal_checkpoint(FULL)');

            // VACUUM INTO produit une copie SQLite cohérente, y compris lorsque WAL est actif.
            $source->exec('VACUUM INTO ' . $source->quote($archivePath));
            $source = null;
            $archiveCreated = true;

            if (!is_file($archivePath) || filesize($archivePath) === 0) {
                throw new RuntimeException('La création de l’archive SQLite a échoué.');
            }

            if (!copy($archivePath, $nextPath)) {
                throw new RuntimeException('Impossible de préparer la nouvelle base current.sqlite.');
            }

            $next = $this->open($nextPath);
            $next->beginTransaction();

            foreach ($this->studentDataTables as $table) {
                if ($this->tableExists($next, $table)) {
                    $next->exec('DELETE FROM ' . $this->quoteIdentifier($table));
                }
            }

            // Les tables AUTOINCREMENT propres aux élèves repartent proprement pour la nouvelle année.
            if ($this->tableExists($next, 'sqlite_sequence')) {
                $stmt = $next->prepare('DELETE FROM sqlite_sequence WHERE name = :name');
                foreach ($this->studentDataTables as $table) {
                    $stmt->execute(['name' => $table]);
                }
            }

            $violations = $next->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($violations !== []) {
                throw new RuntimeException('La nouvelle base contient une incohérence de clés étrangères.');
            }

            $integrity = (string)$next->query('PRAGMA integrity_check')->fetchColumn();
            if (strtolower(trim($integrity)) !== 'ok') {
                throw new RuntimeException('Échec du contrôle d’intégrité de la nouvelle base : ' . $integrity);
            }

            $next->commit();
            $next = null;

            // Retirer les fichiers WAL résiduels de l’ancienne base avant l’échange.
            @unlink($this->databasePath . '-wal');
            @unlink($this->databasePath . '-shm');

            if (!rename($this->databasePath, $oldPath)) {
                throw new RuntimeException('Impossible de mettre de côté l’ancienne base avant remplacement.');
            }

            if (!rename($nextPath, $this->databasePath)) {
                @rename($oldPath, $this->databasePath);
                throw new RuntimeException('Impossible d’installer la nouvelle base current.sqlite.');
            }

            $swapCompleted = true;
            @unlink($oldPath);

            return [
                'archive' => $archiveName,
                'archive_path' => $archivePath,
                'removed_tables' => $this->studentDataTables,
            ];
        } catch (Throwable $e) {
            @unlink($nextPath);

            if (is_file($oldPath) && !is_file($this->databasePath)) {
                @rename($oldPath, $this->databasePath);
            }

            // Une opération échouée ne doit pas bloquer une nouvelle tentative le même jour.
            if ($archiveCreated && !$swapCompleted && is_file($this->databasePath)) {
                @unlink($archivePath);
            }

            throw $e;
        }
    }

    /** @return array<int,array{name:string,size:int,modified:int}> */
    public function listArchives(): array
    {
        if (!is_dir($this->archiveDir)) {
            return [];
        }

        $files = glob($this->archiveDir . '/*.sqlite') ?: [];
        rsort($files, SORT_STRING);

        $result = [];
        foreach ($files as $path) {
            $result[] = [
                'name' => basename($path),
                'size' => (int)(filesize($path) ?: 0),
                'modified' => (int)(filemtime($path) ?: 0),
            ];
        }
        return $result;
    }

    private function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
        $stmt->execute(['name' => $table]);
        return (bool)$stmt->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
