<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Url;
use App\Core\DateFormatter;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes quêtes — Tech4U-QUEST</title>
<link rel="icon" href="<?= e(Url::asset('images/icon.png')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
</head>
<body>
<header class="site-header"><div class="container nav">
<a class="brand" href="<?= e(Url::to('dashboard')) ?>"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a>
<nav class="nav-links"><a class="nav-link active" href="<?= e(Url::to('dashboard')) ?>">Mes quêtes</a><a class="nav-link" href="#badges">Badges</a><form method="post" action="<?= e(Url::to('logout')) ?>" style="margin:0"><input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><button class="btn btn-secondary" type="submit">Déconnexion</button></form></nav>
</div></header>
<main class="container page">
<div class="page-head"><div><span class="eyebrow">⚔️ TABLEAU DE BORD ÉLÈVE</span><h1>Bonjour <?= e((string)$student['login']) ?> 👋</h1><p>Choisis un module. Commence par le mode Facile puis débloque les niveaux suivants grâce aux badges.</p></div><div class="chip">🏆 <?= (int)$badgeCount ?> badge<?= (int)$badgeCount > 1 ? 's' : '' ?></div></div>

<?php if (!empty($error)): ?>
<div class="card card-pad" style="border-color:#fb7185;margin-bottom:1rem"><strong><?= e((string)$error) ?></strong></div>
<?php endif; ?>

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
<div class="module-footer"><span>Meilleur score : <?= $best ?>/<?= $total ?></span><?php if ($available): ?><a class="btn <?= $resume ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(Url::to('module/' . (int)$m['id'])) ?>"><?= $resume ? 'Continuer' : ($completed ? 'Rejouer' : 'Découvrir') ?> →</a><?php else: ?><span class="badge">Indisponible</span><?php endif; ?></div>
</article>
<?php endforeach; ?>
</section>

<section id="badges" style="margin-top:2rem"><div class="page-head"><div><span class="eyebrow">🏆 RÉCOMPENSES</span><h2>Mes badges</h2><p>Chaque module possède quatre badges : Facile, Moyen, Difficile et Expert.</p></div></div><div class="grid module-grid">
<?php if (!$badges): ?><div class="card card-pad"><p>Aucun badge pour le moment. Termine le mode Facile d’un module pour obtenir le premier.</p></div><?php endif; ?>
<?php foreach ($badges as $b): ?><article class="card module-card"><div class="module-icon"><?= e((string)($b['icon'] ?: '🏆')) ?></div><h3><?= e((string)$b['name']) ?></h3><p><?= e((string)($b['description'] ?? '')) ?></p><small><?= e((string)$b['module_title']) ?> · obtenu le <?= e(DateFormatter::human((string)$b['obtained_at'])) ?></small></article><?php endforeach; ?>
</div></section>
</main>
<?php require dirname(__DIR__) . '/_copyright.php'; ?>
</body>
</html>
