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
    } elseif (($_POST['action'] ?? '') === 'reset_demo') {
        try {
            $demoId = 999999;
            $db->beginTransaction();

            $exists = $db->prepare('SELECT id FROM students WHERE id = :id LIMIT 1');
            $exists->execute(['id' => $demoId]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException('Le compte de démonstration n’existe pas encore. Exécute d’abord scripts/create-demo-student.php.');
            }

            $deleteBadges = $db->prepare('DELETE FROM student_badges WHERE student_id = :id');
            $deleteBadges->execute(['id' => $demoId]);

            // attempt_questions et attempt_answers sont supprimés par cascade avec les tentatives.
            $deleteAttempts = $db->prepare('DELETE FROM attempts WHERE student_id = :id');
            $deleteAttempts->execute(['id' => $demoId]);

            $deleteHistory = $db->prepare('DELETE FROM student_login_history WHERE student_id = :id');
            $deleteHistory->execute(['id' => $demoId]);

            $resetAccount = $db->prepare(
                'UPDATE students
                 SET class_code = :class_code,
                     student_number = :student_number,
                     login_code = :login_code,
                     password_hash = :password_hash,
                     must_change_password = 0,
                     active = 1,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $resetAccount->execute([
                'class_code' => 'DEMO',
                'student_number' => 1,
                'login_code' => 'demo-01',
                'password_hash' => password_hash('000000', PASSWORD_DEFAULT),
                'id' => $demoId,
            ]);

            $db->commit();
            $message = 'Compte demo-01 réinitialisé : progression, tentatives et badges effacés. Mot de passe remis à 000000.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
        }
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

$demo = $db->prepare(
    'SELECT s.id, s.login_code, s.active,
            (SELECT COUNT(*) FROM attempts a WHERE a.student_id=s.id) AS attempts,
            (SELECT COUNT(*) FROM student_badges sb WHERE sb.student_id=s.id) AS badges
     FROM students s WHERE s.id=:id LIMIT 1'
);
$demo->execute(['id' => 999999]);
$demoStudent = $demo->fetch(PDO::FETCH_ASSOC) ?: null;

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$activePage = 'students.php';
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
<?php require __DIR__.'/_sidebar.php'; ?>
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

<section class="card" style="padding:1.25rem;margin-top:1rem;border-color:#8b5cf6">
<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap">
<div>
<span class="eyebrow">🧪 COMPTE DE PRÉSENTATION</span>
<h2 style="margin:.4rem 0">Compte inspecteurs</h2>
<?php if ($demoStudent): ?>
<p style="margin:.3rem 0">Login : <code>demo-01</code> · Mot de passe : <code>000000</code></p>
<p style="margin:.3rem 0;color:var(--muted)">Tentatives enregistrées : <?= (int)$demoStudent['attempts'] ?> · Badges : <?= (int)$demoStudent['badges'] ?></p>
<?php else: ?>
<p>Le compte demo-01 n’existe pas encore. Crée-le avec <code>php scripts/create-demo-student.php</code>.</p>
<?php endif; ?>
</div>
<?php if ($demoStudent): ?>
<form method="post" onsubmit="return confirm('Réinitialiser demo-01 ? Toutes ses tentatives, réponses, scores et badges seront supprimés.');">
<input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
<input type="hidden" name="action" value="reset_demo">
<button class="btn btn-danger" type="submit">↻ Réinitialiser demo-01</button>
</form>
<?php endif; ?>
</div>
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
