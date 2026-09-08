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
<div class="card card-pad" style="border-color:#fb7185"><strong><?= e((string)$error) ?></strong><div style="margin-top:1rem"><a class="btn btn-secondary" href="<?= e(Url::to('dashboard')) ?>">Retour</a></div></div>
<?php elseif ($module): ?>
<div class="page-head"><div><span class="eyebrow"><?= e((string)($module['icon'] ?: '📘')) ?> MODULE <?= (int)$module['id'] ?></span><h1><?= e((string)$module['title']) ?></h1><p><?= e((string)($module['description'] ?? '')) ?></p></div></div>

<section style="margin-top:1.25rem">
    <div style="display:flex;justify-content:space-between;align-items:end;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
        <div>
            <h2 style="margin:0">Choisis ton mode</h2>
            <p style="margin:.4rem 0 0;color:var(--muted)">Commence obligatoirement par le mode Facile. Chaque mode terminé donne un badge, et ce badge débloque le mode suivant.</p>
        </div>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem">
        <?php foreach ($paths as $path):
            $code = (string)$path['code'];
            $mode = $modeLabels[$code] ?? (string)$path['name'];
            $unlocked = !empty($path['unlocked']);
            $completed = (int)($path['completed'] ?? 0) === 1;
            $badgeObtained = (int)($path['badge_obtained'] ?? 0) === 1;
            $currentAttempt = (int)($path['current_attempt_id'] ?? 0);
        ?>
        <article class="card" style="padding:1.15rem;<?= !$unlocked ? 'opacity:.55;filter:saturate(.65);' : '' ?>">
            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start">
                <div>
                    <div style="font-size:1.8rem"><?= e((string)($path['icon'] ?: '🎯')) ?></div>
                    <h3 style="margin:.45rem 0 .25rem">Mode <?= e($mode) ?></h3>
                </div>
                <span class="badge"><?= (int)$path['pool_percent'] ?> %</span>
            </div>
            <p style="color:var(--muted);min-height:3.5em"><?= e((string)($path['description'] ?? '')) ?></p>
            <div class="stats-row" style="margin:.9rem 0">
                <div class="stat-mini"><strong><?= (int)$path['question_count'] ?></strong><span>questions</span></div>
                <div class="stat-mini"><strong><?= (int)$module['initial_lives'] ?> ❤️</strong><span>vies</span></div>
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
        </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="quest-layout" style="margin-top:1.25rem"><section class="card card-pad"><h2>Progression et badges</h2><p style="color:var(--muted);line-height:1.7">Chaque module possède quatre badges : Facile, Moyen, Difficile et Expert. Obtenir le badge Facile débloque le mode Moyen ; le badge Moyen débloque le mode Difficile ; le badge Difficile débloque le mode Expert. Une mauvaise réponse retire une vie et il faut répondre correctement pour avancer.</p><div class="stats-row"><div class="stat-mini"><strong>4 🏅</strong><span>badges par module</span></div><div class="stat-mini"><strong>25 → 100 %</strong><span>couverture</span></div><div class="stat-mini"><strong><?= (int)$module['initial_lives'] ?> ❤️</strong><span>par tentative</span></div></div></section><aside class="side-stack"><div class="card side-card"><h3>Catégories du module</h3><div class="category-list"><?php foreach ($module['categories'] as $c): ?><div class="category"><span><?= e((string)$c['name']) ?></span><b><?= (int)$c['active_questions'] ?></b></div><?php endforeach; ?></div></div></aside></div>
<?php endif; ?>
</main>
</body>
</html>
