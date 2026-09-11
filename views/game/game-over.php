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
    <title>Game Over — Tech4U-QUEST</title>
    <link rel="icon" type="image/png" href="<?= e(Url::asset('images/icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
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
        <div class="result-icon">💔</div>
        <h1>GAME OVER</h1>
        <p>Tu as épuisé tes vies. Ton score est enregistré. Lors de la nouvelle tentative du même parcours, les questions déjà rencontrées seront régénérées autant que possible, tandis que les questions futures seront conservées.</p>
        <h3><?= e((string)$attempt['module_title']) ?></h3>
        <?php if (!empty($attempt['path_name'])): ?><div class="chip"><?= e((string)($attempt['path_icon'] ?: '🎯')) ?> <?= e((string)$attempt['path_name']) ?></div><?php endif; ?>
        <div class="score-big"><?= (int)$attempt['score'] ?> / <?= (int)$attempt['total_questions'] ?></div>
        <div class="hero-actions pagination-center">
            <a class="btn btn-primary" href="<?= e(Url::to('module/' . (int)$attempt['module_id'])) ?>">↻ Nouvelle tentative</a>
            <a class="btn btn-secondary" href="<?= e(Url::to('dashboard')) ?>">Retour aux modules</a>
        </div>
    </section>
<?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/_copyright.php'; ?>
</body>
</html>
