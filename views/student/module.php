<?php
declare(strict_types=1);

use App\Core\Url;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$modeLabels = [
    'discovery' => 'Facile',
    'training' => 'Moyen',
    'mastery' => 'Difficile',
    'expert' => 'Expert',
];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Module — Tech4U-QUEST</title>
<link rel="icon" type="image/png" href="<?= e(Url::asset('images/icon.png')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
</head>
<body>
<header class="site-header"><div class="container nav"><a class="brand" href="<?= e(Url::to('dashboard')) ?>"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><div class="nav-links"><a class="nav-link" href="<?= e(Url::to('dashboard')) ?>">← Mes quêtes</a></div></div></header>
<main class="container page">
<?php if ($error): ?>
<div class="card card-pad notice-error"><strong><?= e((string)$error) ?></strong><div class="form-actions"><a class="btn btn-secondary" href="<?= e(Url::to('dashboard')) ?>">Retour</a></div></div>
<?php elseif ($module): ?>
<div class="page-head"><div><span class="eyebrow"><?= e((string)($module['icon'] ?: '📘')) ?> MODULE <?= (int)$module['id'] ?></span><h1><?= e((string)$module['title']) ?></h1><p><?= e((string)($module['description'] ?? '')) ?></p></div><div class="chip module-lives">❤️ 3 vies par tentative</div></div>
<section class="student-section"><div class="section-meta student-section-head"><div><h2>Choisis ton mode</h2><p>Commence par le mode Facile. Chaque badge obtenu débloque le mode suivant.</p></div></div><div class="mode-grid">
<?php foreach ($paths as $path): $code=(string)$path['code'];$mode=$modeLabels[$code]??(string)$path['name'];$unlocked=!empty($path['unlocked']);$completed=(int)($path['completed']??0)===1;$badgeObtained=(int)($path['badge_obtained']??0)===1;$currentAttempt=(int)($path['current_attempt_id']??0); ?>
<article class="card mode-card<?= !$unlocked ? ' mode-card-locked' : '' ?>"><div class="mode-title"><span class="mode-icon"><?= e((string)($path['icon'] ?: '🎯')) ?></span><h3>Mode <?= e($mode) ?></h3></div><p class="mode-description"><?= e((string)($path['description'] ?? '')) ?></p><div class="stats-row mode-stats"><div class="stat-mini"><strong><?= (int)$path['question_count'] ?></strong><span>questions</span></div></div>
<?php if ($badgeObtained): ?><div class="chip mode-status">🏅 Badge <?= e($mode) ?> obtenu</div><?php elseif (!$unlocked): ?><div class="chip mode-status">🔒 Badge du mode précédent requis</div><?php elseif ($currentAttempt > 0): ?><div class="chip mode-status">▶️ Partie en cours</div><?php elseif ($completed): ?><div class="chip mode-status">✅ Mode terminé</div><?php endif; ?>
<div class="mode-footer"><?php if ($unlocked): ?><form method="post" action="<?= e(Url::to('module/' . (int)$module['id'] . '/start')) ?>"><input type="hidden" name="csrf_token" value="<?= e((string)$csrfToken) ?>"><input type="hidden" name="path_id" value="<?= (int)$path['id'] ?>"><button class="btn btn-block <?= $code==='expert' ? 'btn-gold' : 'btn-primary' ?>" type="submit"><?= $currentAttempt > 0 ? 'Continuer le mode ' . e($mode) : ($badgeObtained ? 'Rejouer le mode ' . e($mode) : 'Lancer le mode ' . e($mode)) ?></button></form><?php else: ?><button class="btn btn-secondary btn-block btn-disabled" type="button" disabled>🔒 Mode <?= e($mode) ?> verrouillé</button><?php endif; ?></div></article>
<?php endforeach; ?></div></section>
<section class="card card-pad student-section"><h2>Progression et badges</h2><p class="muted-copy">Chaque fois que tu termines un mode, son badge devient actif et débloque le mode suivant.</p><div class="badge-progress-grid"><?php foreach ($paths as $path): $code=(string)$path['code'];$mode=$modeLabels[$code]??(string)$path['name'];$badgeObtained=(int)($path['badge_obtained']??0)===1; ?><div class="card badge-progress<?= $badgeObtained ? '' : ' badge-progress-locked' ?>"><div class="badge-progress-icon"><?= e((string)($path['icon'] ?: '🏅')) ?></div><strong>Badge <?= e($mode) ?></strong><div class="badge-progress-status"><span class="chip"><?= $badgeObtained ? '✅ Obtenu' : '🔒 Non obtenu' ?></span></div></div><?php endforeach; ?></div></section>
<?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/_copyright.php'; ?>
</body>
</html>
