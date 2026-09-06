<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Auth.php';

use App\Core\Auth;
use App\Core\Database;

$student = Auth::requireStudent('login.php', 'change-password.php', true);

if (strtoupper((string)($student['class_code'] ?? '')) === 'DEMO' || !Auth::studentNeedsPasswordChange()) {
    header('Location: dashboard.php');
    exit;
}

$db = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!Auth::validateCsrf(is_string($csrf) ? $csrf : null)) {
        $error = 'Session expirée. Recharge la page et recommence.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        $stmt = $db->prepare('SELECT password_hash FROM students WHERE id = :id AND active = 1 LIMIT 1');
        $stmt->execute(['id' => (int)$student['id']]);
        $currentHash = $stmt->fetchColumn();

        if (!$currentHash) {
            $error = 'Compte élève introuvable.';
        } elseif (password_verify($newPassword, (string)$currentHash)) {
            $error = 'Choisis un mot de passe différent du mot de passe provisoire.';
        } else {
            $update = $db->prepare(
                'UPDATE students
                 SET password_hash = :password_hash,
                     must_change_password = 0,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => (int)$student['id'],
            ]);

            Auth::markStudentPasswordChanged();
            header('Location: dashboard.php');
            exit;
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#07101f">
    <title>Changer le mot de passe — Tech4U-QUEST</title>
    <link rel="icon" type="image/png" href="assets/images/icon.png">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<main class="login-wrap">
    <section class="card login-card">
        <div class="login-logo"><img src="assets/images/logo.png" alt="Tech4U-QUEST"></div>
        <span class="eyebrow">🔐 PREMIÈRE CONNEXION</span>
        <h1>Choisis ton mot de passe</h1>
        <p>Le mot de passe provisoire doit être remplacé avant d’accéder à ton espace.</p>
        <p><strong>Compte :</strong> <?= e((string)$student['login']) ?></p>

        <?php if ($error !== ''): ?>
            <div class="demo-note" style="border-color:#ef4444;color:#fecaca;margin-bottom:1rem;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="change-password.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">

            <div class="form-group">
                <label class="label" for="new_password">Nouveau mot de passe</label>
                <input class="input" id="new_password" name="new_password" type="password"
                       minlength="6" autocomplete="new-password" required autofocus>
                <small>6 caractères minimum.</small>
            </div>

            <div class="form-group">
                <label class="label" for="confirm_password">Confirmer le mot de passe</label>
                <input class="input" id="confirm_password" name="confirm_password" type="password"
                       minlength="6" autocomplete="new-password" required>
            </div>

            <button class="btn btn-primary" type="submit">Enregistrer et continuer →</button>
        </form>

        <div style="margin-top:1rem;text-align:center">
            <a class="btn btn-secondary" href="logout.php">Se déconnecter</a>
        </div>
    </section>
</main>
</body>
</html>
