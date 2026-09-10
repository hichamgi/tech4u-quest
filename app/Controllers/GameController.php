<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Services\GameService;
use PDOException;
use RuntimeException;
use Throwable;

final class GameController
{
    public function question(string $attempt): void
    {
        $student = Auth::requireStudent(Url::to('login'), Url::to('change-password'));
        $attemptId = (int)$attempt;
        $isDemo = strtoupper((string)($student['class_code'] ?? '')) === 'DEMO';

        if ($attemptId < 1) {
            header('Location: ' . Url::to('dashboard'));
            exit;
        }

        try {
            $db = Database::connection();
            $game = new GameService($db);
        } catch (Throwable $e) {
            Logger::exception($e, ['area' => 'game_init', 'attempt_id' => $attemptId]);
            View::render('game/question', [
                'attemptId' => $attemptId,
                'student' => $student,
                'data' => null,
                'error' => 'Le service de quiz est temporairement indisponible. Réessaie dans un instant.',
                'feedback' => null,
                'csrfToken' => Auth::csrfToken(),
            ]);
            return;
        }

        $error = null;
        $feedback = null;

        try {
            $moduleAccessStmt = $db->prepare(
                'SELECT m.active
                 FROM attempts a
                 JOIN modules m ON m.id = a.module_id
                 WHERE a.id = :attempt AND a.student_id = :student
                 LIMIT 1'
            );
            $moduleAccessStmt->execute([
                'attempt' => $attemptId,
                'student' => (int)$student['id'],
            ]);
            $moduleActive = $moduleAccessStmt->fetchColumn();
        } catch (Throwable $e) {
            Logger::exception($e, ['area' => 'game_access', 'attempt_id' => $attemptId]);
            $moduleActive = false;
            $error = 'Impossible de charger cette tentative pour le moment.';
        }

        if ($error === null) {
            if ($moduleActive === false) {
                $error = 'Tentative introuvable.';
            } elseif ((int)$moduleActive !== 1 && !$isDemo) {
                $error = 'Ce module n’est pas encore disponible. Il sera activé après son traitement en classe.';
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $error === null) {
            $csrf = $_POST['csrf_token'] ?? null;
            if (!Auth::validateCsrf(is_string($csrf) ? $csrf : null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $result = $game->submit($attemptId, (int)$student['id'], $_POST);

                    if ($result['status'] === 'completed') {
                        header('Location: ' . Url::to('attempt/' . $attemptId . '/complete'));
                        exit;
                    }
                    if ($result['status'] === 'game_over') {
                        header('Location: ' . Url::to('attempt/' . $attemptId . '/game-over'));
                        exit;
                    }
                    if ($result['correct']) {
                        header('Location: ' . Url::to('question/' . $attemptId));
                        exit;
                    }

                    $feedback = 'Mauvaise réponse : une vie a été retirée. Réessaie la même question.';
                } catch (RuntimeException $e) {
                    try {
                        $currentAttempt = $game->attempt($attemptId, (int)$student['id']);
                        $status = (string)($currentAttempt['status'] ?? '');

                        if ($status === 'completed') {
                            header('Location: ' . Url::to('attempt/' . $attemptId . '/complete'));
                            exit;
                        }
                        if ($status === 'game_over') {
                            header('Location: ' . Url::to('attempt/' . $attemptId . '/game-over'));
                            exit;
                        }
                        if ($status === 'in_progress' && in_array($e->getMessage(), [
                            'Cette question a déjà été traitée. Recharge la page pour continuer.',
                            'Cette tentative a déjà été modifiée. Recharge la page.',
                        ], true)) {
                            header('Location: ' . Url::to('question/' . $attemptId));
                            exit;
                        }
                    } catch (Throwable) {
                    }

                    if ($e instanceof PDOException) {
                        Logger::exception($e, ['area' => 'game_submit', 'attempt_id' => $attemptId]);
                        $error = 'Impossible d’enregistrer la réponse pour le moment. Réessaie dans un instant.';
                    } else {
                        $error = $e->getMessage();
                    }
                } catch (Throwable $e) {
                    Logger::exception($e, ['area' => 'game_submit', 'attempt_id' => $attemptId]);
                    $error = 'Impossible d’enregistrer la réponse pour le moment. Réessaie dans un instant.';
                }
            }
        }

        $data = null;
        if ($error === null) {
            try {
                $data = $game->currentQuestion($attemptId, (int)$student['id']);
            } catch (RuntimeException $e) {
                if ($e instanceof PDOException) {
                    Logger::exception($e, ['area' => 'game_question', 'attempt_id' => $attemptId]);
                    $error = 'Impossible de charger la question pour le moment.';
                } else {
                    $error = $e->getMessage();
                }
            } catch (Throwable $e) {
                Logger::exception($e, ['area' => 'game_question', 'attempt_id' => $attemptId]);
                $error = 'Impossible de charger la question pour le moment.';
            }
        }

        View::render('game/question', [
            'attemptId' => $attemptId,
            'student' => $student,
            'data' => $data,
            'error' => $error,
            'feedback' => $feedback,
            'csrfToken' => Auth::csrfToken(),
        ]);
    }

    public function gameOver(string $attempt): void
    {
        $this->renderResult((int)$attempt, 'game_over', 'game/game-over');
    }

    public function complete(string $attempt): void
    {
        $this->renderResult((int)$attempt, 'completed', 'game/complete');
    }

    private function renderResult(int $attemptId, string $expectedStatus, string $view): void
    {
        $student = Auth::requireStudent(Url::to('login'), Url::to('change-password'));
        if ($attemptId < 1) {
            header('Location: ' . Url::to('dashboard'));
            exit;
        }

        $error = null;
        $attempt = null;

        try {
            $game = new GameService(Database::connection());
            $attempt = $game->attempt($attemptId, (int)$student['id']);
            if ((string)$attempt['status'] !== $expectedStatus) {
                header('Location: ' . Url::to('dashboard'));
                exit;
            }
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                Logger::exception($e, ['area' => 'game_result', 'attempt_id' => $attemptId]);
                $error = 'Impossible de charger le résultat pour le moment.';
            } else {
                $error = $e->getMessage();
            }
        } catch (Throwable $e) {
            Logger::exception($e, ['area' => 'game_result', 'attempt_id' => $attemptId]);
            $error = 'Impossible de charger le résultat pour le moment.';
        }

        View::render($view, [
            'attempt' => $attempt,
            'error' => $error,
        ]);
    }
}
