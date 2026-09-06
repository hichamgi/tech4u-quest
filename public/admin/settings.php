<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Database.php';
require_once dirname(__DIR__, 2) . '/app/Core/Auth.php';

use App\Core\Auth;
use App\Core\Database;

$admin = Auth::requireAdmin('../login.php');
$db = Database::connection();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Jeton de sécurité invalide.';
    } else {
        $siteName = trim((string)($_POST['site_name'] ?? ''));
        $schoolYear = trim((string)($_POST['school_year'] ?? ''));
        if ($siteName === '' || mb_strlen($siteName) > 80) {
            $error = 'Nom du site invalide.';
        } elseif (!preg_match('/^20\d{2}-20\d{2}$/', $schoolYear)) {
            $error = 'L’année scolaire doit être au format 2026-2027.';
        } else {
            $stmt = $db->prepare('INSERT INTO settings(key,value) VALUES(:key,:value) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
            $stmt->execute(['key' => 'site_name', 'value' => $siteName]);
            $stmt->execute(['key' => 'school_year', 'value' => $schoolYear]);
            $message = 'Paramètres enregistrés.';
        }
    }
}

$settings = $db->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$nav = [
    ['🏠','Tableau de bord','index.php'],
    ['🧭','Modules et configuration','modules.php'],
    ['👥','Élèves','students.php'],
    ['⚙️','Paramètres','settings.php'],
];
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paramètres — Tech4U-QUEST</title><link rel="stylesheet" href="../assets/css/app.css"></head><body><div class="admin-shell"><aside class="sidebar"><a class="brand" href="../"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><nav class="side-nav"><?php foreach($nav as $n): ?><a class="<?= $n[2] === 'settings.php' ? 'active' : '' ?>" href="<?= e($n[2]) ?>"><?= $n[0] ?> <?= e($n[1]) ?></a><?php endforeach; ?></nav></aside><main class="admin-main"><div class="page-head"><div><span class="eyebrow">⚙️ PARAMÈTRES GÉNÉRAUX</span><h1>Configuration du site</h1><p>Paramètres globaux de l’année en cours.</p></div><a class="btn btn-danger" href="../logout.php">Déconnexion</a></div><?php if($message): ?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf"><strong><?= e($message) ?></strong></div><?php endif; ?><?php if($error): ?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#fb7185"><strong><?= e($error) ?></strong></div><?php endif; ?><section class="card" style="padding:1.25rem;max-width:760px"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><div class="form-group"><label class="label" for="site_name">Nom du site</label><input class="input" id="site_name" name="site_name" maxlength="80" value="<?= e((string)($settings['site_name'] ?? 'Tech4U-QUEST')) ?>" required></div><div class="form-group"><label class="label" for="school_year">Année scolaire</label><input class="input" id="school_year" name="school_year" pattern="20[0-9]{2}-20[0-9]{2}" placeholder="2026-2027" value="<?= e((string)($settings['school_year'] ?? '2026-2027')) ?>" required><small>Format : 2026-2027</small></div><button class="btn btn-primary" type="submit">Enregistrer</button></form></section></main></div></body></html>
