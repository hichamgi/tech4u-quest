<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Url;
use App\Core\View;
use PDO;
use RuntimeException;
use Throwable;

final class ModuleController
{
    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
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
                    $moduleActive = isset($_POST['module_active']) ? 1 : 0;

                    if ($moduleId === false) {
                        throw new RuntimeException('Paramètres du module invalides.');
                    }

                    $categoryCounts = $_POST['category_count'] ?? [];
                    if (!is_array($categoryCounts)) {
                        throw new RuntimeException('Quotas de catégories invalides.');
                    }

                    $belongsStmt = $db->prepare(
                        'SELECT 1 FROM categories WHERE id=:category AND module_id=:module AND active=1'
                    );
                    $capacityStmt = $db->prepare(
                        "SELECT
                            SUM(CASE
                                WHEN q.active=1 AND (q.exclusion_group IS NULL OR TRIM(q.exclusion_group)='') THEN 1
                                ELSE 0
                            END)
                            + COUNT(DISTINCT CASE
                                WHEN q.active=1 AND q.exclusion_group IS NOT NULL AND TRIM(q.exclusion_group)<>''
                                THEN q.exclusion_group
                            END) AS usable_questions
                         FROM questions q
                         WHERE q.category_id=:category"
                    );

                    $normalizedCounts = [];
                    $quotaSum = 0;

                    foreach ($categoryCounts as $categoryIdRaw => $countRaw) {
                        $categoryId = filter_var(
                            $categoryIdRaw,
                            FILTER_VALIDATE_INT,
                            ['options' => ['min_range' => 1]]
                        );
                        $count = filter_var(
                            $countRaw,
                            FILTER_VALIDATE_INT,
                            ['options' => ['min_range' => 0, 'max_range' => 100]]
                        );

                        if ($categoryId === false || $count === false) {
                            throw new RuntimeException('Quota de catégorie invalide.');
                        }

                        $belongsStmt->execute(['category' => $categoryId, 'module' => $moduleId]);
                        if (!$belongsStmt->fetchColumn()) {
                            throw new RuntimeException('Une catégorie ne correspond pas au module sélectionné.');
                        }

                        if ($count > 0) {
                            $capacityStmt->execute(['category' => $categoryId]);
                            $usable = (int)$capacityStmt->fetchColumn();
                            if ($usable < $count) {
                                throw new RuntimeException(
                                    "La catégorie #{$categoryId} ne permet que {$usable} question(s) compatible(s) " .
                                    "avec les groupes d’exclusion, pour un quota demandé de {$count}."
                                );
                            }
                        }

                        $normalizedCounts[$categoryId] = $count;
                        $quotaSum += $count;
                    }

                    if ($quotaSum < 1 || $quotaSum > 100) {
                        throw new RuntimeException('La somme des quotas doit être comprise entre 1 et 100 questions.');
                    }

                    $db->beginTransaction();

                    $upsertCategory = $db->prepare(
                        'INSERT INTO module_category_settings(module_id,category_id,question_count)
                         VALUES(:module,:category,:count)
                         ON CONFLICT(module_id,category_id)
                         DO UPDATE SET question_count=excluded.question_count'
                    );
                    foreach ($normalizedCounts as $categoryId => $count) {
                        $upsertCategory->execute([
                            'module' => $moduleId,
                            'category' => $categoryId,
                            'count' => $count,
                        ]);
                    }

