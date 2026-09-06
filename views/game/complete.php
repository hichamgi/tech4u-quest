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
    <title>Module terminé — Tech4U-QUEST</title>
    <link rel="icon" type="image/png" href="<?= e(Url::to('assets/images/icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(Url::to('assets/css/app.css')) ?>">
</head>
<body>
<header class="site-header"><div class="container nav"><a class="brand" href="<?= e(Url::to('dashboard')) ?>"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a></div></header>
<main class="container result-screen">
<?php if ($error): ?>
    <section class="card result-box">
        <h1>Erreur</h1>
        <p><?= e($error) ?></p>
        <a class="btn btn-secondary" href="<?= e(Url::to('dashboard')) ?>">Retour</a>
    </section>
<?php elseif ($attempt): ?>
    <section class="card result-box">
        <div class="result-icon">🏆</div>
        <h1>Quête accomplie !</h1>
        <p>Tu as terminé le module. Ton score et ton badge ont été enregistrés.</p>
        <h3><?= e((string)$attempt['module_title']) ?></h3>
        <div class="score-big"><?= (int)$attempt['score'] ?> / <?= (int)$attempt['total_questions'] ?></div>
        <?php if (!empty($attempt['badge_name'])): ?>
            <span class="chip"><?= e((string)($attempt['badge_icon'] ?: '⭐')) ?> Badge : <?= e((string)$attempt['badge_name']) ?></span>
        <?php endif; ?>
        <div class="hero-actions" style="justify-content:center;margin-top:25px">
            <a class="btn btn-gold" href="<?= e(Url::to('dashboard#badges')) ?>">Voir mes badges</a>
            <a class="btn btn-secondary" href="<?= e(Url::to('dashboard')) ?>">Retour aux modules</a>
        </div>
    </section>
<?php endif; ?>
</main>
</body>
</html>
