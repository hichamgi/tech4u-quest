<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\GameController;
use App\Controllers\HomeController;
use App\Controllers\StudentController;
use App\Core\Router;

/** @var Router $router */
$router->get('/', [HomeController::class, 'index']);

$router->get('/login', [AuthController::class, 'login']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [StudentController::class, 'dashboard']);
$router->get('/module/{id}', [StudentController::class, 'module']);
$router->post('/module/{id}/start', [StudentController::class, 'startModule']);

$router->get('/question/{attempt}', [GameController::class, 'question']);
$router->post('/question/{attempt}', [GameController::class, 'question']);
