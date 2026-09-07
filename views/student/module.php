<?php
declare(strict_types=1);

use App\Core\Url;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
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
            <h2 style="margin:0">Choisis ton parcours</h2>
            <p style="margin:.4rem 0 0;color:var(--muted)">Chaque niveau ouvre une partie plus large de la banque de questions. Termine un parcours pour débloquer le suivant.</p>
        </div>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem">
        <?php foreach ($paths as $path):
            $unlocked = !empty($path['unlocked']);
            $completed = (int)($path['completed'] ?? 0) === 1;
            $currentAttempt = (int)($path['current_attempt_id'] ?? 0);
        ?>
        <article class="card" style="padding:1.15rem;<?= !$unlocked ? 'opacity:.58;' : '' ?>">
            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start">
                <div>
                    <div style="font-size:1.8rem"><?= e((string)($path['icon'] ?: '🎯')) ?></div>
                    <h3 style="margin:.45rem 0 .25rem"><?= e((string)$path['name']) ?></h3>
                </div>
                <span class="badge"><?= (int)$path['pool_percent'] ?> %</span>
            </div>
            <p style="color:var(--muted);min-height:3.5em"><?= e((string)($path['description'] ?? '')) ?></p>
            <div class="stats-row" style="margin:.9rem 0">
                <div class="stat-mini"><strong><?= (int)$path['question_count'] ?></strong><span>questions</span></div>
                <div class="stat-mini"><strong><?= (int)$module['initial_lives'] ?> ❤️</strong><span>vies</span></div>
            </div>

            <?php if ($completed): ?>
                <div class="chip" style="margin-bottom:.75rem">✅ Parcours terminé</div>
            <?php elseif (!$unlocked): ?>
                <div class="chip" style="margin-bottom:.75rem">🔒 Termine le parcours précédent</div>
            <?php elseif ($currentAttempt > 0): ?>
                <div class="chip" style="margin-bottom:.75rem">▶️ Partie en cours</div>
            <?php endif; ?>

            <?php if ($unlocked): ?>
            <form method="post" action="<?= e(Url::to('module/' . (int)$module['id'] . '/start')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e((string)$csrfToken) ?>">
                <input type="hidden" name="path_id" value="<?= (int)$path['id'] ?>">
                <button class="btn <?= (string)$path['code']==='expert' ? 'btn-gold' : 'btn-primary' ?>" type="submit" style="width:100%">
                    <?= $currentAttempt > 0 ? 'Continuer' : ($completed ? 'Rejouer' : 'Commencer') ?>
                </button>
            </form>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="quest-layout" style="margin-top:1.25rem"><section class="card card-pad"><h2>Comment fonctionne la progression ?</h2><p style="color:var(--muted);line-height:1.7">Le pourcentage indique la part de la banque de questions accessible au parcours. Les premiers parcours utilisent surtout les questions les plus faciles ; les niveaux supérieurs introduisent progressivement les questions plus difficiles. Une mauvaise réponse retire une vie et il faut répondre correctement pour avancer.</p><div class="stats-row"><div class="stat-mini"><strong>25 → 100 %</strong><span>couverture</span></div><div class="stat-mini"><strong><?= (int)$module['initial_lives'] ?> ❤️</strong><span>par tentative</span></div><div class="stat-mini"><strong><?= e((string)($module['badge_icon'] ?: '🏆')) ?></strong><span>badge en Expert</span></div></div></section><aside class="side-stack"><div class="card side-card"><h3>Catégories du module</h3><div class="category-list"><?php foreach ($module['categories'] as $c): ?><div class="category"><span><?= e((string)$c['name']) ?></span><b><?= (int)$c['active_questions'] ?></b></div><?php endforeach; ?></div></div></aside></div>
<?php endif; ?>
</main>
</body>
</html>
