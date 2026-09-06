<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\GameController;
use App\Controllers\HomeController;
use App\Controllers\StudentController;
use App\Core\Router;
use App\Core\Url;

/** @var Router $router */
$router->get('/', [HomeController::class, 'index']);

$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/change-password', [AuthController::class, 'changePassword']);
$router->post('/change-password', [AuthController::class, 'changePassword']);
$router->get('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [StudentController::class, 'dashboard']);
$router->get('/module/{id}', [StudentController::class, 'module']);
$router->post('/module/{id}/start', [StudentController::class, 'startModule']);

$router->get('/question/{attempt}', [GameController::class, 'question']);
$router->post('/question/{attempt}', [GameController::class, 'question']);
$router->get('/attempt/{attempt}/game-over', [GameController::class, 'gameOver']);
$router->get('/attempt/{attempt}/complete', [GameController::class, 'complete']);

// Compatibilité avec les anciennes URL avant suppression des scripts publics historiques.
$router->get('/login.php', static function (): void { header('Location: '.Url::to('login'), true, 301); exit; });
$router->get('/logout.php', static function (): void { header('Location: '.Url::to('logout'), true, 301); exit; });
$router->get('/change-password.php', static function (): void { header('Location: '.Url::to('change-password'), true, 301); exit; });
$router->get('/dashboard.php', static function (): void { header('Location: '.Url::to('dashboard'), true, 301); exit; });
$router->get('/module.php', static function (): void { $id=max(1,(int)($_GET['id']??0)); header('Location: '.Url::to('module/'.$id), true, 301); exit; });
$router->get('/question.php', static function (): void { $id=max(1,(int)($_GET['attempt']??0)); header('Location: '.Url::to('question/'.$id), true, 301); exit; });
$router->get('/game-over.php', static function (): void { $id=max(1,(int)($_GET['attempt']??0)); header('Location: '.Url::to('attempt/'.$id.'/game-over'), true, 301); exit; });
$router->get('/module-complete.php', static function (): void { $id=max(1,(int)($_GET['attempt']??0)); header('Location: '.Url::to('attempt/'.$id.'/complete'), true, 301); exit; });
