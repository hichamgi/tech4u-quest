<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Auth.php';

use App\Core\Auth;

$student = Auth::requireStudent('login.php');

$modules=[
    ['🖥️','Généralités sur les systèmes informatiques','Matériel, périphériques, stockage et sécurité.',62],
    ['🧩','Logiciels','Systèmes, applications et outils numériques.',25],
    ['💻','Algorithme et programmation','Logique, variables, conditions et algorithmes.',0],
    ['🌐','Réseaux et Internet','Réseaux, protocoles, Web et cybersécurité.',0]
];
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mes quêtes — Tech4U-QUEST</title><link rel="icon" href="assets/images/icon.png"><link rel="stylesheet" href="assets/css/app.css"></head><body><header class="site-header"><div class="container nav"><a class="brand" href="./"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><nav class="nav-links"><a class="nav-link active" href="dashboard.php">Mes quêtes</a><a class="nav-link" href="#badges">Badges</a><a class="btn btn-secondary" href="logout.php">Déconnexion</a></nav></div></header><main class="container page"><div class="page-head"><div><span class="eyebrow">⚔️ TABLEAU DE BORD ÉLÈVE</span><h1>Bonjour <?= htmlspecialchars((string)$student['login'], ENT_QUOTES, 'UTF-8') ?> 👋</h1><p>Choisis un module et continue ta progression.</p></div><div class="chip">🏆 1 badge obtenu</div></div><section class="grid module-grid"><?php foreach($modules as $i=>$m): ?><article class="card module-card"><div class="module-top"><div class="module-icon"><?= $m[0] ?></div><span class="chip"><?= $m[3] ? 'En cours' : 'À découvrir' ?></span></div><h3><?= htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') ?></p><div class="progress"><span style="width:<?= $m[3] ?>%"></span></div><div class="module-footer"><span><?= $m[3] ?>% terminé</span><a class="btn <?= $i===0?'btn-primary':'btn-secondary' ?>" href="module.php?id=<?= $i+1 ?>"><?= $m[3]?'Continuer':'Découvrir' ?> →</a></div></article><?php endforeach; ?></section></main></body></html>