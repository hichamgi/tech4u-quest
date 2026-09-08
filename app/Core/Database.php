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
        if (self::$pdo instanceof PDO) return self::$pdo;

        $root = dirname(__DIR__, 2);
        $config = require $root . '/config/config.php';
        $path = $config['database'];
        self::initializeIfMissing($path, $root . '/database/schema.sql');

        self::$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        self::$pdo->exec('PRAGMA foreign_keys = ON;');
        self::$pdo->exec('PRAGMA journal_mode = WAL;');
        self::$pdo->exec('PRAGMA busy_timeout = 5000;');
        self::migrate(self::$pdo);
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $cols = $pdo->query('PRAGMA table_info(questions)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        if (!in_array('exclusion_group', $names, true)) {
            $pdo->exec('ALTER TABLE questions ADD COLUMN exclusion_group TEXT');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_exclusion_group ON questions(exclusion_group)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS module_paths (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id INTEGER NOT NULL,
                code TEXT NOT NULL CHECK(code IN ('discovery','training','mastery','expert')),
                name TEXT NOT NULL,
                icon TEXT,
                description TEXT,
                pool_percent INTEGER NOT NULL CHECK(pool_percent BETWEEN 1 AND 100),
                question_count INTEGER NOT NULL CHECK(question_count > 0),
                display_order INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
                FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE,
                UNIQUE(module_id, code)
            )"
        );

        $attemptCols = $pdo->query('PRAGMA table_info(attempts)')->fetchAll(PDO::FETCH_ASSOC);
        $attemptNames = array_column($attemptCols, 'name');
        if (!in_array('path_id', $attemptNames, true)) {
            $pdo->exec('ALTER TABLE attempts ADD COLUMN path_id INTEGER REFERENCES module_paths(id)');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_path ON attempts(path_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_module_paths_module ON module_paths(module_id)');

        $paths = [
            1 => [6, 8, 10, 12],
            2 => [8, 10, 12, 15],
            3 => [8, 12, 15, 20],
            4 => [7, 10, 12, 15],
        ];
        $defs = [
            ['discovery', 'Facile', '🟢', 'Commence avec les notions essentielles et les questions les plus accessibles.', 25, 1],
            ['training', 'Moyen', '🔵', 'Progresse avec une plus grande partie de la banque et des questions plus variées.', 50, 2],
            ['mastery', 'Difficile', '🟠', 'Consolide tes acquis avec un parcours plus exigeant.', 75, 3],
            ['expert', 'Expert', '🔴', 'Relève le défi complet avec toute la banque de questions.', 100, 4],
        ];
        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO module_paths(id,module_id,code,name,icon,description,pool_percent,question_count,display_order,active)
             VALUES(:id,:module,:code,:name,:icon,:description,:pool,:count,:ord,1)'
        );
        $update = $pdo->prepare(
            'UPDATE module_paths
             SET name=:name,icon=:icon,description=:description,pool_percent=:pool,question_count=:count,display_order=:ord
             WHERE id=:id AND module_id=:module AND code=:code'
        );
        foreach ($paths as $moduleId => $counts) {
            foreach ($defs as $i => $def) {
                $params = [
                    'id' => ($moduleId * 100) + ($i + 1),
                    'module' => $moduleId,
                    'code' => $def[0],
                    'name' => $def[1],
                    'icon' => $def[2],
                    'description' => $def[3],
                    'pool' => $def[4],
                    'count' => $counts[$i],
                    'ord' => $def[5],
                ];
                $insert->execute($params);
                $update->execute($params);
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS path_badges (
                id INTEGER PRIMARY KEY,
                module_id INTEGER NOT NULL,
                path_id INTEGER NOT NULL UNIQUE,
                name TEXT NOT NULL,
                description TEXT,
                icon TEXT,
                FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE,
                FOREIGN KEY(path_id) REFERENCES module_paths(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS student_path_badges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL,
                badge_id INTEGER NOT NULL,
                attempt_id INTEGER,
                obtained_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
                FOREIGN KEY(badge_id) REFERENCES path_badges(id) ON DELETE CASCADE,
                FOREIGN KEY(attempt_id) REFERENCES attempts(id),
                UNIQUE(student_id, badge_id)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_path_badges_module ON path_badges(module_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_student_path_badges_student ON student_path_badges(student_id)');

        $badgeSeed = $pdo->prepare(
            'INSERT OR IGNORE INTO path_badges(id,module_id,path_id,name,description,icon)
             SELECT p.id,p.module_id,p.id,
                    p.name || " — " || m.title,
                    "Badge obtenu en terminant le mode " || p.name || " du module " || m.title || ".",
                    p.icon
             FROM module_paths p
             JOIN modules m ON m.id=p.module_id
             WHERE p.id=:path_id'
        );
        $badgeUpdate = $pdo->prepare(
            'UPDATE path_badges
             SET name=(SELECT p.name || " — " || m.title FROM module_paths p JOIN modules m ON m.id=p.module_id WHERE p.id=:path_id),
                 description=(SELECT "Badge obtenu en terminant le mode " || p.name || " du module " || m.title || "." FROM module_paths p JOIN modules m ON m.id=p.module_id WHERE p.id=:path_id),
                 icon=(SELECT p.icon FROM module_paths p WHERE p.id=:path_id)
             WHERE path_id=:path_id'
        );
        foreach (array_keys($paths) as $moduleId) {
            for ($level = 1; $level <= 4; $level++) {
                $pathId = ($moduleId * 100) + $level;
                $badgeSeed->execute(['path_id' => $pathId]);
                $badgeUpdate->execute(['path_id' => $pathId]);
            }
        }
    }

    private static function initializeIfMissing(string $databasePath, string $schemaPath): void
    {
        if (is_file($databasePath)) return;
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory,0775,true) && !is_dir($directory)) throw new RuntimeException('Impossible de créer le dossier de la base SQLite : '.$directory);
        if (!is_file($schemaPath) || !is_readable($schemaPath)) throw new RuntimeException('Le fichier schema.sql est introuvable ou illisible : '.$schemaPath);
        $schema=file_get_contents($schemaPath); if($schema===false||trim($schema)==='') throw new RuntimeException('Le fichier schema.sql est vide ou illisible.');
        try {
            $pdo=new PDO('sqlite:'.$databasePath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            $pdo->exec('PRAGMA foreign_keys = ON;'); $pdo->exec($schema);
        } catch (\Throwable $e) {
            if(is_file($databasePath)){@unlink($databasePath);@unlink($databasePath.'-wal');@unlink($databasePath.'-shm');}
            throw new RuntimeException('Échec de la création automatique de la base SQLite : '.$e->getMessage(),0,$e);
        }
    }
}
