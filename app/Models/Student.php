<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

final class Student
{
    public function __construct(private PDO $db)
    {
    }

    public static function buildLoginCode(string $classCode, int $studentNumber): string
    {
        $classCode = strtoupper(trim($classCode));
        if ($classCode === '' || $studentNumber < 1) {
            throw new RuntimeException('Classe ou numéro invalide.');
        }

        return $classCode . '-' . $studentNumber;
    }

    public function findForAuthentication(string $identifier): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, login_code AS login, class_code, student_number, password_hash,
                    must_change_password, active
             FROM students
             WHERE login_code = :identifier
             LIMIT 1'
        );
        $stmt->execute(['identifier' => strtoupper(trim($identifier))]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        return $student ?: null;
    }

    public function activePasswordHash(int $studentId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT password_hash
             FROM students
             WHERE id = :id AND active = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $studentId]);
        $hash = $stmt->fetchColumn();

        return $hash === false ? null : (string)$hash;
    }

    public function isDemo(int $studentId): bool
    {
        $stmt = $this->db->prepare('SELECT UPPER(TRIM(class_code)) FROM students WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $studentId]);

        return (string)$stmt->fetchColumn() === 'DEMO';
    }

    public function badgeCount(int $studentId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM student_path_badges WHERE student_id = :student');
        $stmt->execute(['student' => $studentId]);

        return (int)$stmt->fetchColumn();
    }

    public function badges(int $studentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pb.icon,pb.name,pb.description,spb.obtained_at,
                    m.title AS module_title,p.name AS path_name
             FROM student_path_badges spb
             JOIN path_badges pb ON pb.id = spb.badge_id
             JOIN module_paths p ON p.id = pb.path_id
             JOIN modules m ON m.id = pb.module_id
             WHERE spb.student_id = :student
             ORDER BY spb.obtained_at DESC, p.display_order DESC'
        );
        $stmt->execute(['student' => $studentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create or synchronize a Tech4U student from the local MySQL database.
     * The numeric ID is provided by MySQL and must remain stable.
     */
    public function createFromLocalId(
        int $studentId,
        string $classCode,
        int $studentNumber,
        string $plainPassword,
        bool $mustChange = true
    ): void {
        if ($studentId < 1) {
            throw new RuntimeException('ID élève invalide.');
        }
        if (mb_strlen($plainPassword) < 6) {
            throw new RuntimeException('Le mot de passe doit contenir au moins 6 caractères.');
        }

        $loginCode = self::buildLoginCode($classCode, $studentNumber);

        $stmt = $this->db->prepare(
            'INSERT INTO students(
                id, class_code, student_number, login_code,
                password_hash, must_change_password
             ) VALUES (
                :id, :class_code, :student_number, :login_code,
                :password_hash, :must_change_password
             )'
        );
        $stmt->execute([
            'id' => $studentId,
            'class_code' => strtoupper(trim($classCode)),
            'student_number' => $studentNumber,
            'login_code' => $loginCode,
            'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'must_change_password' => $mustChange ? 1 : 0,
        ]);
    }

    public function changeNumber(int $studentId, int $newNumber): void
    {
        if ($newNumber < 1) {
            throw new RuntimeException('Le nouveau numéro doit être supérieur à zéro.');
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('SELECT class_code, login_code FROM students WHERE id = :id AND active = 1');
            $stmt->execute(['id' => $studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                throw new RuntimeException('Élève introuvable.');
            }

            $newLogin = self::buildLoginCode((string)$student['class_code'], $newNumber);

            $check = $this->db->prepare('SELECT id FROM students WHERE login_code = :login AND id <> :id');
            $check->execute(['login' => $newLogin, 'id' => $studentId]);
            if ($check->fetchColumn()) {
                throw new RuntimeException('Ce code élève est déjà utilisé.');
            }

            $update = $this->db->prepare(
                'UPDATE students
                 SET student_number = :number, login_code = :login, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                'number' => $newNumber,
                'login' => $newLogin,
                'id' => $studentId,
            ]);

            $history = $this->db->prepare(
                'INSERT INTO student_login_history(student_id, old_login_code, new_login_code)
                 VALUES (:student_id, :old_login, :new_login)'
            );
            $history->execute([
                'student_id' => $studentId,
                'old_login' => (string)$student['login_code'],
                'new_login' => $newLogin,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function setPassword(int $studentId, string $plainPassword, bool $mustChange = false): void
    {
        if (mb_strlen($plainPassword) < 6) {
            throw new RuntimeException('Le mot de passe doit contenir au moins 6 caractères.');
        }

        $stmt = $this->db->prepare(
            'UPDATE students
             SET password_hash = :hash, must_change_password = :must_change, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'must_change' => $mustChange ? 1 : 0,
            'id' => $studentId,
        ]);
    }
}
