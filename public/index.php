<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/config/config.php';
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($config['app_name']) ?></title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<main class="welcome">
    <img src="assets/images/logo.png" alt="Tech4U-QUEST" class="logo">
    <h1>Tech4U-QUEST</h1>
    <p>Le socle PHP du projet est prêt.</p>
</main>
</body>
</html>
