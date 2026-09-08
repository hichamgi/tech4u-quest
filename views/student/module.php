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
<style>
.module-lives{white-space:nowrap;display:inline-flex;align-items:center;flex-shrink:0}
.mode-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;align-items:stretch}
.mode-card{padding:1.15rem;display:flex;flex-direction:column;height:100%}
.mode-title{display:flex;align-items:center;gap:.5rem;white-space:nowrap;margin-bottom:.25rem}
.mode-title .mode-icon{font-size:1.8rem;line-height:1}
.mode-title h3{margin:0;white-space:nowrap}
.mode-description{color:var(--muted);min-height:3.5em}
.mode-footer{margin-top:auto;padding-top:.75rem}
.mode-footer form{margin:0}
</style>
</head>
<body>
<header class="site-header"><div class="container nav"><a class="brand" href="<?= e(Url::to('dashboard')) ?>"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><div class="nav-links"><a class="nav-link" href="<?= e(Url::to('dashboard')) ?>">← Mes quêtes</a></div></div></header>
<main class="container page">
<?php if ($error): ?>
<div class="card card-pad" style="border-color:#fb7185"><strong><?= e((string)$error) ?></strong><div style="margin-top:1rem"><a class="btn btn-secondary" href="<?= e(Url::to('dashboard')) ?>">Retour</a></div></div>
<?php elseif ($module): ?>
<div class="page-head"><div><span class="eyebrow"><?= e((string)($module['icon'] ?: '📘')) ?> MODULE <?= (int)$module['id'] ?></span><h1><?= e((string)$module['title']) ?></h1><p><?= e((string)($module['description'] ?? '')) ?></p></div><div class="chip module-lives">❤️ 3 vies par tentative</div></div>

<section style="margin-top:1.25rem">
    <div style="display:flex;justify-content:space-between;align-items:end;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
        <div>
            <h2 style="margin:0">Choisis ton mode</h2>
            <p style="margin:.4rem 0 0;color:var(--muted)">Commence par le mode Facile. Chaque badge obtenu débloque le mode suivant.</p>
        </div>
    </div>

    <div class="mode-grid">
        <?php foreach ($paths as $path):
            $code = (string)$path['code'];
            $mode = $modeLabels[$code] ?? (string)$path['name'];
            $unlocked = !empty($path['unlocked']);
            $completed = (int)($path['completed'] ?? 0) === 1;
            $badgeObtained = (int)($path['badge_obtained'] ?? 0) === 1;
            $currentAttempt = (int)($path['current_attempt_id'] ?? 0);
        ?>
        <article class="card mode-card" style="<?= !$unlocked ? 'opacity:.55;filter:saturate(.65);' : '' ?>">
            <div class="mode-title">
                <span class="mode-icon"><?= e((string)($path['icon'] ?: '🎯')) ?></span>
                <h3>Mode <?= e($mode) ?></h3>
            </div>
            <p class="mode-description"><?= e((string)($path['description'] ?? '')) ?></p>
            <div class="stats-row" style="margin:.9rem 0">
                <div class="stat-mini"><strong><?= (int)$path['question_count'] ?></strong><span>questions</span></div>
            </div>

            <?php if ($badgeObtained): ?>
                <div class="chip" style="margin-bottom:.75rem">🏅 Badge <?= e($mode) ?> obtenu</div>
            <?php elseif (!$unlocked): ?>
                <div class="chip" style="margin-bottom:.75rem">🔒 Badge du mode précédent requis</div>
            <?php elseif ($currentAttempt > 0): ?>
                <div class="chip" style="margin-bottom:.75rem">▶️ Partie en cours</div>
            <?php elseif ($completed): ?>
                <div class="chip" style="margin-bottom:.75rem">✅ Mode terminé</div>
            <?php endif; ?>

            <div class="mode-footer">
            <?php if ($unlocked): ?>
            <form method="post" action="<?= e(Url::to('module/' . (int)$module['id'] . '/start')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e((string)$csrfToken) ?>">
                <input type="hidden" name="path_id" value="<?= (int)$path['id'] ?>">
                <button class="btn <?= $code==='expert' ? 'btn-gold' : 'btn-primary' ?>" type="submit" style="width:100%">
                    <?= $currentAttempt > 0 ? 'Continuer le mode ' . e($mode) : ($badgeObtained ? 'Rejouer le mode ' . e($mode) : 'Lancer le mode ' . e($mode)) ?>
                </button>
            </form>
            <?php else: ?>
                <button class="btn btn-secondary" type="button" disabled style="width:100%;cursor:not-allowed">🔒 Mode <?= e($mode) ?> verrouillé</button>
            <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card card-pad" style="margin-top:1.25rem">
    <h2>Progression et badges</h2>
    <p style="color:var(--muted);line-height:1.7">Les badges sont désactivés au départ. Chaque fois que tu termines un mode, son badge devient actif et débloque le mode suivant.</p>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-top:1rem">
        <?php foreach ($paths as $path):
            $code = (string)$path['code'];
            $mode = $modeLabels[$code] ?? (string)$path['name'];
            $badgeObtained = (int)($path['badge_obtained'] ?? 0) === 1;
        ?>
            <div class="card" style="padding:1rem;text-align:center;<?= $badgeObtained ? '' : 'opacity:.42;filter:grayscale(1);' ?>">
                <div style="font-size:2.2rem;margin-bottom:.4rem"><?= e((string)($path['icon'] ?: '🏅')) ?></div>
                <strong>Badge <?= e($mode) ?></strong>
                <div style="margin-top:.55rem">
                    <span class="chip"><?= $badgeObtained ? '✅ Obtenu' : '🔒 Non obtenu' ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
</main>
</body>
</html>
