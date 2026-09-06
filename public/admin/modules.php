<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Database.php';
require_once dirname(__DIR__, 2) . '/app/Core/Auth.php';

use App\Core\Auth;
use App\Core\Database;

$admin = Auth::requireAdmin('../login.php');
$db = Database::connection();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Jeton de sécurité invalide.';
    } else {
        try {
            $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $initialLives = filter_var($_POST['initial_lives'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
            $badgeEnabled = isset($_POST['badge_enabled']) ? 1 : 0;

            if ($moduleId === false || $initialLives === false) {
                throw new RuntimeException('Paramètres du module invalides.');
            }

            $categoryCounts = $_POST['category_count'] ?? [];
            if (!is_array($categoryCounts)) {
                throw new RuntimeException('Quotas de catégories invalides.');
            }

            $db->beginTransaction();

            $quotaSum = 0;
            foreach ($categoryCounts as $categoryId => $count) {
                $categoryId = filter_var($categoryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $count = filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
                if ($categoryId === false || $count === false) {
                    throw new RuntimeException('Quota de catégorie invalide.');
                }

                $belongs = $db->prepare('SELECT 1 FROM categories WHERE id = :c AND module_id = :m');
                $belongs->execute(['c' => $categoryId, 'm' => $moduleId]);
                if (!$belongs->fetchColumn()) {
                    throw new RuntimeException('Une catégorie ne correspond pas au module sélectionné.');
                }

                $up = $db->prepare('INSERT INTO module_category_settings(module_id, category_id, question_count) VALUES(:m,:c,:q) ON CONFLICT(module_id, category_id) DO UPDATE SET question_count = excluded.question_count');
                $up->execute(['m' => $moduleId, 'c' => $categoryId, 'q' => $count]);
                $quotaSum += $count;
            }

            if ($quotaSum < 1 || $quotaSum > 100) {
                throw new RuntimeException('La somme des quotas doit être comprise entre 1 et 100 questions.');
            }

            // Le nombre de questions par tentative est toujours dérivé de la somme des quotas.
            $stmt = $db->prepare('UPDATE module_settings SET question_count = :q, initial_lives = :l, badge_enabled = :b WHERE module_id = :m');
            $stmt->execute(['q' => $quotaSum, 'l' => $initialLives, 'b' => $badgeEnabled, 'm' => $moduleId]);

            $db->commit();
            $message = "Configuration du module enregistrée. Questions par tentative : {$quotaSum}.";
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$modules = $db->query(
    'SELECT m.id, m.title, m.description, m.icon, m.recommended_bank_size, m.active,
            ms.question_count, ms.initial_lives, ms.badge_enabled,
            COUNT(DISTINCT c.id) AS category_total,
            COUNT(DISTINCT CASE WHEN q.active = 1 THEN q.id END) AS active_questions,
            b.name AS badge_name, b.icon AS badge_icon
     FROM modules m
     LEFT JOIN module_settings ms ON ms.module_id = m.id
     LEFT JOIN categories c ON c.module_id = m.id AND c.active = 1
     LEFT JOIN questions q ON q.category_id = c.id
     LEFT JOIN badges b ON b.module_id = m.id
     GROUP BY m.id
     ORDER BY m.display_order, m.id'
)->fetchAll(PDO::FETCH_ASSOC);

$categoriesStmt = $db->prepare(
    'SELECT c.id, c.name, c.description, c.recommended_bank_size,
            COALESCE(mcs.question_count,0) AS draw_count,
            COUNT(CASE WHEN q.active = 1 THEN q.id END) AS active_questions
     FROM categories c
     LEFT JOIN module_category_settings mcs ON mcs.category_id = c.id AND mcs.module_id = c.module_id
     LEFT JOIN questions q ON q.category_id = c.id
     WHERE c.module_id = :module_id AND c.active = 1
     GROUP BY c.id
     ORDER BY c.display_order, c.id'
);

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$activePage = 'modules.php';
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Modules — Administration Tech4U-QUEST</title><link rel="stylesheet" href="../assets/css/app.css"></head>
<body><div class="admin-shell">
<?php require __DIR__.'/_sidebar.php'; ?>
<main class="admin-main">
<div class="page-head"><div><span class="eyebrow">🧭 CONFIGURATION PÉDAGOGIQUE</span><h1>Modules et réglages</h1><p>Contrôle du nombre de questions, des vies, des quotas par catégorie et de la couverture de la banque.</p></div><a class="btn btn-danger" href="../logout.php">Déconnexion</a></div>
<?php if ($message): ?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#fb7185"><strong><?= e($error) ?></strong></div><?php endif; ?>

<?php foreach ($modules as $module):
$categoriesStmt->execute(['module_id' => $module['id']]);
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
$bankTarget = max(1, (int)$module['recommended_bank_size']);
$bankPercent = min(100, (int)round(((int)$module['active_questions'] / $bankTarget) * 100));
$currentQuotaTotal = array_sum(array_map(static fn(array $c): int => (int)$c['draw_count'], $categories));
?>
<section class="card" style="padding:1.25rem;margin-bottom:1rem">
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">
<div><span class="eyebrow"><?= e((string)($module['icon'] ?: '📘')) ?> MODULE <?= (int)$module['id'] ?></span><h2 style="margin:.35rem 0"><?= e((string)$module['title']) ?></h2><p><?= e((string)($module['description'] ?? '')) ?></p></div>
<div class="chip"><?= (int)$module['active_questions'] ?> / <?= (int)$module['recommended_bank_size'] ?> questions</div>
</div>
<div class="progress" style="margin:.8rem 0 1.25rem"><span style="width:<?= $bankPercent ?>%"></span></div>
<form method="post" class="module-config-form">
<input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><input type="hidden" name="module_id" value="<?= (int)$module['id'] ?>">
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem">
<div class="form-group"><label class="label">Questions par tentative</label><input class="input question-total" type="number" value="<?= $currentQuotaTotal ?>" readonly aria-readonly="true"><small style="color:var(--muted)">Calculé automatiquement à partir de la somme des quotas.</small></div>
<div class="form-group"><label class="label">Vies initiales</label><input class="input" type="number" min="1" max="20" name="initial_lives" value="<?= (int)$module['initial_lives'] ?>" required></div>
<div class="form-group"><label class="label">Badge</label><label style="display:flex;gap:.5rem;align-items:center;padding:.8rem 0"><input type="checkbox" name="badge_enabled" value="1" <?= (int)$module['badge_enabled'] === 1 ? 'checked' : '' ?>> <?= e((string)(($module['badge_icon'] ?? '') . ' ' . ($module['badge_name'] ?? ''))) ?></label></div>
</div>
<div style="overflow:auto"><table class="table"><thead><tr><th>Catégorie</th><th>Questions actives</th><th>Cible banque</th><th>Quota par tentative</th><th>État</th></tr></thead><tbody>
<?php foreach ($categories as $category): $enough = (int)$category['active_questions'] >= (int)$category['draw_count']; ?>
<tr><td><strong><?= e((string)$category['name']) ?></strong><br><small><?= e((string)($category['description'] ?? '')) ?></small></td><td><?= (int)$category['active_questions'] ?></td><td><?= (int)$category['recommended_bank_size'] ?></td><td><input class="input category-quota" style="max-width:100px" type="number" min="0" max="100" name="category_count[<?= (int)$category['id'] ?>]" value="<?= (int)$category['draw_count'] ?>" required></td><td><span class="badge"><?= $enough ? 'OK' : 'À compléter' ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div style="margin-top:1rem"><button class="btn btn-primary" type="submit">Enregistrer la configuration</button></div>
</form>
</section>
<?php endforeach; ?>
</main></div>
<script>
document.querySelectorAll('.module-config-form').forEach(function (form) {
    const total = form.querySelector('.question-total');
    const quotas = form.querySelectorAll('.category-quota');
    const refreshTotal = function () {
        let sum = 0;
        quotas.forEach(function (input) {
            const value = parseInt(input.value, 10);
            if (!Number.isNaN(value) && value > 0) sum += value;
        });
        total.value = sum;
    };
    quotas.forEach(function (input) {
        input.addEventListener('input', refreshTotal);
        input.addEventListener('change', refreshTotal);
    });
    refreshTotal();
});
</script>
</body></html>
