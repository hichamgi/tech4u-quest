<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class GameService
{
    public function __construct(private PDO $db)
    {
    }

    public function modulesForStudent(int $studentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.title, m.description, m.icon, ms.question_count, ms.initial_lives,
                    (SELECT COUNT(*) FROM questions q JOIN categories c2 ON c2.id=q.category_id WHERE c2.module_id=m.id AND q.active=1) AS bank_size,
                    (SELECT MAX(a.score) FROM attempts a WHERE a.student_id=:student_id AND a.module_id=m.id) AS best_score,
                    (SELECT a2.id FROM attempts a2 WHERE a2.student_id=:student_id AND a2.module_id=m.id AND a2.status="in_progress" ORDER BY a2.id DESC LIMIT 1) AS current_attempt_id,
                    EXISTS(SELECT 1 FROM attempts ac WHERE ac.student_id=:student_id AND ac.module_id=m.id AND ac.status="completed") AS completed,
                    b.id AS badge_id, b.name AS badge_name, b.icon AS badge_icon,
                    EXISTS(SELECT 1 FROM student_badges sb WHERE sb.student_id=:student_id AND sb.badge_id=b.id) AS badge_obtained
             FROM modules m
             JOIN module_settings ms ON ms.module_id=m.id
             LEFT JOIN badges b ON b.module_id=m.id
             WHERE m.active=1
             ORDER BY m.display_order,m.id'
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function module(int $moduleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, ms.question_count, ms.initial_lives, ms.badge_enabled,
                    b.name AS badge_name, b.description AS badge_description, b.icon AS badge_icon
             FROM modules m
             JOIN module_settings ms ON ms.module_id=m.id
             LEFT JOIN badges b ON b.module_id=m.id
             WHERE m.id=:id AND m.active=1'
        );
        $stmt->execute(['id' => $moduleId]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$module) {
            throw new RuntimeException('Module introuvable.');
        }

        $cats = $this->db->prepare(
            'SELECT c.id,c.name,c.description,c.recommended_bank_size,
                    COALESCE(mcs.question_count,0) AS draw_count,
                    COUNT(CASE WHEN q.active=1 THEN q.id END) AS active_questions
             FROM categories c
             LEFT JOIN module_category_settings mcs ON mcs.category_id=c.id AND mcs.module_id=c.module_id
             LEFT JOIN questions q ON q.category_id=c.id
             WHERE c.module_id=:module_id AND c.active=1
             GROUP BY c.id
             ORDER BY c.display_order,c.id'
        );
        $cats->execute(['module_id' => $moduleId]);
        $module['categories'] = $cats->fetchAll(PDO::FETCH_ASSOC);
        return $module;
    }

    public function startOrResume(int $studentId, int $moduleId): int
    {
        $this->module($moduleId);

        $stmt = $this->db->prepare(
            'SELECT id FROM attempts WHERE student_id=:student AND module_id=:module AND status="in_progress" ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['student' => $studentId, 'module' => $moduleId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int)$existing;
        }

        $lastStmt = $this->db->prepare(
            'SELECT * FROM attempts WHERE student_id=:student AND module_id=:module ORDER BY id DESC LIMIT 1'
        );
        $lastStmt->execute(['student' => $studentId, 'module' => $moduleId]);
        $last = $lastStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return $this->createAttempt($studentId, $moduleId, $last);
    }

    private function createAttempt(int $studentId, int $moduleId, ?array $previous): int
    {
        $module = $this->module($moduleId);
        $questionCount = (int)$module['question_count'];
        $initialLives = (int)$module['initial_lives'];
        $attemptNumber = $previous ? ((int)$previous['attempt_number'] + 1) : 1;

        $path = $this->buildFreshPath($moduleId);

        // On retry after game over: regenerate only encountered positions and preserve unseen future positions.
        if ($previous && (string)$previous['status'] === 'game_over') {
            $oldStmt = $this->db->prepare(
                'SELECT aq.position, aq.question_id, c.id AS category_id
                 FROM attempt_questions aq
                 JOIN questions q ON q.id=aq.question_id
                 JOIN categories c ON c.id=q.category_id
                 WHERE aq.attempt_id=:attempt ORDER BY aq.position'
            );
            $oldStmt->execute(['attempt' => $previous['id']]);
            $oldPath = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
            $encountered = min((int)$previous['current_position'], $questionCount);
            foreach ($oldPath as $old) {
                $pos = (int)$old['position'];
                if ($pos > $encountered) {
                    $path[$pos] = (int)$old['question_id'];
                } else {
                    $path[$pos] = $this->randomQuestionForCategory((int)$old['category_id'], [(int)$old['question_id']]);
                }
            }
            ksort($path);
        }

        if (count($path) !== $questionCount) {
            throw new RuntimeException('La banque de questions ne permet pas de construire le parcours configuré. Vérifie les quotas par catégorie.');
        }

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO attempts(student_id,module_id,attempt_number,total_questions,current_position,lives,score,status)
                 VALUES(:student,:module,:attempt_number,:total,1,:lives,0,"in_progress")'
            );
            $insert->execute([
                'student' => $studentId,
                'module' => $moduleId,
                'attempt_number' => $attemptNumber,
                'total' => $questionCount,
                'lives' => $initialLives,
            ]);
            $attemptId = (int)$this->db->lastInsertId();

            $iq = $this->db->prepare('INSERT INTO attempt_questions(attempt_id,position,question_id) VALUES(:attempt,:position,:question)');
            foreach ($path as $position => $questionId) {
                $iq->execute(['attempt' => $attemptId, 'position' => $position, 'question' => $questionId]);
            }
            $this->db->commit();
            return $attemptId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function buildFreshPath(int $moduleId): array
    {
        $quota = $this->db->prepare(
            'SELECT c.id AS category_id, mcs.question_count
             FROM module_category_settings mcs
             JOIN categories c ON c.id=mcs.category_id
             WHERE mcs.module_id=:module AND c.active=1 AND mcs.question_count>0
             ORDER BY c.display_order,c.id'
        );
        $quota->execute(['module' => $moduleId]);
        $rows = $quota->fetchAll(PDO::FETCH_ASSOC);
        $questionIds = [];
        foreach ($rows as $row) {
            $cid = (int)$row['category_id'];
            $needed = (int)$row['question_count'];
            $stmt = $this->db->prepare('SELECT id FROM questions WHERE category_id=:category AND active=1 ORDER BY RANDOM() LIMIT :lim');
            $stmt->bindValue(':category', $cid, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $needed, PDO::PARAM_INT);
            $stmt->execute();
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            if (count($ids) < $needed) {
                throw new RuntimeException("Catégorie {$cid} : {$needed} question(s) requise(s), seulement " . count($ids) . ' disponible(s).');
            }
            array_push($questionIds, ...$ids);
        }
        shuffle($questionIds);
        $path = [];
        foreach ($questionIds as $i => $qid) {
            $path[$i + 1] = $qid;
        }
        return $path;
    }

    private function randomQuestionForCategory(int $categoryId, array $exclude = []): int
    {
        $sql = 'SELECT id FROM questions WHERE category_id=:category AND active=1';
        $params = ['category' => $categoryId];
        if ($exclude) {
            $marks = [];
            foreach ($exclude as $i => $id) {
                $key = 'x' . $i;
                $marks[] = ':' . $key;
                $params[$key] = $id;
            }
            $sql .= ' AND id NOT IN (' . implode(',', $marks) . ')';
        }
        $sql .= ' ORDER BY RANDOM() LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
        if ($id === false && $exclude) {
            return $this->randomQuestionForCategory($categoryId, []);
        }
        if ($id === false) {
            throw new RuntimeException("Aucune question active dans la catégorie {$categoryId}.");
        }
        return (int)$id;
    }

    public function attempt(int $attemptId, int $studentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*,m.title AS module_title,m.icon AS module_icon,b.name AS badge_name,b.icon AS badge_icon
             FROM attempts a JOIN modules m ON m.id=a.module_id LEFT JOIN badges b ON b.module_id=m.id
             WHERE a.id=:id AND a.student_id=:student'
        );
        $stmt->execute(['id' => $attemptId, 'student' => $studentId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new RuntimeException('Tentative introuvable.');
        }
        return $attempt;
    }

    public function currentQuestion(int $attemptId, int $studentId): array
    {
        $attempt = $this->attempt($attemptId, $studentId);
        if ((string)$attempt['status'] !== 'in_progress') {
            throw new RuntimeException('Cette tentative est terminée.');
        }
        $stmt = $this->db->prepare(
            'SELECT aq.id AS attempt_question_id,aq.position,aq.wrong_answers,q.id,q.question,q.type,q.difficulty,q.explanation,q.lesson,q.topic,c.name AS category_name
             FROM attempt_questions aq
             JOIN questions q ON q.id=aq.question_id
             JOIN categories c ON c.id=q.category_id
             WHERE aq.attempt_id=:attempt AND aq.position=:position'
        );
        $stmt->execute(['attempt' => $attemptId, 'position' => $attempt['current_position']]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            throw new RuntimeException('Question courante introuvable.');
        }
        $ans = $this->db->prepare('SELECT id,answer,display_order FROM question_answers WHERE question_id=:question ORDER BY display_order,id');
        $ans->execute(['question' => $question['id']]);
        $question['answers'] = $ans->fetchAll(PDO::FETCH_ASSOC);
        $question['attempt'] = $attempt;
        return $question;
    }

    public function submit(int $attemptId, int $studentId, array $input): array
    {
        $question = $this->currentQuestion($attemptId, $studentId);
        $attempt = $question['attempt'];
        $type = (string)$question['type'];
        $answerStmt = $this->db->prepare('SELECT id,answer,is_correct FROM question_answers WHERE question_id=:question ORDER BY display_order,id');
        $answerStmt->execute(['question' => $question['id']]);
        $answers = $answerStmt->fetchAll(PDO::FETCH_ASSOC);

        $isCorrect = false;
        $answerId = null;
        $shortAnswer = null;

        if ($type === 'qcm' || $type === 'true_false') {
            $answerId = (int)($input['answer_id'] ?? 0);
            foreach ($answers as $a) {
                if ((int)$a['id'] === $answerId) {
                    $isCorrect = (int)$a['is_correct'] === 1;
                    break;
                }
            }
            if ($answerId < 1) {
                throw new RuntimeException('Choisis une réponse.');
            }
        } elseif ($type === 'multiple') {
            $selected = array_values(array_unique(array_map('intval', (array)($input['answer_ids'] ?? []))));
            sort($selected);
            $correctIds = [];
            $allowedIds = [];
            foreach ($answers as $a) {
                $allowedIds[] = (int)$a['id'];
                if ((int)$a['is_correct'] === 1) {
                    $correctIds[] = (int)$a['id'];
                }
            }
            sort($correctIds);
            foreach ($selected as $sid) {
                if (!in_array($sid, $allowedIds, true)) {
                    throw new RuntimeException('Réponse invalide.');
                }
            }
            if (!$selected) {
                throw new RuntimeException('Choisis au moins une réponse.');
            }
            $isCorrect = $selected === $correctIds;
            $shortAnswer = json_encode($selected, JSON_THROW_ON_ERROR);
        } elseif ($type === 'short') {
            $shortAnswer = trim((string)($input['short_answer'] ?? ''));
            if ($shortAnswer === '') {
                throw new RuntimeException('Saisis une réponse.');
            }
            $normalized = mb_strtolower($shortAnswer);
            foreach ($answers as $a) {
                if ((int)$a['is_correct'] === 1 && mb_strtolower(trim((string)$a['answer'])) === $normalized) {
                    $isCorrect = true;
                    break;
                }
            }
        }

        $this->db->beginTransaction();
        try {
            $log = $this->db->prepare(
                'INSERT INTO attempt_answers(attempt_id,attempt_question_id,answer_id,short_answer,is_correct)
                 VALUES(:attempt,:aq,:answer_id,:short_answer,:correct)'
            );
            $log->execute([
                'attempt' => $attemptId,
                'aq' => $question['attempt_question_id'],
                'answer_id' => $answerId ?: null,
                'short_answer' => $shortAnswer,
                'correct' => $isCorrect ? 1 : 0,
            ]);

            if ($isCorrect) {
                $this->db->prepare('UPDATE attempt_questions SET answered=1,completed=1 WHERE id=:id')->execute(['id' => $question['attempt_question_id']]);
                $newScore = (int)$attempt['score'] + 1;
                $position = (int)$attempt['current_position'];
                $total = (int)$attempt['total_questions'];
                if ($position >= $total) {
                    $this->db->prepare('UPDATE attempts SET score=:score,status="completed",finished_at=CURRENT_TIMESTAMP WHERE id=:id')
                        ->execute(['score' => $newScore, 'id' => $attemptId]);
                    $this->awardBadge($studentId, (int)$attempt['module_id'], $attemptId);
                    $this->db->commit();
                    return ['correct' => true, 'status' => 'completed', 'attempt_id' => $attemptId];
                }
                $this->db->prepare('UPDATE attempts SET score=:score,current_position=current_position+1 WHERE id=:id')
                    ->execute(['score' => $newScore, 'id' => $attemptId]);
                $this->db->commit();
                return ['correct' => true, 'status' => 'in_progress', 'attempt_id' => $attemptId];
            }

            $newLives = max(0, (int)$attempt['lives'] - 1);
            $this->db->prepare('UPDATE attempt_questions SET answered=1,wrong_answers=wrong_answers+1 WHERE id=:id')->execute(['id' => $question['attempt_question_id']]);
            if ($newLives === 0) {
                $this->db->prepare('UPDATE attempts SET lives=0,status="game_over",finished_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id' => $attemptId]);
                $this->db->commit();
                return ['correct' => false, 'status' => 'game_over', 'attempt_id' => $attemptId];
            }
            $this->db->prepare('UPDATE attempts SET lives=:lives WHERE id=:id')->execute(['lives' => $newLives, 'id' => $attemptId]);
            $this->db->commit();
            return ['correct' => false, 'status' => 'in_progress', 'attempt_id' => $attemptId, 'lives' => $newLives];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function awardBadge(int $studentId, int $moduleId, int $attemptId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM badges WHERE module_id=:module');
        $stmt->execute(['module' => $moduleId]);
        $badgeId = $stmt->fetchColumn();
        if ($badgeId === false) {
            return;
        }
        $insert = $this->db->prepare('INSERT OR IGNORE INTO student_badges(student_id,badge_id,attempt_id) VALUES(:student,:badge,:attempt)');
        $insert->execute(['student' => $studentId, 'badge' => (int)$badgeId, 'attempt' => $attemptId]);
    }
}
