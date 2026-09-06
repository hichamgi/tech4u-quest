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
$attemptId = (int)($_GET['attempt'] ?? 0);
$error = null;
try {
    $attempt = $game->attempt($attemptId, (int)$student['id']);
    if ((string)$attempt['status'] !== 'completed') {
        header('Location: dashboard.php');
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $attempt = null;
}
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Module terminé — Tech4U-QUEST</title><link rel="icon" type="image/png" href="assets/images/icon.png"><link rel="stylesheet" href="assets/css/app.css"></head><body><header class="site-header"><div class="container nav"><a class="brand" href="dashboard.php"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a></div></header><main class="container result-screen">
<?php if ($error): ?><section class="card result-box"><h1>Erreur</h1><p><?= e($error) ?></p><a class="btn btn-secondary" href="dashboard.php">Retour</a></section><?php elseif ($attempt): ?><section class="card result-box"><div class="result-icon">🏆</div><h1>Quête accomplie !</h1><p>Tu as terminé le module. Ton score et ton badge ont été enregistrés.</p><h3><?= e((string)$attempt['module_title']) ?></h3><div class="score-big"><?= (int)$attempt['score'] ?> / <?= (int)$attempt['total_questions'] ?></div><?php if (!empty($attempt['badge_name'])): ?><span class="chip"><?= e((string)($attempt['badge_icon'] ?: '⭐')) ?> Badge : <?= e((string)$attempt['badge_name']) ?></span><?php endif; ?><div class="hero-actions" style="justify-content:center;margin-top:25px"><a class="btn btn-gold" href="dashboard.php#badges">Voir mes badges</a><a class="btn btn-secondary" href="dashboard.php">Retour aux modules</a></div></section><?php endif; ?>
</main></body></html>
