<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Logger
{
    public static function exception(Throwable $e, array $context = []): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/storage/logs';
        $path = $directory . '/app.log';

        $safeContext = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safeContext[(string)$key] = $value;
            }
        }

        $record = [
            'time' => date('c'),
            'level' => 'ERROR',
            'message' => $e->getMessage(),
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $safeContext,
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line)) {
            $line = date('c') . ' ERROR ' . $e::class . ': ' . $e->getMessage();
        }
        $line .= PHP_EOL;

        if ((!is_dir($directory) && !@mkdir($directory, 0775, true))
            || !@file_put_contents($path, $line, FILE_APPEND | LOCK_EX)) {
            error_log(trim($line));
        }
    }
}
