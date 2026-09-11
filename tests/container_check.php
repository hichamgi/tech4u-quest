<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\Admin\ArchiveController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ModuleController;
use App\Controllers\Admin\QuestionController;
use App\Controllers\Admin\QuestionExclusionController;
use App\Controllers\Admin\QuestionExportController;
use App\Controllers\Admin\QuestionImportController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\StatisticsController;
use App\Controllers\Admin\StudentController as AdminStudentController;
use App\Controllers\AuthController;
use App\Controllers\GameController;
use App\Controllers\HomeController;
use App\Controllers\StudentController;
use App\Core\Container;
use App\Core\Database;

$controllers = [
    HomeController::class,
    AuthController::class,
    StudentController::class,
    GameController::class,
    DashboardController::class,
    StatisticsController::class,
    ModuleController::class,
    AdminStudentController::class,
    SettingsController::class,
    ArchiveController::class,
    QuestionController::class,
    QuestionExclusionController::class,
    QuestionImportController::class,
    QuestionExportController::class,
];

$container = new Container();
$failures = [];

foreach ($controllers as $controller) {
    try {
        $instance = $container->get($controller);
        if (!$instance instanceof $controller) {
            throw new RuntimeException('Type résolu incorrect.');
        }
        echo '[OK] DI ' . $controller . "\n";
    } catch (Throwable $e) {
        $failures[] = $controller . ' : ' . $e->getMessage();
        echo '[FAIL] DI ' . $controller . ' — ' . $e->getMessage() . "\n";
    }
}

Database::disconnect();

if ($failures !== []) {
    echo "\n" . count($failures) . " dépendance(s) non résolue(s).\n";
    exit(1);
}

echo "\nToutes les dépendances des contrôleurs sont résolues.\n";
exit(0);
