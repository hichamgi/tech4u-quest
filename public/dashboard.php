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
$modules = $game->modulesForStudent((int)$student['id']);

$badgeCountStmt = $db->prepare('SELECT COUNT(*) FROM student_badges WHERE student_id=:student');
$badgeCountStmt->execute(['student' => $student['id']]);
$badgeCount = (int)$badgeCountStmt->fetchColumn();

$badgesStmt = $db->prepare(
    'SELECT b.icon,b.name,b.description,sb.obtained_at,m.title AS module_title
     FROM student_badges sb JOIN badges b ON b.id=sb.badge_id JOIN modules m ON m.id=b.module_id
     WHERE sb.student_id=:student ORDER BY sb.obtained_at DESC'
);
$badgesStmt->execute(['student' => $student['id']]);
$badges = $badgesStmt->fetchAll(PDO::FETCH_ASSOC);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes quêtes — Tech4U-QUEST</title>
<link rel="icon" href="assets/images/icon.png"><link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="site-header"><div class="container nav"><a class="brand" href="dashboard.php"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><nav class="nav-links"><a class="nav-link active" href="dashboard.php">Mes quêtes</a><a class="nav-link" href="#badges">Badges</a><a class="btn btn-secondary" href="logout.php">Déconnexion</a></nav></div></header>
<main class="container page">
<div class="page-head"><div><span class="eyebrow">⚔️ TABLEAU DE BORD ÉLÈVE</span><h1>Bonjour <?= e((string)$student['login']) ?> 👋</h1><p>Choisis un module. Ta progression, tes scores et tes badges sont enregistrés automatiquement.</p></div><div class="chip">🏆 <?= $badgeCount ?> badge<?= $badgeCount > 1 ? 's' : '' ?></div></div>

<section class="grid module-grid">
<?php foreach ($modules as $m):
$total = max(1, (int)$m['question_count']);
$best = min($total, (int)($m['best_score'] ?? 0));
$percent = (int)round(($best / $total) * 100);
$completed = (int)$m['completed'] === 1;
$resume = !empty($m['current_attempt_id']);
$available = (int)$m['bank_size'] >= $total;
$status = $completed ? 'Terminé' : ($resume ? 'En cours' : ($available ? 'À commencer' : 'Banque incomplète'));
?>
<article class="card module-card">
<div class="module-top"><div class="module-icon"><?= e((string)($m['icon'] ?: '📘')) ?></div><span class="chip"><?= e($status) ?></span></div>
<h3><?= e((string)$m['title']) ?></h3><p><?= e((string)($m['description'] ?? '')) ?></p>
<div class="progress"><span style="width:<?= $percent ?>%"></span></div>
<div class="module-footer"><span>Meilleur score : <?= $best ?>/<?= $total ?></span><?php if ($available): ?><a class="btn <?= $resume ? 'btn-primary' : 'btn-secondary' ?>" href="module.php?id=<?= (int)$m['id'] ?>"><?= $resume ? 'Continuer' : ($completed ? 'Rejouer' : 'Découvrir') ?> →</a><?php else: ?><span class="badge">Indisponible</span><?php endif; ?></div>
</article>
<?php endforeach; ?>
</section>

<section id="badges" style="margin-top:2rem"><div class="page-head"><div><span class="eyebrow">🏆 RÉCOMPENSES</span><h2>Mes badges</h2></div></div><div class="grid module-grid">
<?php if (!$badges): ?><div class="card card-pad"><p>Aucun badge pour le moment. Termine un module pour débloquer le premier.</p></div><?php endif; ?>
<?php foreach ($badges as $b): ?><article class="card module-card"><div class="module-icon"><?= e((string)($b['icon'] ?: '🏆')) ?></div><h3><?= e((string)$b['name']) ?></h3><p><?= e((string)($b['description'] ?? '')) ?></p><small><?= e((string)$b['module_title']) ?> · obtenu le <?= e((string)$b['obtained_at']) ?></small></article><?php endforeach; ?>
</div></section>
</main></body></html>
