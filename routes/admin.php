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

// Anciennes URL conservées comme redirections HTTP après suppression des anciens scripts.
$router->get('/admin/index.php', static function ():void { header('Location: '.Url::to('admin'),true,301);exit; });
$router->get('/admin/modules.php', static function ():void { header('Location: '.Url::to('admin/modules'),true,301);exit; });
$router->get('/admin/students.php', static function ():void { $to=(($_GET['action']??'')==='template')?'admin/students/template':'admin/students';header('Location: '.Url::to($to),true,301);exit; });
$router->get('/admin/settings.php', static function ():void { header('Location: '.Url::to('admin/settings'),true,301);exit; });
$router->get('/admin/archive.php', static function ():void { header('Location: '.Url::to('admin/archive'),true,301);exit; });
$router->get('/admin/questions.php', static function ():void { header('Location: '.Url::to('admin/questions'),true,301);exit; });
$router->get('/admin/question-edit.php', static function ():void { $id=(int)($_GET['id']??0);header('Location: '.Url::to($id>0?'admin/questions/'.$id.'/edit':'admin/questions/new'),true,301);exit; });
$router->get('/admin/question-exclusions.php', static function ():void { header('Location: '.Url::to('admin/questions/exclusions'),true,301);exit; });
$router->get('/admin/questions-import.php', static function ():void { $to=isset($_GET['template'])?'admin/questions/template':'admin/questions/import';header('Location: '.Url::to($to),true,301);exit; });
$router->get('/admin/questions-export.php', static function ():void { header('Location: '.Url::to('admin/questions/export'),true,301);exit; });
$router->get('/admin/questions-reference.php', static function ():void { header('Location: '.Url::to('admin/questions/reference'),true,301);exit; });
