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
                    $initialLives = filter_var($_POST['initial_lives'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>20]]);
                    $badgeEnabled = isset($_POST['badge_enabled']) ? 1 : 0;
                    $moduleActive = isset($_POST['module_active']) ? 1 : 0;
                    if ($moduleId === false || $initialLives === false) throw new RuntimeException('Paramètres du module invalides.');
                    $categoryCounts = $_POST['category_count'] ?? [];
                    if (!is_array($categoryCounts)) throw new RuntimeException('Quotas de catégories invalides.');

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
                    if ($quotaSum < 1 || $quotaSum > 100) throw new RuntimeException('La somme des quotas doit être comprise entre 1 et 100 questions.');
                    $stmt = $db->prepare('UPDATE module_settings SET question_count=:q,initial_lives=:l,badge_enabled=:b WHERE module_id=:m');
                    $stmt->execute(['q'=>$quotaSum,'l'=>$initialLives,'b'=>$badgeEnabled,'m'=>$moduleId]);
                    $stmt = $db->prepare('UPDATE modules SET active=:active WHERE id=:id');
                    $stmt->execute(['active'=>$moduleActive,'id'=>$moduleId]);
                    $db->commit();
                    $message = 'Configuration enregistrée. Questions par tentative : '.$quotaSum.'. Module '.($moduleActive?'activé':'désactivé').' pour les élèves.';
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
                    COUNT(DISTINCT CASE WHEN q.active=1 THEN q.id END) active_questions,
                    b.name badge_name,b.icon badge_icon
             FROM modules m
             LEFT JOIN module_settings ms ON ms.module_id=m.id
             LEFT JOIN categories c ON c.module_id=m.id AND c.active=1
             LEFT JOIN questions q ON q.category_id=c.id
             LEFT JOIN badges b ON b.module_id=m.id
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
        foreach ($modules as &$module) {
            $categoriesStmt->execute(['module_id'=>$module['id']]);
            $module['categories'] = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($module);
        View::render('admin/modules', compact('modules','message','error'));
    }
}
