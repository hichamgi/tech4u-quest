<?php
declare(strict_types=1);

$activePage = $activePage ?? basename((string)($_SERVER['PHP_SELF'] ?? ''));
$adminNav = [
    ['🏠', 'Tableau de bord', 'index.php'],
    ['🧭', 'Modules et configuration', 'modules.php'],
    ['❓', 'Questions', 'questions.php'],
    ['🔀', 'Exclusions de questions', 'question-exclusions.php'],
    ['👥', 'Élèves', 'students.php'],
    ['🗄️', 'Archivage', 'archive.php'],
    ['⚙️', 'Paramètres', 'settings.php'],
];

$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<script>
(() => {
    let favicon = document.querySelector('link[rel="icon"]');
    if (!favicon) {
        favicon = document.createElement('link');
        favicon.rel = 'icon';
        document.head.appendChild(favicon);
    }
    favicon.type = 'image/png';
    favicon.href = '../assets/images/icon.png';
})();
</script>
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
