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
    <title>Connexion — Tech4U-QUEST</title>
    <link rel="icon" type="image/png" href="<?= e(Url::asset('images/icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
</head>
<body>
<main class="login-wrap">
    <section class="card login-card">
        <div class="login-logo"><img src="<?= e(Url::asset('images/logo.png')) ?>" alt="Tech4U-QUEST"></div>
        <h1>Prêt pour la quête ?</h1>
        <p>Connecte-toi pour retrouver ton espace Tech4U-QUEST.</p>

        <?php if ($error !== ''): ?>
            <div class="demo-note" style="border-color:#ef4444;color:#fecaca;margin-bottom:1rem;">
                <?= e((string)$error) ?>
            </div>
        <?php endif; ?>

        <form action="<?= e(Url::to('login')) ?>" method="post" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= e((string)$csrfToken) ?>">
            <div class="form-group">
                <label class="label" for="identifier">Code élève ou utilisateur</label>
                <input class="input" id="identifier" name="identifier" autocomplete="username"
                       value="<?= e((string)$identifier) ?>"
                       placeholder="Ex. TCT1-12 ou admin" required autofocus>
            </div>
            <div class="form-group">
                <label class="label" for="password">Mot de passe</label>
                <input class="input" id="password" name="password" type="password"
                       autocomplete="current-password" placeholder="••••••••" required>
            </div>
            <button class="btn btn-primary" type="submit">Entrer dans Tech4U-QUEST →</button>
        </form>
    </section>
</main>
</body>
</html>
