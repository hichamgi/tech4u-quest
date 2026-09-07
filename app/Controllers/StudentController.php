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
    public function dashboard(): void
    {
        $student = Auth::requireStudent(Url::to('login'));
        $db = Database::connection();
        $game = new GameService($db);
        $isDemo = strtoupper((string)($student['class_code'] ?? '')) === 'DEMO';
        $modules = $game->modulesForStudent((int)$student['id'], $isDemo);

        $badgeCountStmt = $db->prepare('SELECT COUNT(*) FROM student_badges WHERE student_id=:student');
        $badgeCountStmt->execute(['student' => $student['id']]);
        $badgeCount = (int)$badgeCountStmt->fetchColumn();

        $badgesStmt = $db->prepare(
            'SELECT b.icon,b.name,b.description,sb.obtained_at,m.title AS module_title
             FROM student_badges sb
             JOIN badges b ON b.id=sb.badge_id
             JOIN modules m ON m.id=b.module_id
             WHERE sb.student_id=:student
             ORDER BY sb.obtained_at DESC'
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
        $game = new GameService(Database::connection());
        $isDemo = strtoupper((string)($student['class_code'] ?? '')) === 'DEMO';
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
        $isDemo = strtoupper((string)($student['class_code'] ?? '')) === 'DEMO';

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

        $game = new GameService(Database::connection());

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
