<?php
declare(strict_types=1);

return [
    'version' => '2026-09-11_unique_in_progress_attempt_v1',
    'up' => static function (PDO $pdo): void {
        $duplicates = $pdo->query(
            "SELECT student_id,path_id,COUNT(*) AS total
             FROM attempts
             WHERE status='in_progress' AND path_id IS NOT NULL
             GROUP BY student_id,path_id
             HAVING COUNT(*) > 1
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($duplicates) {
            throw new RuntimeException(
                'Impossible d’activer la contrainte des tentatives en cours : ' .
                'plusieurs tentatives in_progress existent pour un même élève/parcours.'
            );
        }

        $pdo->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS uniq_attempts_in_progress_student_path
             ON attempts(student_id,path_id)
             WHERE status='in_progress' AND path_id IS NOT NULL"
        );
    },
];
