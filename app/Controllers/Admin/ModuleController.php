<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\Module;
use App\Services\ModuleConfigurationService;
use RuntimeException;
use Throwable;

final class ModuleController
{
    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        $moduleModel = new Module($db);
        $service = new ModuleConfigurationService($db, $moduleModel);
        $message = null;
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $moduleId = filter_var(
                        $_POST['module_id'] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]]
                    );
                    if ($moduleId === false) {
                        throw new RuntimeException('Paramètres du module invalides.');
                    }

                    $categoryCounts = $_POST['category_count'] ?? [];
                    if (!is_array($categoryCounts)) {
                        throw new RuntimeException('Quotas de catégories invalides.');
                    }

                    $service->save(
                        (int)$moduleId,
                        isset($_POST['module_active']),
                        $categoryCounts
                    );
                    $message = 'Configuration enregistrée.';
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    Logger::exception($e, ['controller' => self::class, 'action' => 'index']);
                    $error = 'Impossible d’enregistrer la configuration du module pour le moment.';
                }
            }
        }

        try {
            $modules = $moduleModel->adminList();
            foreach ($modules as &$module) {
                $moduleId = (int)$module['id'];
                $module['categories'] = $moduleModel->adminCategories($moduleId);
                $module['paths'] = $moduleModel->adminPaths($moduleId);
            }
            unset($module);
        } catch (Throwable $e) {
            Logger::exception($e, ['controller' => self::class, 'action' => 'load']);
            $modules = [];
            $error ??= 'Impossible de charger la configuration des modules pour le moment.';
        }

        View::render('admin/modules', compact('modules', 'message', 'error'));
    }
}
