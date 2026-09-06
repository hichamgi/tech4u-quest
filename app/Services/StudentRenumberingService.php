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
     * @param array<int,int> $mapping [ancien_numero => nouveau_numero]
     */
    public function apply(string $classCode, array $mapping): int
    {
        $classCode = strtoupper(trim($classCode));
        if ($classCode === '' || $mapping === []) {
            throw new RuntimeException('Classe ou correspondance manquante.');
        }

        $normalized = [];
        foreach ($mapping as $old => $new) {
            $old = (int)$old;
            $new = (int)$new;
            if ($old < 1 || $new < 1) {
                throw new RuntimeException('Tous les numéros doivent être supérieurs à zéro.');
            }
            $normalized[$old] = $new;
        }

        if (count(array_unique(array_values($normalized))) !== count($normalized)) {
            throw new RuntimeException('Deux élèves ne peuvent pas recevoir le même nouveau numéro.');
        }

        $this->db->beginTransaction();

        try {
            $placeholders = implode(',', array_fill(0, count($normalized), '?'));
            $params = array_merge([$classCode], array_keys($normalized));
            $stmt = $this->db->prepare(
                "SELECT id, student_uid, student_number, login_code
                 FROM students
                 WHERE class_code = ? AND student_number IN ($placeholders) AND active = 1"
            );
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($students) !== count($normalized)) {
                throw new RuntimeException('Au moins un ancien numéro ne correspond à aucun élève actif.');
            }

            $targetNumbers = array_values($normalized);
            $targetPlaceholders = implode(',', array_fill(0, count($targetNumbers), '?'));
            $targetParams = array_merge([$classCode], $targetNumbers, array_column($students, 'id'));
            $idPlaceholders = implode(',', array_fill(0, count($students), '?'));
            $conflict = $this->db->prepare(
                "SELECT student_number
                 FROM students
                 WHERE class_code = ?
                   AND student_number IN ($targetPlaceholders)
                   AND id NOT IN ($idPlaceholders)
                   AND active = 1
                 LIMIT 1"
            );
            $conflict->execute($targetParams);
            if ($conflict->fetchColumn() !== false) {
                throw new RuntimeException('Un nouveau numéro appartient à un élève qui ne figure pas dans la correspondance.');
            }

            // Phase temporaire : libère tous les anciens numéros et codes avant d'appliquer les nouveaux.
            $temp = $this->db->prepare(
                'UPDATE students
                 SET class_code = :class_code, login_code = :login_code, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            foreach ($students as $student) {
                $token = substr(hash('sha256', (string)$student['student_uid'] . microtime(true)), 0, 16);
                $temp->execute([
                    'class_code' => '__TMP_' . $token,
                    'login_code' => '__TMP_' . $token,
                    'id' => (int)$student['id'],
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
                $oldNumber = (int)$student['student_number'];
                $newNumber = $normalized[$oldNumber];
                $newLogin = $classCode . '-' . $newNumber;

                $update->execute([
                    'class_code' => $classCode,
                    'student_number' => $newNumber,
                    'login_code' => $newLogin,
                    'id' => (int)$student['id'],
                ]);

                if ((string)$student['login_code'] !== $newLogin) {
                    $history->execute([
                        'student_id' => (int)$student['id'],
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
