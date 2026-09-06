<?php
declare(strict_types=1);

use App\Core\Url;

$activePage = $activePage ?? '';
$items = [
    ['key'=>'dashboard','icon'=>'📊','label'=>'Tableau de bord','url'=>Url::to('admin')],
    ['key'=>'modules','icon'=>'🧭','label'=>'Modules et configuration','url'=>Url::to('admin/modules')],
    ['key'=>'questions','icon'=>'❓','label'=>'Questions','url'=>Url::to('admin/questions')],
    ['key'=>'exclusions','icon'=>'🔀','label'=>'Exclusions de questions','url'=>Url::to('admin/questions/exclusions')],
    ['key'=>'students','icon'=>'👥','label'=>'Élèves','url'=>Url::to('admin/students')],
    ['key'=>'archive','icon'=>'🗄️','label'=>'Archivage','url'=>Url::to('admin/archive')],
    ['key'=>'settings','icon'=>'⚙️','label'=>'Paramètres','url'=>Url::to('admin/settings')],
];
?>
<aside class="sidebar">
    <a class="brand" href="<?= htmlspecialchars(Url::to('admin'), ENT_QUOTES, 'UTF-8') ?>">
        <span class="brand-mark">⚡</span>
        <span>Tech4U <b>QUEST</b></span>
    </a>

    <nav class="side-nav" aria-label="Navigation administration">
        <?php foreach ($items as $item): ?>
            <a class="<?= $activePage === $item['key'] ? 'active' : '' ?>"
               href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true"><?= $item['icon'] ?></span>
                <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
