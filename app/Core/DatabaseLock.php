<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Process-level lock protecting replacement of current.sqlite.
 *
 * Normal database users hold a shared lock for the lifetime of the PDO
 * connection. Annual archival takes an exclusive lock before checkpointing,
 * copying and swapping the SQLite file.
 */
final class DatabaseLock
{
    /** @var resource|null */
    private static $sharedHandle = null;

    /**
     * Acquire the shared application lock used by normal DB requests.
     * It is deliberately non-blocking: while maintenance owns the exclusive
     * lock, new database requests fail fast instead of opening the old file.
     */
    public static function acquireShared(string $databasePath): void
    {
        if (is_resource(self::$sharedHandle)) {
            return;
        }

        $handle = self::openHandle($databasePath);
        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('La base est temporairement en maintenance. Réessaie dans un instant.');
        }

        self::$sharedHandle = $handle;
    }

    public static function releaseShared(): void
    {
        if (!is_resource(self::$sharedHandle)) {
            self::$sharedHandle = null;
            return;
        }

        flock(self::$sharedHandle, LOCK_UN);
        fclose(self::$sharedHandle);
        self::$sharedHandle = null;
    }

    /**
     * @return resource exclusive lock handle; caller must releaseExclusive().
     */
    public static function acquireExclusive(string $databasePath)
    {
        $handle = self::openHandle($databasePath);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(
                'Impossible de lancer l’archivage : une requête utilise encore la base. Réessaie dans quelques secondes.'
            );
        }

        return $handle;
    }

    /** @param resource|null $handle */
    public static function releaseExclusive($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return resource */
    private static function openHandle(string $databasePath)
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le dossier de verrouillage SQLite.');
        }

        $path = $directory . '/.database-maintenance.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Impossible d’ouvrir le verrou de maintenance SQLite.');
        }

        return $handle;
    }
}
