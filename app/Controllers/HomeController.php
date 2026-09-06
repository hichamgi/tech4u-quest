<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Url;
use App\Core\View;

final class HomeController
{
    public function index(): void
    {
        if (Auth::check()) {
            if (Auth::isStudent()) {
                $target = Auth::studentNeedsPasswordChange() ? 'change-password' : 'dashboard';
                header('Location: ' . Url::to($target));
                exit;
            }

            if (Auth::isAdmin()) {
                header('Location: ' . Url::to('admin'));
                exit;
            }

            header('Location: ' . Url::to('logout'));
            exit;
        }

        $config = require dirname(__DIR__, 2) . '/config/config.php';

        View::render('home/index', [
            'config' => $config,
        ]);
    }
}
