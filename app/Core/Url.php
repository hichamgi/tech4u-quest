<?php
declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function basePath(): string
    {
        $contextPrefix = rtrim((string)($_SERVER['CONTEXT_PREFIX'] ?? ''), '/');
        if ($contextPrefix !== '') {
            return $contextPrefix;
        }

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(dirname($scriptName), '/.');

        return ($base === '' || $base === '/') ? '' : $base;
    }

    public static function to(string $path = ''): string
    {
        $base = self::basePath();
        $path = '/' . ltrim($path, '/');

        if ($path === '/') {
            return $base !== '' ? $base . '/' : '/';
        }

        return $base . $path;
    }

    public static function asset(string $path): string
    {
        return self::to('assets/' . ltrim($path, '/'));
    }
}
