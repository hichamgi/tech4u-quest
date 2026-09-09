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
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#07101f">
    <title>Changer le mot de passe — Tech4U-QUEST</title>
    <link rel="icon" type="image/png" href="<?= e(Url::to('assets/images/icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(Url::to('assets/css/app.css')) ?>">
</head>
<body>
<main class="login-wrap">
    <section class="card login-card">
        <div class="login-logo"><img src="<?= e(Url::to('assets/images/logo.png')) ?>" alt="Tech4U-QUEST"></div>
        <span class="eyebrow">🔐 PREMIÈRE CONNEXION</span>
        <h1>Choisis ton mot de passe</h1>
        <p>Le mot de passe provisoire doit être remplacé avant d’accéder à ton espace.</p>
        <p><strong>Compte :</strong> <?= e((string)$student['login']) ?></p>

        <?php if ($error !== ''): ?>
            <div class="demo-note" style="border-color:#ef4444;color:#fecaca;margin-bottom:1rem;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(Url::to('change-password')) ?>" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

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
            <a class="btn btn-secondary" href="<?= e(Url::to('logout')) ?>">Se déconnecter</a>
        </div>
    </section>
</main>
<?php require dirname(__DIR__) . '/_copyright.php'; ?>
</body>
</html>
