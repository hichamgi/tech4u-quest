<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $root = dirname(__DIR__, 2);
        $config = require $root . '/config/config.php';
        $path = $config['database'];

        self::initializeIfMissing($path, $root . '/database/schema.sql');

        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::$pdo->exec('PRAGMA foreign_keys = ON;');
        self::$pdo->exec('PRAGMA journal_mode = WAL;');
        self::$pdo->exec('PRAGMA busy_timeout = 5000;');

        return self::$pdo;
    }

    private static function initializeIfMissing(string $databasePath, string $schemaPath): void
    {
        if (is_file($databasePath)) {
            return;
        }

        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier de la base SQLite : ' . $directory);
        }

        if (!is_file($schemaPath) || !is_readable($schemaPath)) {
            throw new RuntimeException('Le fichier schema.sql est introuvable ou illisible : ' . $schemaPath);
        }

        $schema = file_get_contents($schemaPath);
        if ($schema === false || trim($schema) === '') {
            throw new RuntimeException('Le fichier schema.sql est vide ou illisible.');
        }

        try {
            $pdo = new PDO('sqlite:' . $databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $pdo->exec('PRAGMA foreign_keys = ON;');
            $pdo->exec($schema);
        } catch (\Throwable $e) {
            if (is_file($databasePath)) {
                @unlink($databasePath);
                @unlink($databasePath . '-wal');
                @unlink($databasePath . '-shm');
            }

            throw new RuntimeException(
                'Échec de la création automatique de la base SQLite : ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
