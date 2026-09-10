<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Url;
use App\Core\View;
use App\Services\GameService;
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

        $db = Database::connection();
        $game = new GameService($db);
        $error = null;
        $feedback = null;

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
        if ($moduleActive === false) {
            $error = 'Tentative introuvable.';
        } elseif ((int)$moduleActive !== 1 && !$isDemo) {
            $error = 'Ce module n’est pas encore disponible. Il sera activé après son traitement en classe.';
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
                } catch (Throwable $e) {
                    // En cas de double clic / renvoi réseau, l’état peut avoir changé
                    // pendant que la seconde requête attendait le verrou SQLite.
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
                        // On conserve l’erreur initiale si la tentative ne peut pas être relue.
                    }

                    $error = $e->getMessage();
                }
            }
        }

        $data = null;
        if ($error === null) {
            try {
                $data = $game->currentQuestion($attemptId, (int)$student['id']);
            } catch (Throwable $e) {
                $error = $e->getMessage();
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

        $game = new GameService(Database::connection());
        $error = null;
        $attempt = null;

        try {
            $attempt = $game->attempt($attemptId, (int)$student['id']);
            if ((string)$attempt['status'] !== $expectedStatus) {
                header('Location: ' . Url::to('dashboard'));
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        View::render($view, [
            'attempt' => $attempt,
            'error' => $error,
        ]);
    }
}
