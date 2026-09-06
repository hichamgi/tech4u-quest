<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Database.php';
require_once dirname(__DIR__, 2) . '/app/Core/Auth.php';

use App\Core\Auth;
use App\Core\Database;

$admin = Auth::requireAdmin('../login.php');
$db = Database::connection();

function scalar(PDO $db, string $sql): int
{
    return (int)$db->query($sql)->fetchColumn();
}
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$stats = [
    'students' => scalar($db, 'SELECT COUNT(*) FROM students WHERE active = 1'),
    'classes' => scalar($db, 'SELECT COUNT(DISTINCT class_code) FROM students WHERE active = 1'),
    'modules' => scalar($db, 'SELECT COUNT(*) FROM modules WHERE active = 1'),
    'categories' => scalar($db, 'SELECT COUNT(*) FROM categories WHERE active = 1'),
    'questions' => scalar($db, 'SELECT COUNT(*) FROM questions WHERE active = 1'),
    'attempts' => scalar($db, 'SELECT COUNT(*) FROM attempts'),
    'badges' => scalar($db, 'SELECT COUNT(*) FROM badges'),
    'awarded_badges' => scalar($db, 'SELECT COUNT(*) FROM student_badges'),
];

$settingsRows = $db->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$schoolYear = (string)($settingsRows['school_year'] ?? 'Non définie');

$moduleStatus = $db->query(
    'SELECT m.id, m.icon, m.title, m.recommended_bank_size,
            ms.question_count, ms.initial_lives, ms.badge_enabled,
            COUNT(DISTINCT c.id) AS categories,
            COUNT(DISTINCT CASE WHEN q.active = 1 THEN q.id END) AS active_questions
     FROM modules m
     LEFT JOIN module_settings ms ON ms.module_id = m.id
     LEFT JOIN categories c ON c.module_id = m.id AND c.active = 1
     LEFT JOIN questions q ON q.category_id = c.id
     WHERE m.active = 1
     GROUP BY m.id
     ORDER BY m.display_order, m.id'
)->fetchAll(PDO::FETCH_ASSOC);

$quotaStmt = $db->prepare('SELECT COALESCE(SUM(question_count),0) FROM module_category_settings WHERE module_id = :id');
foreach ($moduleStatus as &$module) {
    $quotaStmt->execute(['id' => $module['id']]);
    $module['configured_quota'] = (int)$quotaStmt->fetchColumn();
}
unset($module);

$configItems = [
    ['title'=>'Année scolaire','ok'=>$schoolYear !== '' && $schoolYear !== 'Non définie','detail'=>$schoolYear,'link'=>'settings.php'],
    ['title'=>'Liste des élèves','ok'=>$stats['students'] > 0,'detail'=>$stats['students'].' élève(s) actif(s) dans '.$stats['classes'].' classe(s)','link'=>'students.php'],
    ['title'=>'Modules pédagogiques','ok'=>$stats['modules'] === 4,'detail'=>$stats['modules'].' module(s) actif(s)','link'=>'modules.php'],
    ['title'=>'Catégories pédagogiques','ok'=>$stats['categories'] === 40,'detail'=>$stats['categories'].' catégorie(s) active(s)','link'=>'modules.php'],
    ['title'=>'Banque de questions','ok'=>$stats['questions'] >= 330,'detail'=>$stats['questions'].' / 330 questions cibles','link'=>'questions.php'],
    ['title'=>'Badges','ok'=>$stats['badges'] >= 4,'detail'=>$stats['badges'].' badge(s) configuré(s)','link'=>'modules.php'],
];

