<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(string $view, array $data = []): void
    {
        $view = trim($view, '/');
        $file = dirname(__DIR__, 2) . '/views/' . $view . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('Vue introuvable : ' . $view);
        }

        extract($data, EXTR_SKIP);
        require $file;
    }
}
