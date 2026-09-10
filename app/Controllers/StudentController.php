<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Services\GameService;
use PDO;
use PDOException;
use RuntimeException;
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

        try {
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
        } catch (Throwable $e) {
            Logger::exception($e, ['area' => 'student_dashboard', 'student_id' => (int)$student['id']]);
            http_response_code(503);
            View::render('student/dashboard', [
                'student' => $student,
                'modules' => [],
                'badgeCount' => 0,
                'badges' => [],
                'error' => 'Le tableau de bord est temporairement indisponible. Réessaie dans un instant.',
            ]);
            return;
        }

        View::render('student/dashboard', [
            'student' => $student,
            'modules' => $modules,
            'badgeCount' => $badgeCount,
            'badges' => $badges,
            'error' => null,
        ]);
    }

    public function module(string $id): void
    {
        $student = Auth::requireStudent(Url::to('login'));
        $moduleId = max(1, (int)$id);
        $module = null;
        $paths = [];
        $error = null;

        try {
            $db = Database::connection();
            $game = new GameService($db);
            $isDemo = $this->isDemoStudent($db, (int)$student['id']);
            $module = $game->module($moduleId, $isDemo);
            $paths = $game->pathsForStudent($moduleId, (int)$student['id'], $isDemo);
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                Logger::exception($e, ['area' => 'student_module', 'module_id' => $moduleId]);
                $error = 'Impossible de charger ce module pour le moment.';
            } else {
                $error = $e->getMessage();
            }
        } catch (Throwable $e) {
            Logger::exception($e, ['area' => 'student_module', 'module_id' => $moduleId]);
            $error = 'Impossible de charger ce module pour le moment.';
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
            $db = Database::connection();
            $game = new GameService($db);
            $isDemo = $this->isDemoStudent($db, (int)$student['id']);

            if ($pathId < 1) {
                throw new RuntimeException('Choisis un parcours avant de commencer.');
            }

            $game->module($moduleId, $isDemo);
            $attemptId = $game->startOrResume((int)$student['id'], $moduleId, $pathId, $isDemo, $isDemo);
            header('Location: ' . Url::to('question/' . $attemptId));
            exit;
        } catch (RuntimeException $e) {
            $error = $e instanceof PDOException
                ? 'Impossible de démarrer ce parcours pour le moment.'
                : $e->getMessage();

            if ($e instanceof PDOException) {
                Logger::exception($e, ['area' => 'student_start_module', 'module_id' => $moduleId, 'path_id' => $pathId]);
            }
        } catch (Throwable $e) {
            Logger::exception($e, ['area' => 'student_start_module', 'module_id' => $moduleId, 'path_id' => $pathId]);
            $error = 'Impossible de démarrer ce parcours pour le moment.';
        }

        $module = null;
        $paths = [];
        try {
            $db ??= Database::connection();
            $game ??= new GameService($db);
            $isDemo ??= $this->isDemoStudent($db, (int)$student['id']);
            $module = $game->module($moduleId, $isDemo);
            $paths = $game->pathsForStudent($moduleId, (int)$student['id'], $isDemo);
        } catch (Throwable $reloadError) {
            Logger::exception($reloadError, ['area' => 'student_start_module_reload', 'module_id' => $moduleId]);
        }

        View::render('student/module', [
            'student' => $student,
            'module' => $module,
            'paths' => $paths,
            'error' => $error,
            'csrfToken' => Auth::csrfToken(),
        ]);
    }
}
