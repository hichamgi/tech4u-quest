<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Auth.php';
require_once dirname(__DIR__) . '/app/Services/GameService.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\GameService;

$student = Auth::requireStudent('login.php');
$db = Database::connection();
$game = new GameService($db);
$moduleId = max(1, (int)($_GET['id'] ?? 0));
$error = null;
try {
    $module = $game->module($moduleId);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $module = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $module) {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Jeton de sécurité invalide.';
    } else {
        try {
            $attemptId = $game->startOrResume((int)$student['id'], $moduleId);
            header('Location: question.php?attempt=' . $attemptId);
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Module — Tech4U-QUEST</title><link rel="icon" type="image/png" href="assets/images/icon.png"><link rel="stylesheet" href="assets/css/app.css"></head><body><header class="site-header"><div class="container nav"><a class="brand" href="dashboard.php"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><div class="nav-links"><a class="nav-link" href="dashboard.php">← Mes quêtes</a></div></div></header><main class="container page">
<?php if ($error): ?><div class="card card-pad" style="border-color:#fb7185"><strong><?= e($error) ?></strong><div style="margin-top:1rem"><a class="btn btn-secondary" href="dashboard.php">Retour</a></div></div><?php elseif ($module): ?>
<div class="page-head"><div><span class="eyebrow"><?= e((string)($module['icon'] ?: '📘')) ?> MODULE <?= (int)$module['id'] ?></span><h1><?= e((string)$module['title']) ?></h1><p><?= e((string)($module['description'] ?? '')) ?></p></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><button class="btn btn-primary" type="submit">🎮 Lancer / continuer la quête</button></form></div>
<div class="quest-layout"><section class="card card-pad"><h2>Ta mission</h2><p style="color:var(--muted);line-height:1.7">Le parcours contient <strong><?= (int)$module['question_count'] ?> questions</strong>. Une mauvaise réponse retire une vie. Il faut répondre correctement à la question courante pour avancer.</p><div class="stats-row"><div class="stat-mini"><strong><?= (int)$module['question_count'] ?></strong><span>questions</span></div><div class="stat-mini"><strong><?= (int)$module['initial_lives'] ?> ❤️</strong><span>vies</span></div><div class="stat-mini"><strong><?= e((string)($module['badge_icon'] ?: '🏆')) ?></strong><span><?= e((string)($module['badge_name'] ?: 'Badge')) ?></span></div></div></section><aside class="side-stack"><div class="card side-card"><h3>Catégories du parcours</h3><div class="category-list"><?php foreach ($module['categories'] as $c): ?><div class="category"><span><?= e((string)$c['name']) ?></span><b><?= (int)$c['draw_count'] ?></b></div><?php endforeach; ?></div></div></aside></div>
<?php endif; ?>
</main></body></html>
