<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Database.php';
require_once dirname(__DIR__, 2) . '/app/Core/Auth.php';
require_once dirname(__DIR__, 2) . '/app/Services/StudentCsvImportService.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\StudentCsvImportService;

$admin = Auth::requireAdmin('../login.php');
$db = Database::connection();

if (($_GET['action'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tech4u-eleves-template.csv"');
    echo "\xEF\xBB\xBF";
    echo "id;class_code;student_number;password;active;must_change_password\n";
    echo "157;TCT1;12;MotDePasseTemporaire;1;1\n";
    echo "158;TCT1;13;MotDePasseTemporaire;1;1\n";
    exit;
}

$message = null;
$error = null;
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Jeton de sécurité invalide. Recharge la page et recommence.';
    } elseif (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
        $error = 'Aucun fichier CSV reçu.';
    } elseif ((int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Erreur pendant l’envoi du fichier CSV.';
    } else {
        try {
            $service = new StudentCsvImportService($db);
            $importResult = $service->import(
                (string)$_FILES['csv']['tmp_name'],
                (int)$_FILES['csv']['size']
            );
            $message = sprintf(
                'Import terminé : %d ajouté(s), %d mis à jour, %d inchangé(s).',
                $importResult['created'],
                $importResult['updated'],
                $importResult['unchanged']
            );
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$filterClass = strtoupper(trim((string)($_GET['class'] ?? '')));
$params = [];
$sql = 'SELECT id, class_code, student_number, login_code, must_change_password, active, created_at, updated_at FROM students';
if ($filterClass !== '') {
    $sql .= ' WHERE class_code = :class_code';
    $params['class_code'] = $filterClass;
}
$sql .= ' ORDER BY class_code, student_number, id';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classes = $db->query('SELECT class_code, COUNT(*) AS total, SUM(active) AS active_total FROM students GROUP BY class_code ORDER BY class_code')->fetchAll(PDO::FETCH_ASSOC);
$totals = $db->query('SELECT COUNT(*) AS total, COALESCE(SUM(active),0) AS active, COALESCE(SUM(must_change_password),0) AS must_change FROM students')->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active'=>0,'must_change'=>0];

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

$nav = [
    ['🏠','Tableau de bord','index.php'],
    ['🧭','Modules et configuration','modules.php'],
    ['👥','Élèves','students.php'],
];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Élèves — Administration Tech4U-QUEST</title>
<link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
<div class="admin-shell">
<aside class="sidebar">
<a class="brand" href="../"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a>
<nav class="side-nav">
<?php foreach ($nav as $n): ?><a class="<?= $n[2] === 'students.php' ? 'active' : '' ?>" href="<?= e($n[2]) ?>"><?= $n[0] ?> <?= e($n[1]) ?></a><?php endforeach; ?>
</nav>
</aside>
<main class="admin-main">
<div class="page-head">
<div><span class="eyebrow">👥 GESTION DES ÉLÈVES</span><h1>Élèves</h1><p>Synchronisation avec les identifiants stables provenant de ta base MySQL locale.</p></div>
<div style="display:flex;gap:.75rem;flex-wrap:wrap"><a class="btn btn-secondary" href="students.php?action=template">Télécharger le modèle CSV</a><a class="btn btn-danger" href="../logout.php">Déconnexion</a></div>
</div>

<?php if ($message): ?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf"><strong><?= e($message) ?></strong></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#fb7185"><strong><?= e($error) ?></strong></div><?php endif; ?>
<?php if ($importResult && !empty($importResult['errors'])): ?>
<div class="card" style="padding:1rem;margin-bottom:1rem"><h3 style="margin-top:0">Lignes non importées</h3><ul><?php foreach ($importResult['errors'] as $rowError): ?><li><?= e((string)$rowError) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="grid kpi-grid">
<div class="card kpi"><span>Élèves</span><strong><?= (int)$totals['total'] ?></strong></div>
<div class="card kpi"><span>Actifs</span><strong><?= (int)$totals['active'] ?></strong></div>
<div class="card kpi"><span>Classes</span><strong><?= count($classes) ?></strong></div>
<div class="card kpi"><span>Mot de passe à changer</span><strong><?= (int)$totals['must_change'] ?></strong></div>
</section>

<section class="card" style="padding:1.25rem;margin-top:1rem">
<h2 style="margin-top:0">Importer / mettre à jour une liste CSV</h2>
<p>Colonnes : <code>id;class_code;student_number;password;active;must_change_password</code>. Les séparateurs <code>;</code>, <code>,</code> et tabulation sont acceptés.</p>
<p><strong>Règle importante :</strong> <code>id</code> est l’ID numérique stable de l’élève dans ta base MySQL locale. Pour un élève existant, laisse <code>password</code> vide si tu veux conserver son mot de passe actuel.</p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
<div class="form-group"><label class="label" for="csv">Fichier CSV (2 Mo maximum)</label><input class="input" type="file" id="csv" name="csv" accept=".csv,text/csv,text/plain" required></div>
<button class="btn btn-primary" type="submit">Importer et synchroniser</button>
</form>
</section>

<section class="card table-card" style="margin-top:1rem">
<div class="card-pad">
<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
<div><h2 style="margin:0">Liste actuelle</h2><p style="margin:.35rem 0 0">Aucun nom/prénom n’est stocké sur le serveur.</p></div>
<form method="get" style="display:flex;gap:.5rem;align-items:center"><select class="input" name="class" style="min-width:160px"><option value="">Toutes les classes</option><?php foreach ($classes as $c): ?><option value="<?= e((string)$c['class_code']) ?>" <?= $filterClass === (string)$c['class_code'] ? 'selected' : '' ?>><?= e((string)$c['class_code']) ?> (<?= (int)$c['total'] ?>)</option><?php endforeach; ?></select><button class="btn btn-secondary" type="submit">Filtrer</button></form>
</div>
</div>
<div style="overflow:auto">
<table class="table"><thead><tr><th>ID MySQL</th><th>Classe</th><th>N°</th><th>Login</th><th>État</th><th>Mot de passe</th><th>Mise à jour</th></tr></thead><tbody>
<?php if (!$students): ?><tr><td colspan="7">Aucun élève enregistré.</td></tr><?php endif; ?>
<?php foreach ($students as $student): ?><tr>
<td><?= (int)$student['id'] ?></td><td><?= e((string)$student['class_code']) ?></td><td><?= (int)$student['student_number'] ?></td><td><code><?= e((string)$student['login_code']) ?></code></td>
<td><span class="badge"><?= (int)$student['active'] === 1 ? 'Actif' : 'Inactif' ?></span></td>
<td><?= (int)$student['must_change_password'] === 1 ? 'À changer' : 'Défini' ?></td>
<td><?= e((string)($student['updated_at'] ?: $student['created_at'])) ?></td>
</tr><?php endforeach; ?>
</tbody></table>
</div>
</section>
</main>
</div>
</body>
</html>