                    $pathsStmt = $db->prepare(
                        'SELECT id,display_order
                         FROM module_paths
                         WHERE module_id=:module
                         ORDER BY display_order,id'
                    );
                    $pathsStmt->execute(['module' => $moduleId]);
                    $modulePaths = $pathsStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($modulePaths) !== 4) {
                        throw new RuntimeException('Les quatre niveaux du module ne sont pas disponibles.');
                    }

                    $levelPercents = [1 => 25, 2 => 50, 3 => 75, 4 => 100];
                    $updatePath = $db->prepare(
                        'UPDATE module_paths
                         SET question_count=:count,pool_percent=:percent,active=1
                         WHERE id=:id AND module_id=:module'
                    );

                    foreach ($modulePaths as $path) {
                        $pathId = (int)$path['id'];
                        $order = (int)$path['display_order'];
                        $percent = $levelPercents[$order] ?? null;

                        if ($percent === null) {
                            throw new RuntimeException('Ordre de niveau invalide.');
                        }

                        $questionCount = max(1, (int)ceil($quotaSum * ($percent / 100)));
                        $updatePath->execute([
                            'count' => $questionCount,
                            'percent' => $percent,
                            'id' => $pathId,
                            'module' => $moduleId,
                        ]);
                    }

                    // Les badges de parcours sont obligatoires : ils pilotent le déblocage des niveaux suivants.
                    $stmt = $db->prepare(
                        'UPDATE module_settings
                         SET question_count=:count,initial_lives=3,badge_enabled=1
                         WHERE module_id=:module'
                    );
                    $stmt->execute(['count' => $quotaSum, 'module' => $moduleId]);

                    $stmt = $db->prepare('UPDATE modules SET active=:active WHERE id=:id');
                    $stmt->execute(['active' => $moduleActive, 'id' => $moduleId]);

                    $db->commit();
                    $message = 'Configuration enregistrée.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = $e->getMessage();
                }
            }
        }

        $modules = $db->query(
            'SELECT m.id,m.title,m.description,m.icon,m.recommended_bank_size,m.active,
                    ms.question_count,ms.initial_lives,
                    COUNT(DISTINCT c.id) category_total,
                    COUNT(DISTINCT CASE WHEN q.active=1 THEN q.id END) active_questions
             FROM modules m
             LEFT JOIN module_settings ms ON ms.module_id=m.id
             LEFT JOIN categories c ON c.module_id=m.id AND c.active=1
             LEFT JOIN questions q ON q.category_id=c.id
             GROUP BY m.id
             ORDER BY m.display_order,m.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $categoriesStmt = $db->prepare(
            "SELECT c.id,c.name,c.description,c.recommended_bank_size,
                    COALESCE(mcs.question_count,0) draw_count,
                    COUNT(CASE WHEN q.active=1 THEN q.id END) active_questions,
                    SUM(CASE
                        WHEN q.active=1 AND (q.exclusion_group IS NULL OR TRIM(q.exclusion_group)='') THEN 1
                        ELSE 0
                    END)
                    + COUNT(DISTINCT CASE
                        WHEN q.active=1 AND q.exclusion_group IS NOT NULL AND TRIM(q.exclusion_group)<>''
                        THEN q.exclusion_group
                    END) AS usable_questions
             FROM categories c
             LEFT JOIN module_category_settings mcs
                    ON mcs.category_id=c.id AND mcs.module_id=c.module_id
             LEFT JOIN questions q ON q.category_id=c.id
             WHERE c.module_id=:module_id AND c.active=1
             GROUP BY c.id
             ORDER BY c.display_order,c.id"
        );

        $pathsStmt = $db->prepare(
            'SELECT p.id,p.code,p.name,p.icon,p.description,p.pool_percent,p.question_count,p.display_order,
                    pb.name AS badge_name,pb.icon AS badge_icon,
                    COUNT(DISTINCT spb.student_id) AS badge_holders
             FROM module_paths p
             LEFT JOIN path_badges pb ON pb.path_id=p.id
             LEFT JOIN student_path_badges spb ON spb.badge_id=pb.id
             WHERE p.module_id=:module_id
             GROUP BY p.id
             ORDER BY p.display_order,p.id'
        );

        foreach ($modules as &$module) {
            $categoriesStmt->execute(['module_id' => $module['id']]);
            $module['categories'] = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

            $pathsStmt->execute(['module_id' => $module['id']]);
            $module['paths'] = $pathsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($module);

        View::render('admin/modules', compact('modules', 'message', 'error'));
    }
}
