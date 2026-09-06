<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/config/config.php';
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#07101f">
<title><?= htmlspecialchars($config['app_name']) ?> — Apprendre en jouant</title>
<link rel="icon" type="image/png" href="assets/images/icon.png">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="site-header"><div class="container nav">
<a class="brand" href="./"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a>
<nav class="nav-links"><a class="nav-link active" href="./">Accueil</a><a class="nav-link" href="login.php">Connexion</a><a class="btn btn-primary" href="login.php">Commencer la quête →</a></nav>
</div></header>
<main class="container hero">
<section>
<span class="eyebrow">🎮 PLATEFORME D'APPRENTISSAGE GAMIFIÉE</span>
<h1><span class="gradient-text">Joue. Apprends.</span><br>Progresse.</h1>
<p>Transforme l'informatique en aventure. Réponds aux défis, protège tes 3 vies, progresse question après question et débloque les badges de chaque module.</p>
<div class="hero-actions"><a class="btn btn-primary" href="login.php">🚀 Démarrer</a></div>
<div class="stats-row"><div class="stat-mini"><strong>4</strong><span>modules de départ</span></div><div class="stat-mini"><strong>3 ❤️</strong><span>vies par tentative</span></div><div class="stat-mini"><strong>100%</strong><span>progression par défi</span></div></div>
</section>
<section class="hero-card"><img class="logo" src="assets/images/logo.png" alt="Logo Tech4U-QUEST"></section>
</main>
</body>
</html>