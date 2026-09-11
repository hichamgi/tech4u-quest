<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\Question;
use RuntimeException;
use Throwable;

final class QuestionExclusionController
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $model = new Question(Database::connection());
        $message = $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $groups = $_POST['group'] ?? [];
                    if (!is_array($groups)) {
                        throw new RuntimeException('Données invalides.');
                    }
                    $updated = $model->updateExclusionGroups($groups);
                    $message = $updated . ' question(s) mise(s) à jour sur cette page.';
                } catch (Throwable $e) {
                    $error = $this->safeError($e, 'update');
                }
            }
        }

        $module = max(0, (int)($_GET['module'] ?? 0));
        $category = max(0, (int)($_GET['category'] ?? 0));
        $search = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));

        try {
            $result = $model->paginateExclusions([
                'module' => $module,
                'category' => $category,
                'search' => $search,
            ], $page, self::PER_PAGE);
            $questions = $result['questions'];
            $page = $result['page'];
            $totalPages = $result['totalPages'];
            $totalRows = $result['totalRows'];
            $modules = $model->modules();
            $categories = $model->categories(true);
        } catch (Throwable $e) {
            Logger::exception($e, ['controller' => self::class, 'action' => 'load']);
            $questions = [];
            $modules = [];
            $categories = [];
            $page = 1;
            $totalPages = 1;
            $totalRows = 0;
            $error ??= 'Impossible de charger les groupes d’exclusion pour le moment.';
        }

        View::render('admin/questions/exclusions', compact(
            'message','error','module','category','search','questions','modules','categories',
            'page','totalPages','totalRows'
        ));
    }

    private function safeError(Throwable $e, string $action): string
    {
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }
        Logger::exception($e, ['controller' => self::class, 'action' => $action]);
        return 'Une erreur technique est survenue. Consulte le journal de l’application.';
    }
}
