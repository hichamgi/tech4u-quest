<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Auth.php';
require_once dirname(__DIR__, 2) . '/app/Services/ArchiveService.php';

use App\Core\Auth;
use App\Services\ArchiveService;

Auth::requireAdmin('../login.php');
$service = new ArchiveService();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Jeton de sécurité invalide.';
    } elseif (($_POST['action'] ?? '') === 'archive') {
        try {
            $result = $service->archiveAndResetStudents();
            $message = 'Archivage terminé : ' . $result['archive'] .
                '. La nouvelle base current.sqlite conserve les paramètres, comptes administratifs, modules, catégories, questions, réponses, quotas et badges, sans recopier les élèves ni leurs données.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$archives = $service->listArchives();
$activePage = 'archive.php';
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', ' ') . ' Go';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', ' ') . ' Mo';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', ' ') . ' Ko';
    return $bytes . ' o';
}
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Archivage — Tech4U-QUEST</title>
    <link rel="icon" type="image/png" href="../assets/images/icon.png">
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
<div class="admin-shell">
    <?php require __DIR__ . '/_sidebar.php'; ?>
    <main class="admin-main">
        <div class="page-head">
            <div>
                <span class="eyebrow">🗄️ ARCHIVAGE</span>
                <h1>Archivage annuel</h1>
                <p>Conserver l’année actuelle puis préparer une base propre pour les nouveaux élèves.</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf">
                <strong><?= e($message) ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#fb7185">
                <strong><?= e($error) ?></strong>
            </div>
        <?php endif; ?>

        <section class="card" style="padding:1.25rem;margin-bottom:1.25rem;max-width:900px">
            <h2 style="margin-top:0">Créer l’archive de l’année</h2>
            <p>L’action crée une copie complète de la base courante dans :</p>
            <pre style="white-space:pre-wrap">database/archives/<?= e(date('Y-m-d')) ?>.sqlite</pre>

            <p><strong>La base archivée reste complète</strong> : elle contient les élèves, leurs tentatives, réponses, scores et badges obtenus pendant l’année.</p>

            <p>Après l’archivage, <code>database/current.sqlite</code> est remplacée par une copie qui conserve toutes les données communes, notamment :</p>
            <p><code>settings</code>, <code>users</code>, <code>modules</code>, <code>categories</code>, <code>questions</code>, <code>question_answers</code>, <code>module_settings</code>, <code>module_category_settings</code> et <code>badges</code>.</p>

            <p>Les données propres aux élèves ne sont pas recopiées dans la nouvelle base :</p>
            <p><code>students</code>, <code>student_login_history</code>, <code>attempts</code>, <code>attempt_questions</code>, <code>attempt_answers</code> et <code>student_badges</code>.</p>

            <form method="post" onsubmit="return confirm('Confirmer l’archivage ? La base actuelle sera archivée puis les élèves et leurs données seront retirés de la nouvelle current.sqlite.');">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button class="btn btn-danger" type="submit" name="action" value="archive">🗄️ Archiver et préparer la nouvelle année</button>
            </form>
        </section>

        <section class="card" style="padding:1.25rem;max-width:900px">
            <h2 style="margin-top:0">Archives disponibles</h2>
            <?php if (!$archives): ?>
                <p>Aucune archive SQLite pour le moment.</p>
            <?php else: ?>
                <div style="overflow:auto">
                    <table class="table">
                        <thead><tr><th>Archive</th><th>Taille</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($archives as $archive): ?>
                            <tr>
                                <td><code><?= e($archive['name']) ?></code></td>
                                <td><?= e(formatBytes($archive['size'])) ?></td>
                                <td><?= e(date('d/m/Y H:i', $archive['modified'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
