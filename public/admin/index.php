<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Database.php';
require_once dirname(__DIR__, 2) . '/app/Core/Auth.php';

use App\Core\Auth;

$admin = Auth::requireAdmin('../login.php');

$nav = [
    ['🏠','Tableau de bord','index.php'],
    ['🧭','Modules','modules.php'],
    ['🗂️','Catégories','#'],
    ['❓','Questions','#'],
    ['👥','Élèves','#'],
    ['📊','Résultats','#'],
    ['📅','Année scolaire','#'],
];
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administration — Tech4U-QUEST</title><link rel="stylesheet" href="../assets/css/app.css"></head><body><div class="admin-shell"><aside class="sidebar"><a class="brand" href="../"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><nav class="side-nav"><?php foreach($nav as $i=>$n): ?><a class="<?= $i===0?'active':'' ?>" href="<?= $n[2] ?>"><?= $n[0] ?> <?= $n[1] ?></a><?php endforeach; ?></nav></aside><main class="admin-main"><div class="page-head"><div><span class="eyebrow">⚙️ ADMINISTRATION</span><h1>Tableau de bord</h1><p>Connecté en tant que <?= htmlspecialchars((string)$admin['login'], ENT_QUOTES, 'UTF-8') ?>.</p></div><div style="display:flex;gap:.75rem"><a class="btn btn-secondary" href="../">Voir le site</a><a class="btn btn-danger" href="../logout.php">Déconnexion</a></div></div><section class="grid kpi-grid"><div class="card kpi"><span>Élèves actifs</span><strong>360</strong></div><div class="card kpi"><span>Modules</span><strong>4</strong></div><div class="card kpi"><span>Questions</span><strong>128</strong></div><div class="card kpi"><span>Tentatives</span><strong>842</strong></div></section><section class="card table-card"><div class="card-pad"><h2 style="margin:0">Activité récente</h2></div><table class="table"><thead><tr><th>Élève</th><th>Module</th><th>Score</th><th>État</th></tr></thead><tbody><tr><td>Élève démo 01</td><td>Systèmes informatiques</td><td>8/8</td><td><span class="badge">Terminé</span></td></tr><tr><td>Élève démo 02</td><td>Logiciels</td><td>5/8</td><td><span class="badge">En cours</span></td></tr><tr><td>Élève démo 03</td><td>Réseaux et Internet</td><td>3/8</td><td><span class="badge">Game over</span></td></tr></tbody></table></section></main></div></body></html>