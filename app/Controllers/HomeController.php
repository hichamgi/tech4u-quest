<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

final class HomeController
{
    public function index(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';

        View::render('home/index', [
            'config' => $config,
        ]);
    }
}
