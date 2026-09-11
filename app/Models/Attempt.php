<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Attempt
{
    public function __construct(private PDO $db)
    {
    }

    public function moduleActiveForStudent(int $attemptId, int $studentId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT m.active
             FROM attempts a
             JOIN modules m ON m.id = a.module_id
             WHERE a.id = :attempt AND a.student_id = :student
             LIMIT 1'
        );
        $stmt->execute([
            'attempt' => $attemptId,
            'student' => $studentId,
        ]);
        $active = $stmt->fetchColumn();

        return $active === false ? null : (int)$active;
    }
}
