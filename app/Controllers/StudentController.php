<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Url;
use App\Core\View;
use App\Services\GameService;
use PDO;
use Throwable;

final class StudentController
{
    private function isDemoStudent(PDO $db, int $studentId): bool
    {
        $stmt = $db->prepare('SELECT UPPER(TRIM(class_code)) FROM students WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $studentId]);
        return (string)$stmt->fetchColumn() === 'DEMO';
    }

    public function dashboard(): void
    {
        $student = Auth::requireStudent(Url::to('login'));
        $db = Database::connection();
        $game = new GameService($db);
        $isDemo = $this->isDemoStudent($db, (int)$student['id']);
        $modules = $game->modulesForStudent((int)$student['id'], $isDemo);

        $badgeCountStmt = $db->prepare('SELECT COUNT(*) FROM student_path_badges WHERE student_id=:student');
        $badgeCountStmt->execute(['student' => $student['id']]);
        $badgeCount = (int)$badgeCountStmt->fetchColumn();

        $badgesStmt = $db->prepare(
            'SELECT pb.icon,pb.name,pb.description,spb.obtained_at,m.title AS module_title,p.name AS path_name
             FROM student_path_badges spb
             JOIN path_badges pb ON pb.id=spb.badge_id
             JOIN module_paths p ON p.id=pb.path_id
             JOIN modules m ON m.id=pb.module_id
             WHERE spb.student_id=:student
             ORDER BY spb.obtained_at DESC, p.display_order DESC'
        );
        $badgesStmt->execute(['student' => $student['id']]);
        $badges = $badgesStmt->fetchAll(PDO::FETCH_ASSOC);

        View::render('student/dashboard', [
            'student' => $student,
            'modules' => $modules,
            'badgeCount' => $badgeCount,
            'badges' => $badges,
        ]);
    }

    public function module(string $id): void
    {
        $student = Auth::requireStudent(Url::to('login'));
        $moduleId = max(1, (int)$id);
        $db = Database::connection();
        $game = new GameService($db);
        $isDemo = $this->isDemoStudent($db, (int)$student['id']);
        $module = null;
        $paths = [];
        $error = null;

        try {
            $module = $game->module($moduleId, $isDemo);
            $paths = $game->pathsForStudent($moduleId, (int)$student['id'], $isDemo);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        View::render('student/module', [
            'student' => $student,
            'module' => $module,
            'paths' => $paths,
            'error' => $error,
            'csrfToken' => Auth::csrfToken(),
        ]);
    }

    public function startModule(string $id): void
    {
        $student = Auth::requireStudent(Url::to('login'));
        $moduleId = max(1, (int)$id);
        $pathId = max(0, (int)($_POST['path_id'] ?? 0));
        $db = Database::connection();
        $game = new GameService($db);
        $isDemo = $this->isDemoStudent($db, (int)$student['id']);

        if (!Auth::validateCsrf(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
            http_response_code(419);
            View::render('student/module', [
                'student' => $student,
                'module' => null,
                'paths' => [],
                'error' => 'Jeton de sécurité invalide.',
                'csrfToken' => Auth::csrfToken(),
            ]);
            return;
        }

        try {
            if ($pathId < 1) {
                throw new \RuntimeException('Choisis un parcours avant de commencer.');
            }
            $game->module($moduleId, $isDemo);
            $attemptId = $game->startOrResume((int)$student['id'], $moduleId, $pathId, $isDemo, $isDemo);
            header('Location: ' . Url::to('question/' . $attemptId));
            exit;
        } catch (Throwable $e) {
            $module = null;
            $paths = [];
            try {
                $module = $game->module($moduleId, $isDemo);
                $paths = $game->pathsForStudent($moduleId, (int)$student['id'], $isDemo);
            } catch (Throwable) {
            }

            View::render('student/module', [
                'student' => $student,
                'module' => $module,
                'paths' => $paths,
                'error' => $e->getMessage(),
                'csrfToken' => Auth::csrfToken(),
            ]);
        }
    }
}
