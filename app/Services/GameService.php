<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class GameService
{
    private QuestionSelectionService $questionSelector;
    private AnswerEvaluationService $answerEvaluator;
    private BadgeService $badges;

    public function __construct(private PDO $db)
    {
        $this->questionSelector = new QuestionSelectionService($db);
        $this->answerEvaluator = new AnswerEvaluationService();
        $this->badges = new BadgeService($db);
    }

    public function modulesForStudent(int $studentId, bool $includeInactive = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id,m.title,m.description,m.icon,ms.question_count,ms.initial_lives,
                    (SELECT COUNT(*) FROM questions q JOIN categories c2 ON c2.id=q.category_id WHERE c2.module_id=m.id AND q.active=1) AS bank_size,
                    (SELECT MAX(a.score) FROM attempts a WHERE a.student_id=:student_best AND a.module_id=m.id) AS best_score,
                    (SELECT a2.id FROM attempts a2 WHERE a2.student_id=:student_current AND a2.module_id=m.id AND a2.status="in_progress" ORDER BY a2.id DESC LIMIT 1) AS current_attempt_id,
                    EXISTS(SELECT 1 FROM attempts ac WHERE ac.student_id=:student_completed AND ac.module_id=m.id AND ac.status="completed") AS completed
             FROM modules m
             JOIN module_settings ms ON ms.module_id=m.id
             WHERE (m.active=1 OR CAST(:include_inactive AS INTEGER)=1)
             ORDER BY m.display_order,m.id'
        );
        $stmt->execute([
            'student_best' => $studentId,
            'student_current' => $studentId,
            'student_completed' => $studentId,
            'include_inactive' => $includeInactive ? 1 : 0,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function module(int $moduleId, bool $includeInactive = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*,ms.question_count,ms.initial_lives,ms.badge_enabled
             FROM modules m
             JOIN module_settings ms ON ms.module_id=m.id
             WHERE m.id=:id AND (m.active=1 OR CAST(:include_inactive AS INTEGER)=1)'
        );
        $stmt->execute(['id' => $moduleId, 'include_inactive' => $includeInactive ? 1 : 0]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$module) {
            throw new RuntimeException('Module introuvable.');
        }

        $categories = $this->db->prepare(
            'SELECT c.id,c.name,c.description,c.recommended_bank_size,
                    COALESCE(mcs.question_count,1) AS draw_count,
                    COUNT(CASE WHEN q.active=1 THEN q.id END) AS active_questions
             FROM categories c
             LEFT JOIN module_category_settings mcs ON mcs.category_id=c.id AND mcs.module_id=c.module_id
             LEFT JOIN questions q ON q.category_id=c.id
             WHERE c.module_id=:module_id AND c.active=1
             GROUP BY c.id
             ORDER BY c.display_order,c.id'
        );
        $categories->execute(['module_id' => $moduleId]);
        $module['categories'] = $categories->fetchAll(PDO::FETCH_ASSOC);
        return $module;
    }

    public function pathsForStudent(int $moduleId, int $studentId, bool $isDemo = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*,
                    pb.id AS badge_id,pb.name AS badge_name,pb.icon AS badge_icon,pb.description AS badge_description,
                    EXISTS(SELECT 1 FROM student_path_badges spb WHERE spb.student_id=:student_badge AND spb.badge_id=pb.id) AS badge_obtained,
                    EXISTS(SELECT 1 FROM attempts a WHERE a.student_id=:student_completed AND a.path_id=p.id AND a.status="completed") AS completed,
                    (SELECT a2.id FROM attempts a2 WHERE a2.student_id=:student_current AND a2.path_id=p.id AND a2.status="in_progress" ORDER BY a2.id DESC LIMIT 1) AS current_attempt_id
             FROM module_paths p
             LEFT JOIN path_badges pb ON pb.path_id=p.id
             WHERE p.module_id=:module
             ORDER BY p.display_order,p.id'
        );
        $stmt->execute([
            'student_badge' => $studentId,
            'student_completed' => $studentId,
            'student_current' => $studentId,
            'module' => $moduleId,
        ]);
        $paths = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $previousBadge = true;
        foreach ($paths as &$path) {
            $path['unlocked'] = $isDemo || (int)$path['display_order'] === 1 || $previousBadge;
            $previousBadge = (int)($path['badge_obtained'] ?? 0) === 1;
        }
        unset($path);
        return $paths;
    }

    public function startOrResume(
        int $studentId,
        int $moduleId,
        int $pathId,
        bool $includeInactive = false,
        bool $isDemo = false
    ): int {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->module($moduleId, $includeInactive);
            $path = $this->path($pathId, $moduleId);
            if (!$this->pathUnlocked($studentId, $path, $isDemo)) {
                throw new RuntimeException('Ce niveau est verrouillé. Obtiens d’abord le badge du niveau précédent.');
            }

            $stmt = $this->db->prepare(
                'SELECT id FROM attempts
                 WHERE student_id=:student AND module_id=:module AND path_id=:path AND status="in_progress"
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['student' => $studentId, 'module' => $moduleId, 'path' => $pathId]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                $this->db->exec('COMMIT');
                return (int)$existing;
            }

            $lastStmt = $this->db->prepare(
                'SELECT * FROM attempts
                 WHERE student_id=:student AND module_id=:module AND path_id=:path
                 ORDER BY id DESC LIMIT 1'
            );
            $lastStmt->execute(['student' => $studentId, 'module' => $moduleId, 'path' => $pathId]);
            $last = $lastStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $attemptId = $this->createAttempt($studentId, $moduleId, $path, $last, $includeInactive);
            $this->db->exec('COMMIT');
            return $attemptId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public function attempt(int $attemptId, int $studentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*,m.title AS module_title,m.icon AS module_icon,
                    p.code AS path_code,p.name AS path_name,p.icon AS path_icon,
                    pb.name AS badge_name,pb.icon AS badge_icon
             FROM attempts a
             JOIN modules m ON m.id=a.module_id
             LEFT JOIN module_paths p ON p.id=a.path_id
             LEFT JOIN path_badges pb ON pb.path_id=p.id
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
            'SELECT aq.id AS attempt_question_id,aq.position,aq.wrong_answers,
                    q.id,q.question,q.type,q.difficulty,q.explanation,q.lesson,q.topic,
                    c.name AS category_name
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

        $answers = $this->db->prepare(
            'SELECT id,answer,display_order
             FROM question_answers
             WHERE question_id=:question
             ORDER BY display_order,id'
        );
        $answers->execute(['question' => $question['id']]);
        $question['answers'] = $this->stableAnswerOrder(
            $answers->fetchAll(PDO::FETCH_ASSOC),
            (int)$question['attempt_question_id']
        );
        $question['attempt'] = $attempt;
        return $question;
    }

    public function submit(int $attemptId, int $studentId, array $input): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $question = $this->currentQuestion($attemptId, $studentId);
            $attempt = $question['attempt'];

            $expectedQuestionId = filter_var(
                $input['attempt_question_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($expectedQuestionId === false || (int)$expectedQuestionId !== (int)$question['attempt_question_id']) {
                throw new RuntimeException('Cette question a déjà été traitée. Recharge la page pour continuer.');
            }

            $answerStmt = $this->db->prepare(
                'SELECT id,answer,is_correct
                 FROM question_answers
                 WHERE question_id=:question
                 ORDER BY display_order,id'
            );
            $answerStmt->execute(['question' => $question['id']]);
            $evaluation = $this->answerEvaluator->evaluate(
                $question,
                $answerStmt->fetchAll(PDO::FETCH_ASSOC),
                $input
            );

            $isCorrect = $evaluation['is_correct'];
            $answerId = $evaluation['answer_id'];
            $shortAnswer = $evaluation['short_answer'];

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
                $this->db->prepare(
                    'UPDATE attempt_questions SET answered=1,completed=1 WHERE id=:id'
                )->execute(['id' => $question['attempt_question_id']]);

                $newScore = (int)$attempt['score'] + 1;
                $position = (int)$attempt['current_position'];
                $total = (int)$attempt['total_questions'];

                if ($position >= $total) {
                    $update = $this->db->prepare(
                        'UPDATE attempts
                         SET score=:score,status="completed",finished_at=CURRENT_TIMESTAMP
                         WHERE id=:id AND student_id=:student AND status="in_progress" AND current_position=:position'
                    );
                    $update->execute([
                        'score' => $newScore,
                        'id' => $attemptId,
                        'student' => $studentId,
                        'position' => $position,
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Cette tentative a déjà été modifiée. Recharge la page.');
                    }

                    $this->badges->awardPathBadge($studentId, (int)$attempt['path_id'], $attemptId);
                    $this->db->exec('COMMIT');
                    return ['correct' => true, 'status' => 'completed', 'attempt_id' => $attemptId];
                }

                $update = $this->db->prepare(
                    'UPDATE attempts
                     SET score=:score,current_position=current_position+1
                     WHERE id=:id AND student_id=:student AND status="in_progress" AND current_position=:position'
                );
                $update->execute([
                    'score' => $newScore,
                    'id' => $attemptId,
                    'student' => $studentId,
                    'position' => $position,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Cette tentative a déjà été modifiée. Recharge la page.');
                }

                $this->db->exec('COMMIT');
                return ['correct' => true, 'status' => 'in_progress', 'attempt_id' => $attemptId];
            }

            $newLives = max(0, (int)$attempt['lives'] - 1);
            $this->db->prepare(
                'UPDATE attempt_questions SET answered=1,wrong_answers=wrong_answers+1 WHERE id=:id'
            )->execute(['id' => $question['attempt_question_id']]);

            if ($newLives === 0) {
                $update = $this->db->prepare(
                    'UPDATE attempts
                     SET lives=0,status="game_over",finished_at=CURRENT_TIMESTAMP
                     WHERE id=:id AND student_id=:student AND status="in_progress"
                       AND current_position=:position AND lives=:old_lives'
                );
                $update->execute([
                    'id' => $attemptId,
                    'student' => $studentId,
                    'position' => $attempt['current_position'],
                    'old_lives' => $attempt['lives'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Cette tentative a déjà été modifiée. Recharge la page.');
                }

                $this->db->exec('COMMIT');
                return ['correct' => false, 'status' => 'game_over', 'attempt_id' => $attemptId];
            }

            $update = $this->db->prepare(
                'UPDATE attempts
                 SET lives=:lives
                 WHERE id=:id AND student_id=:student AND status="in_progress"
                   AND current_position=:position AND lives=:old_lives'
            );
            $update->execute([
                'lives' => $newLives,
                'id' => $attemptId,
                'student' => $studentId,
                'position' => $attempt['current_position'],
                'old_lives' => $attempt['lives'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Cette tentative a déjà été modifiée. Recharge la page.');
            }

            $this->db->exec('COMMIT');
            return [
                'correct' => false,
                'status' => 'in_progress',
                'attempt_id' => $attemptId,
                'lives' => $newLives,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    private function path(int $pathId, int $moduleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM module_paths WHERE id=:id AND module_id=:module');
        $stmt->execute(['id' => $pathId, 'module' => $moduleId]);
        $path = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$path) {
            throw new RuntimeException('Parcours introuvable.');
        }
        return $path;
    }

    private function pathUnlocked(int $studentId, array $path, bool $isDemo): bool
    {
        if ($isDemo || (int)$path['display_order'] === 1) {
            return true;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM module_paths
             WHERE module_id=:module AND display_order<:ord
             ORDER BY display_order DESC LIMIT 1'
        );
        $stmt->execute(['module' => $path['module_id'], 'ord' => $path['display_order']]);
        $previousId = $stmt->fetchColumn();
        if ($previousId === false) {
            return true;
        }

        $done = $this->db->prepare(
            'SELECT 1
             FROM student_path_badges spb
             JOIN path_badges pb ON pb.id=spb.badge_id
             WHERE spb.student_id=:student AND pb.path_id=:path
             LIMIT 1'
        );
        $done->execute(['student' => $studentId, 'path' => (int)$previousId]);
        return $done->fetchColumn() !== false;
    }

    private function createAttempt(
        int $studentId,
        int $moduleId,
        array $path,
        ?array $previous,
        bool $includeInactive = false
    ): int {
        $module = $this->module($moduleId, $includeInactive);
        $questionCount = (int)$path['question_count'];
        $initialLives = (int)$module['initial_lives'];
        $attemptNumber = $previous ? ((int)$previous['attempt_number'] + 1) : 1;

        $questions = $this->questionSelector->buildForAttempt(
            $moduleId,
            $path,
            $previous,
            $questionCount
        );
        if (count($questions) !== $questionCount) {
            throw new RuntimeException('La banque de questions ne permet pas de construire ce parcours.');
        }

        $insert = $this->db->prepare(
            'INSERT INTO attempts(student_id,module_id,path_id,attempt_number,total_questions,current_position,lives,score,status)
             VALUES(:student,:module,:path,:attempt_number,:total,1,:lives,0,"in_progress")'
        );
        $insert->execute([
            'student' => $studentId,
            'module' => $moduleId,
            'path' => $path['id'],
            'attempt_number' => $attemptNumber,
            'total' => $questionCount,
            'lives' => $initialLives,
        ]);
        $attemptId = (int)$this->db->lastInsertId();

        $questionInsert = $this->db->prepare(
            'INSERT INTO attempt_questions(attempt_id,position,question_id)
             VALUES(:attempt,:position,:question)'
        );
        foreach ($questions as $position => $questionId) {
            $questionInsert->execute([
                'attempt' => $attemptId,
                'position' => $position,
                'question' => $questionId,
            ]);
        }
        return $attemptId;
    }

    private function stableAnswerOrder(array $answers, int $attemptQuestionId): array
    {
        usort($answers, static function (array $a, array $b) use ($attemptQuestionId): int {
            $hashA = hash('sha256', $attemptQuestionId . ':' . (int)$a['id']);
            $hashB = hash('sha256', $attemptQuestionId . ':' . (int)$b['id']);
            return $hashA <=> $hashB;
        });
        return $answers;
    }
}
