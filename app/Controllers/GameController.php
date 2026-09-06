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
        $student = Auth::requireStudent(Url::to('login'));
        $attemptId = (int)$attempt;

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
        } elseif ((int)$moduleActive !== 1) {
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
                        header('Location: ' . Url::to('module-complete.php?attempt=' . $attemptId));
                        exit;
                    }

                    if ($result['status'] === 'game_over') {
                        header('Location: ' . Url::to('game-over.php?attempt=' . $attemptId));
                        exit;
                    }

                    if ($result['correct']) {
                        header('Location: ' . Url::to('question/' . $attemptId));
                        exit;
                    }

                    $feedback = 'Mauvaise réponse : une vie a été retirée. Réessaie la même question.';
                } catch (Throwable $e) {
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
}
