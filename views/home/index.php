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
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#07101f">
<title><?= e((string)$config['app_name']) ?> — Apprendre en jouant</title>
<link rel="icon" type="image/png" href="<?= e(Url::asset('images/icon.png')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
</head>
<body>
<header class="site-header"><div class="container nav">
<a class="brand" href="<?= e(Url::to()) ?>"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a>
<nav class="nav-links"><a class="nav-link active" href="<?= e(Url::to()) ?>">Accueil</a></nav>
</div></header>
<main class="container hero">
<section>
<span class="eyebrow">🎮 PLATEFORME D'APPRENTISSAGE GAMIFIÉE</span>
<h1><span class="gradient-text">Joue. Apprends.</span><br>Progresse.</h1>
<p>Transforme l'informatique en aventure. Réponds aux défis, protège tes 3 vies, progresse question après question et débloque les badges de chaque module.</p>
<div class="hero-actions"><a class="btn btn-primary" href="<?= e(Url::to('login')) ?>">🚀 Démarrer</a></div>
<div class="stats-row"><div class="stat-mini"><strong>4</strong><span>modules de départ</span></div><div class="stat-mini"><strong>3 ❤️</strong><span>vies par tentative</span></div><div class="stat-mini"><strong>100%</strong><span>progression par défi</span></div></div>
</section>
<section class="hero-card"><img class="logo" src="<?= e(Url::asset('images/logo.png')) ?>" alt="Logo Tech4U-QUEST"></section>
</main>
<?php require dirname(__DIR__) . '/_copyright.php'; ?>
</body>
</html>
