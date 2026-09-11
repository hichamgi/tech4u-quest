<?php
declare(strict_types=1);

return [
    'version' => '2026-09-10_progression_schema_v1',
    'up' => static function (PDO $pdo): void {
        $columns = $pdo->query('PRAGMA table_info(questions)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');
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

        $attemptColumns = $pdo->query('PRAGMA table_info(attempts)')->fetchAll(PDO::FETCH_ASSOC);
        $attemptNames = array_column($attemptColumns, 'name');
        if (!in_array('path_id', $attemptNames, true)) {
            $pdo->exec('ALTER TABLE attempts ADD COLUMN path_id INTEGER REFERENCES module_paths(id)');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attempts_path ON attempts(path_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_module_paths_module ON module_paths(module_id)');

        $definitions = [
            ['discovery', 'Facile', '🟢', 'Commence avec les notions essentielles et les questions les plus accessibles.', 25, 1],
            ['training', 'Moyen', '🔵', 'Progresse avec davantage de questions et une difficulté plus variée.', 50, 2],
            ['mastery', 'Difficile', '🟠', 'Consolide tes acquis avec davantage de questions et un niveau plus exigeant.', 75, 3],
            ['expert', 'Expert', '🔴', 'Relève le défi complet défini par la répartition pédagogique du module.', 100, 4],
        ];

        $moduleIds = $pdo->query('SELECT id FROM modules ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $quotaStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(question_count),0)
             FROM module_category_settings
             WHERE module_id=:module'
        );
        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO module_paths(
                id,module_id,code,name,icon,description,pool_percent,question_count,display_order,active
             ) VALUES(:id,:module,:code,:name,:icon,:description,:percent,:count,:ord,1)'
        );
        $update = $pdo->prepare(
            'UPDATE module_paths
             SET name=:name,icon=:icon,description=:description,pool_percent=:percent,
                 question_count=:count,display_order=:ord
             WHERE id=:id AND module_id=:module AND code=:code'
        );

        foreach ($moduleIds as $moduleIdRaw) {
            $moduleId = (int)$moduleIdRaw;
            $quotaStmt->execute(['module' => $moduleId]);
            $quotaTotal = (int)$quotaStmt->fetchColumn();
            if ($quotaTotal < 1) {
                $fallback = $pdo->prepare(
                    'SELECT question_count FROM module_settings WHERE module_id=:module'
                );
                $fallback->execute(['module' => $moduleId]);
                $quotaTotal = max(1, (int)$fallback->fetchColumn());
            }

            foreach ($definitions as $index => $definition) {
                $percent = (int)$definition[4];
                $count = max(1, (int)ceil($quotaTotal * ($percent / 100)));
                $params = [
                    'id' => ($moduleId * 100) + ($index + 1),
                    'module' => $moduleId,
                    'code' => $definition[0],
                    'name' => $definition[1],
                    'icon' => $definition[2],
                    'description' => $definition[3],
                    'percent' => $percent,
                    'count' => $count,
                    'ord' => $definition[5],
                ];
                $insert->execute($params);
                $update->execute($params);
            }

            $settings = $pdo->prepare(
                'UPDATE module_settings
                 SET question_count=:count,initial_lives=3
                 WHERE module_id=:module'
            );
            $settings->execute(['count' => $quotaTotal, 'module' => $moduleId]);
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
                UNIQUE(student_id,badge_id)
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
        foreach ($moduleIds as $moduleIdRaw) {
            $moduleId = (int)$moduleIdRaw;
            for ($level = 1; $level <= 4; $level++) {
                $pathId = ($moduleId * 100) + $level;
                $badgeSeed->execute(['path_id' => $pathId]);
                $badgeUpdate->execute(['path_id' => $pathId]);
            }
        }
    },
];