$recent = $db->query(
    'SELECT a.id, s.login_code, m.title AS module_title, a.score, a.total_questions, a.status, a.started_at
     FROM attempts a
     JOIN students s ON s.id = a.student_id
     JOIN modules m ON m.id = a.module_id
     ORDER BY a.id DESC LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

$nav = [
    ['🏠','Tableau de bord','index.php'],
    ['🧭','Modules et configuration','modules.php'],
    ['❓','Questions','questions.php'],
    ['👥','Élèves','students.php'],
    ['⚙️','Paramètres','settings.php'],
];
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administration — Tech4U-QUEST</title><link rel="stylesheet" href="../assets/css/app.css"></head><body><div class="admin-shell"><aside class="sidebar"><a class="brand" href="../"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><nav class="side-nav"><?php foreach($nav as $n): ?><a class="<?= $n[2] === 'index.php' ? 'active' : '' ?>" href="<?= e($n[2]) ?>"><?= $n[0] ?> <?= e($n[1]) ?></a><?php endforeach; ?></nav></aside><main class="admin-main"><div class="page-head"><div><span class="eyebrow">⚙️ ADMINISTRATION</span><h1>Tableau de bord</h1><p>Connecté en tant que <?= e((string)$admin['login']) ?> · Année scolaire <?= e($schoolYear) ?></p></div><div style="display:flex;gap:.75rem;flex-wrap:wrap"><a class="btn btn-secondary" href="../">Voir le site</a><a class="btn btn-danger" href="../logout.php">Déconnexion</a></div></div><section class="grid kpi-grid"><div class="card kpi"><span>Élèves actifs</span><strong><?= $stats['students'] ?></strong><small><?= $stats['classes'] ?> classe(s)</small></div><div class="card kpi"><span>Modules</span><strong><?= $stats['modules'] ?></strong><small><?= $stats['categories'] ?> catégories</small></div><div class="card kpi"><span>Questions actives</span><strong><?= $stats['questions'] ?></strong><small>cible initiale : 330</small></div><div class="card kpi"><span>Tentatives</span><strong><?= $stats['attempts'] ?></strong><small><?= $stats['awarded_badges'] ?> badge(s) attribué(s)</small></div></section><section class="card" style="padding:1.25rem;margin-top:1rem"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap"><div><h2 style="margin:0">État de préparation du site</h2><p>Les éléments ci-dessous doivent être configurés avant l’ouverture aux élèves.</p></div><div style="display:flex;gap:.5rem;flex-wrap:wrap"><a class="btn btn-primary" href="questions.php">Gérer les questions</a><a class="btn btn-secondary" href="students.php">Importer les élèves CSV</a></div></div><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-top:1rem"><?php foreach ($configItems as $item): ?><a class="card" href="<?= e($item['link']) ?>" style="padding:1rem;text-decoration:none;color:inherit;border-color:<?= $item['ok'] ? '#2dd4bf' : '#f59e0b' ?>"><div style="display:flex;justify-content:space-between;gap:.75rem"><strong><?= e($item['title']) ?></strong><span><?= $item['ok'] ? '✅' : '⚠️' ?></span></div><p style="margin:.5rem 0 0"><?= e($item['detail']) ?></p></a><?php endforeach; ?></div></section><section class="card table-card" style="margin-top:1rem"><div class="card-pad"><div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap"><div><h2 style="margin:0">Configuration des modules</h2><p style="margin:.35rem 0 0">Contrôle des quotas et de la couverture de la banque de questions.</p></div><a class="btn btn-secondary" href="modules.php">Configurer</a></div></div><div style="overflow:auto"><table class="table"><thead><tr><th>Module</th><th>Catégories</th><th>Questions/tentative</th><th>Quotas</th><th>Vies</th><th>Banque</th><th>État</th></tr></thead><tbody><?php foreach ($moduleStatus as $module): $quotaOk=(int)$module['configured_quota']===(int)$module['question_count']; $bankOk=(int)$module['active_questions'] >= (int)$module['question_count']; $ok=$quotaOk && $bankOk && (int)$module['categories']>0; ?><tr><td><?= e((string)($module['icon'] ?: '📘')) ?> <strong><?= e((string)$module['title']) ?></strong></td><td><?= (int)$module['categories'] ?></td><td><?= (int)$module['question_count'] ?></td><td><?= (int)$module['configured_quota'] ?>/<?= (int)$module['question_count'] ?></td><td><?= (int)$module['initial_lives'] ?></td><td><?= (int)$module['active_questions'] ?>/<?= (int)$module['recommended_bank_size'] ?></td><td><span class="badge"><?= $ok ? 'Prêt' : 'À compléter' ?></span></td></tr><?php endforeach; ?></tbody></table></div></section><section class="card table-card" style="margin-top:1rem"><div class="card-pad"><h2 style="margin:0">Activité récente</h2></div><div style="overflow:auto"><table class="table"><thead><tr><th>Élève</th><th>Module</th><th>Score</th><th>État</th><th>Date</th></tr></thead><tbody><?php if (!$recent): ?><tr><td colspan="5">Aucune tentative pour le moment.</td></tr><?php endif; ?><?php foreach ($recent as $attempt): ?><tr><td><code><?= e((string)$attempt['login_code']) ?></code></td><td><?= e((string)$attempt['module_title']) ?></td><td><?= (int)$attempt['score'] ?>/<?= (int)$attempt['total_questions'] ?></td><td><span class="badge"><?= e((string)$attempt['status']) ?></span></td><td><?= e((string)$attempt['started_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section></main></div></body></html>
