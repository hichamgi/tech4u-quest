<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__) . '/app';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$files = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $files[] = $file->getPathname();
}
sort($files, SORT_STRING);

foreach ($files as $file) {
    require_once $file;
}

echo '[OK] Chargement des classes applicatives : ' . count($files) . " fichier(s) PHP\n";
exit(0);
