<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Router;

$router = new Router();

require dirname(__DIR__) . '/routes/web.php';

$router->dispatch();
