<?php
declare(strict_types=1);

use App\Core\Url;

$title = $title ?? 'Administration — Tech4U-QUEST';
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#07101f">
<title><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars(Url::asset('images/icon.png'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(Url::asset('css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<div class="admin-shell">
<?php require __DIR__ . '/_sidebar.php'; ?>
<main class="admin-main">
