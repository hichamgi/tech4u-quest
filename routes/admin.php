<?php
declare(strict_types=1);

use App\Controllers\Admin\ArchiveController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ModuleController;
use App\Controllers\Admin\QuestionController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\StudentController;
use App\Core\Router;
use App\Core\Url;

/** @var Router $router */
$router->get('/admin', [DashboardController::class, 'index']);
$router->get('/admin/', [DashboardController::class, 'index']);

$router->get('/admin/modules', [ModuleController::class, 'index']);
$router->post('/admin/modules', [ModuleController::class, 'index']);

$router->get('/admin/students', [StudentController::class, 'index']);
$router->post('/admin/students', [StudentController::class, 'index']);
$router->get('/admin/students/template', [StudentController::class, 'template']);

$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->post('/admin/settings', [SettingsController::class, 'index']);

$router->get('/admin/archive', [ArchiveController::class, 'index']);
$router->post('/admin/archive', [ArchiveController::class, 'index']);

$router->get('/admin/questions', [QuestionController::class, 'index']);
$router->post('/admin/questions', [QuestionController::class, 'index']);
$router->get('/admin/questions/new', [QuestionController::class, 'create']);
$router->post('/admin/questions/new', [QuestionController::class, 'create']);
$router->get('/admin/questions/{id}/edit', [QuestionController::class, 'edit']);
$router->post('/admin/questions/{id}/edit', [QuestionController::class, 'edit']);
$router->get('/admin/questions/exclusions', [QuestionController::class, 'exclusions']);
$router->post('/admin/questions/exclusions', [QuestionController::class, 'exclusions']);
$router->get('/admin/questions/import', [QuestionController::class, 'import']);
$router->post('/admin/questions/import', [QuestionController::class, 'import']);
$router->get('/admin/questions/template', [QuestionController::class, 'template']);
$router->get('/admin/questions/export', [QuestionController::class, 'export']);
$router->get('/admin/questions/reference', [QuestionController::class, 'reference']);

// Compatibilité temporaire avec les anciennes URL .php.
$redirects = [
    '/admin/index.php' => 'admin',
    '/admin/modules.php' => 'admin/modules',
    '/admin/students.php' => 'admin/students',
    '/admin/settings.php' => 'admin/settings',
    '/admin/archive.php' => 'admin/archive',
    '/admin/questions.php' => 'admin/questions',
    '/admin/question-exclusions.php' => 'admin/questions/exclusions',
    '/admin/questions-import.php' => 'admin/questions/import',
];
foreach ($redirects as $from => $to) {
    $router->get($from, static function () use ($to): void {
        header('Location: ' . Url::to($to), true, 301);
        exit;
    });
}
