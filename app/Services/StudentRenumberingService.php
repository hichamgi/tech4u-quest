<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class StudentRenumberingService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Renumber students by their stable numeric IDs imported from the local MySQL database.
     *
     * @param array<int,int> $mapping [student_id => nouveau_numero]
     */
    public function apply(string $classCode, array $mapping): int
    {
        $classCode = strtoupper(trim($classCode));
        if ($classCode === '' || $mapping === []) {
            throw new RuntimeException('Classe ou correspondance manquante.');
        }

        $normalized = [];
        foreach ($mapping as $studentId => $newNumber) {
            $studentId = (int)$studentId;
            $newNumber = (int)$newNumber;

            if ($studentId < 1 || $newNumber < 1) {
                throw new RuntimeException('Les IDs et numéros doivent être supérieurs à zéro.');
            }

            $normalized[$studentId] = $newNumber;
        }

        if (count(array_unique(array_values($normalized))) !== count($normalized)) {
            throw new RuntimeException('Deux élèves ne peuvent pas recevoir le même nouveau numéro.');
        }

        $this->db->beginTransaction();

        try {
            $ids = array_keys($normalized);
            $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $this->db->prepare(
                "SELECT id, student_number, login_code
                 FROM students
                 WHERE class_code = ? AND id IN ($idPlaceholders) AND active = 1"
            );
            $stmt->execute(array_merge([$classCode], $ids));
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($students) !== count($normalized)) {
                throw new RuntimeException('Au moins un ID ne correspond à aucun élève actif de cette classe.');
            }

            $targetNumbers = array_values($normalized);
            $targetPlaceholders = implode(',', array_fill(0, count($targetNumbers), '?'));
            $conflict = $this->db->prepare(
                "SELECT student_number
                 FROM students
                 WHERE class_code = ?
                   AND student_number IN ($targetPlaceholders)
                   AND id NOT IN ($idPlaceholders)
                   AND active = 1
                 LIMIT 1"
            );
            $conflict->execute(array_merge([$classCode], $targetNumbers, $ids));

            if ($conflict->fetchColumn() !== false) {
                throw new RuntimeException('Un nouveau numéro appartient à un élève qui ne figure pas dans la correspondance.');
            }

            // Temporary values release all current class/number/login uniqueness constraints.
            $temp = $this->db->prepare(
                'UPDATE students
                 SET class_code = :class_code,
                     login_code = :login_code,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            foreach ($students as $student) {
                $studentId = (int)$student['id'];
                $token = $studentId . '_' . bin2hex(random_bytes(6));
                $temp->execute([
                    'class_code' => '__TMP_' . $token,
                    'login_code' => '__TMP_' . $token,
                    'id' => $studentId,
                ]);
            }

            $update = $this->db->prepare(
                'UPDATE students
                 SET class_code = :class_code,
                     student_number = :student_number,
                     login_code = :login_code,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $history = $this->db->prepare(
                'INSERT INTO student_login_history(student_id, old_login_code, new_login_code)
                 VALUES (:student_id, :old_login_code, :new_login_code)'
            );

            foreach ($students as $student) {
                $studentId = (int)$student['id'];
                $newNumber = $normalized[$studentId];
                $newLogin = $classCode . '-' . $newNumber;

                $update->execute([
                    'class_code' => $classCode,
                    'student_number' => $newNumber,
                    'login_code' => $newLogin,
                    'id' => $studentId,
                ]);

                if ((string)$student['login_code'] !== $newLogin) {
                    $history->execute([
                        'student_id' => $studentId,
                        'old_login_code' => (string)$student['login_code'],
                        'new_login_code' => $newLogin,
                    ]);
                }
            }

            $this->db->commit();
            return count($students);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
