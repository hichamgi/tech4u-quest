<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\Student;
use App\Services\GameService;
use PDOException;
use RuntimeException;
use Throwable;

final class StudentController
{
    public function __construct(
        private Student $studentModel,
        private GameService $game
    ) {
    }

    public function dashboard(): void
    {
        $student = Auth::requireStudent(Url::to('login'));

        try {
            $isDemo = $this->studentModel->isDemo((int)$student['id']);
            $modules = $this->game->modulesForStudent((int)$student['id'], $isDemo);
            $badgeCount = $this->studentModel->badgeCount((int)$student['id']);
            $badges = $this->studentModel->badges((int)$student['id']);
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
            $isDemo = $this->studentModel->isDemo((int)$student['id']);
            $module = $this->game->module($moduleId, $isDemo);
            $paths = $this->game->pathsForStudent($moduleId, (int)$student['id'], $isDemo);
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
            $isDemo = $this->studentModel->isDemo((int)$student['id']);

            if ($pathId < 1) {
                throw new RuntimeException('Choisis un parcours avant de commencer.');
            }

            $this->game->module($moduleId, $isDemo);
            $attemptId = $this->game->startOrResume((int)$student['id'], $moduleId, $pathId, $isDemo, $isDemo);
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
            $isDemo ??= $this->studentModel->isDemo((int)$student['id']);
            $module = $this->game->module($moduleId, $isDemo);
            $paths = $this->game->pathsForStudent($moduleId, (int)$student['id'], $isDemo);
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
