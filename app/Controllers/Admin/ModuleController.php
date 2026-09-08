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
                    $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                    $badgeEnabled = isset($_POST['badge_enabled']) ? 1 : 0;
                    $moduleActive = isset($_POST['module_active']) ? 1 : 0;
                    if ($moduleId === false) throw new RuntimeException('Paramètres du module invalides.');

                    $categoryCounts = $_POST['category_count'] ?? [];
                    if (!is_array($categoryCounts)) throw new RuntimeException('Quotas de catégories invalides.');

                    $pathActive = $_POST['path_active'] ?? [];
                    if (!is_array($pathActive)) throw new RuntimeException('Configuration des niveaux invalide.');

                    $db->beginTransaction();
                    $quotaSum = 0;
                    foreach ($categoryCounts as $categoryId => $count) {
                        $categoryId = filter_var($categoryId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                        $count = filter_var($count, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>100]]);
                        if ($categoryId === false || $count === false) throw new RuntimeException('Quota de catégorie invalide.');

                        $belongs = $db->prepare('SELECT 1 FROM categories WHERE id=:c AND module_id=:m');
                        $belongs->execute(['c'=>$categoryId,'m'=>$moduleId]);
                        if (!$belongs->fetchColumn()) throw new RuntimeException('Une catégorie ne correspond pas au module sélectionné.');

                        $up = $db->prepare('INSERT INTO module_category_settings(module_id,category_id,question_count) VALUES(:m,:c,:q) ON CONFLICT(module_id,category_id) DO UPDATE SET question_count=excluded.question_count');
                        $up->execute(['m'=>$moduleId,'c'=>$categoryId,'q'=>$count]);
                        $quotaSum += $count;
                    }
                    if ($quotaSum < 1 || $quotaSum > 100) {
                        throw new RuntimeException('La somme des quotas doit être comprise entre 1 et 100 questions.');
                    }

                    $pathsStmt = $db->prepare('SELECT id,display_order FROM module_paths WHERE module_id=:module ORDER BY display_order,id');
                    $pathsStmt->execute(['module'=>$moduleId]);
                    $modulePaths = $pathsStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($modulePaths) !== 4) throw new RuntimeException('Les quatre niveaux du module ne sont pas disponibles.');

                    $levelPercents = [1=>25,2=>50,3=>75,4=>100];
                    $updatePath = $db->prepare('UPDATE module_paths SET question_count=:count,pool_percent=:percent,active=:active WHERE id=:id AND module_id=:module');
                    foreach ($modulePaths as $path) {
                        $pathId = (int)$path['id'];
                        $order = (int)$path['display_order'];
                        $percent = $levelPercents[$order] ?? null;
                        if ($percent === null) throw new RuntimeException('Ordre de niveau invalide.');

                        // Expert = quota pédagogique complet. Les autres niveaux sont une fraction
                        // croissante de ce quota, arrondie au supérieur.
                        $questionCount = max(1, (int)ceil($quotaSum * ($percent / 100)));
                        $active = isset($pathActive[$pathId]) ? 1 : 0;
                        if ($order === 1) $active = 1;

                        $updatePath->execute([
                            'count'=>$questionCount,
                            'percent'=>$percent,
                            'active'=>$active,
                            'id'=>$pathId,
                            'module'=>$moduleId,
                        ]);
                    }

                    $stmt = $db->prepare('UPDATE module_settings SET question_count=:q,initial_lives=3,badge_enabled=:b WHERE module_id=:m');
                    $stmt->execute(['q'=>$quotaSum,'b'=>$badgeEnabled,'m'=>$moduleId]);

                    $stmt = $db->prepare('UPDATE modules SET active=:active WHERE id=:id');
                    $stmt->execute(['active'=>$moduleActive,'id'=>$moduleId]);

                    $db->commit();
                    $message = 'Configuration enregistrée. Expert utilise les '.$quotaSum.' questions du quota pédagogique ; Facile, Moyen et Difficile utilisent automatiquement 25 %, 50 % et 75 % de ce quota.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = $e->getMessage();
                }
            }
        }

        $modules = $db->query(
            'SELECT m.id,m.title,m.description,m.icon,m.recommended_bank_size,m.active,
                    ms.question_count,ms.initial_lives,ms.badge_enabled,
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
            'SELECT c.id,c.name,c.description,c.recommended_bank_size,
                    COALESCE(mcs.question_count,0) draw_count,
                    COUNT(CASE WHEN q.active=1 THEN q.id END) active_questions
             FROM categories c
             LEFT JOIN module_category_settings mcs ON mcs.category_id=c.id AND mcs.module_id=c.module_id
             LEFT JOIN questions q ON q.category_id=c.id
             WHERE c.module_id=:module_id AND c.active=1
             GROUP BY c.id
             ORDER BY c.display_order,c.id'
        );

        $pathsStmt = $db->prepare(
            'SELECT p.id,p.code,p.name,p.icon,p.description,p.pool_percent,p.question_count,p.display_order,p.active,
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
            $categoriesStmt->execute(['module_id'=>$module['id']]);
            $module['categories'] = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
            $pathsStmt->execute(['module_id'=>$module['id']]);
            $module['paths'] = $pathsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($module);

        View::render('admin/modules', compact('modules','message','error'));
    }
}
