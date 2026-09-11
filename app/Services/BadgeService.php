<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class BadgeService
{
    public function __construct(private PDO $db)
    {
    }

    public function awardPathBadge(int $studentId, int $pathId, int $attemptId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM path_badges WHERE path_id=:path LIMIT 1');
        $stmt->execute(['path' => $pathId]);
        $badgeId = $stmt->fetchColumn();
        if ($badgeId === false) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT OR IGNORE INTO student_path_badges(student_id,badge_id,attempt_id)
             VALUES(:student,:badge,:attempt)'
        );
        $insert->execute([
            'student' => $studentId,
            'badge' => (int)$badgeId,
            'attempt' => $attemptId,
        ]);
    }
}
