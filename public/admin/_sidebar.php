<?php
declare(strict_types=1);

$activePage = $activePage ?? basename((string)($_SERVER['PHP_SELF'] ?? ''));
$adminNav = [
    ['🏠', 'Tableau de bord', 'index.php'],
    ['🧭', 'Modules et configuration', 'modules.php'],
    ['❓', 'Questions', 'questions.php'],
    ['👥', 'Élèves', 'students.php'],
    ['⚙️', 'Paramètres', 'settings.php'],
];

$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<aside class="sidebar">
    <a class="brand" href="../">
        <span class="brand-mark">⚡</span>
        <span>Tech4U <b>QUEST</b></span>
    </a>
    <nav class="side-nav">
        <?php foreach ($adminNav as $item): ?>
            <a class="<?= $item[2] === $activePage ? 'active' : '' ?>" href="<?= $esc($item[2]) ?>">
                <?= $item[0] ?> <?= $esc($item[1]) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
