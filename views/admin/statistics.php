<?php
declare(strict_types=1);

function e_admin_stats(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$title = 'Statistiques — Tech4U-QUEST';
$activePage = 'statistics';
require __DIR__ . '/_header.php';

$maxDaily = 1;
foreach ($activity as $row) $maxDaily = max($maxDaily, (int)$row['attempts']);
$maxModuleAttempts = 1;
foreach ($moduleStats as $row) $maxModuleAttempts = max($maxModuleAttempts, (int)$row['attempts']);
$maxPathAttempts = 1;
foreach ($pathStats as $row) $maxPathAttempts = max($maxPathAttempts, (int)$row['attempts']);

$levelByOrder = [];
foreach ($levelDefs as $level) $levelByOrder[(int)$level['display_order']] = $level;
?>

<div class="page-head"><div><span class="eyebrow">📈 ANALYSE D'UTILISATION</span><h1>Graphiques et statistiques</h1><p>Vue globale, par classe et individuelle. Les comptes de la classe DEMO sont exclus.</p></div></div>

<section class="stats-grid">
<div class="card stat-card"><span>Élèves</span><strong><?= $kpis['students'] ?></strong><small><?= $kpis['active_students'] ?> ont déjà joué</small></div>
<div class="card stat-card"><span>Tentatives</span><strong><?= $kpis['attempts'] ?></strong><small><?= $kpis['completed'] ?> parcours terminés</small></div>
<div class="card stat-card"><span>Réponses données</span><strong><?= $kpis['answers'] ?></strong><small><?= $kpis['success_rate'] ?>% correctes</small></div>
<div class="card stat-card"><span>Badges de niveau</span><strong><?= $kpis['badges'] ?></strong><small>Facile, Moyen, Difficile et Expert</small></div>
</section>

<section class="card chart-card"><div class="chart-title"><div><h2>Activité sur les 14 derniers jours</h2><p>Nombre de nouvelles tentatives démarrées chaque jour.</p></div></div><div class="daily-chart" aria-label="Tentatives par jour"><?php foreach ($activity as $row): $height=max(2,(int)round(((int)$row['attempts']/$maxDaily)*100)); ?><div class="daily-col" title="<?= e_admin_stats((string)$row['label']) ?> : <?= (int)$row['attempts'] ?> tentative(s)"><span class="daily-value"><?= (int)$row['attempts'] ?></span><div class="daily-bar-wrap"><div class="daily-bar" style="height:<?= $height ?>%"></div></div><span class="daily-label"><?= e_admin_stats((string)$row['label']) ?></span></div><?php endforeach; ?></div></section>

<section class="card chart-card"><div class="chart-title"><div><h2>Activité par module</h2><p>Tentatives et progression moyenne.</p></div></div><div class="module-bars"><?php foreach ($moduleStats as $module): $attempts=(int)$module['attempts'];$width=$attempts>0?max(3,(int)round(($attempts/$maxModuleAttempts)*100)):0; ?><div class="module-row"><div class="module-label"><strong><?= e_admin_stats((string)($module['icon'] ?: '📘')) ?> <?= e_admin_stats((string)$module['title']) ?></strong><small><?= (int)$module['completed'] ?> terminée(s) · <?= (int)$module['game_over'] ?> game over</small></div><div><div class="bar-track"><div class="bar-fill" style="width:<?= $width ?>%"></div></div><div class="progress-stack"><div class="progress-line"><span>Progression moyenne</span><strong><?= (int)$module['avg_progress'] ?>%</strong></div></div></div><div class="module-number"><?= $attempts ?> tentative<?= $attempts > 1 ? 's' : '' ?></div></div><?php endforeach; ?></div></section>

<section class="card chart-card"><div class="chart-title"><div><h2>Progression par niveau</h2><p>Facile → Moyen → Difficile → Expert, données cumulées sur les modules.</p></div></div><div class="path-grid"><?php foreach ($pathStats as $path): $attempts=(int)$path['attempts'];$completed=(int)$path['completed'];$rate=$attempts>0?(int)round(($completed/$attempts)*100):0;$width=$attempts>0?max(3,(int)round(($attempts/$maxPathAttempts)*100)):0; ?><article class="card path-stat"><div><?= e_admin_stats((string)($path['icon'] ?: '🎯')) ?> <span class="badge"><?= (int)$path['pool_percent'] ?>%</span></div><h3><?= e_admin_stats((string)$path['name']) ?></h3><div class="big"><?= $attempts ?></div><small>tentative<?= $attempts>1?'s':'' ?></small><div class="bar-track"><div class="bar-fill" style="width:<?= $width ?>%"></div></div><div class="progress-line"><span>Parcours terminés</span><strong><?= $completed ?></strong></div><div class="progress-line"><span>Taux de complétion</span><strong><?= $rate ?>%</strong></div><div class="progress-line"><span>Élèves ayant terminé</span><strong><?= (int)$path['students_completed'] ?></strong></div><div class="progress-line"><span>Élèves avec badge</span><strong><?= (int)$path['badge_holders'] ?></strong></div></article><?php endforeach; ?></div></section>

<section class="card chart-card"><div class="chart-title"><div><h2>Progression par classe et par niveau</h2><p>Nombre d'élèves de chaque classe ayant obtenu au moins un badge du niveau concerné.</p></div></div><div class="stats-table-wrap"><table class="stats-table"><thead><tr><th>Classe</th><th>Élèves</th><?php foreach($levelDefs as $level):?><th><?=e_admin_stats((string)$level['icon'])?> <?=e_admin_stats((string)$level['name'])?></th><?php endforeach;?><th>Total badges</th></tr></thead><tbody><?php foreach($classStats as $row):$class=(string)$row['class_code'];?><tr><td><strong><?=e_admin_stats($class)?></strong></td><td><?= (int)$row['students']?></td><?php foreach($levelDefs as $level):$ls=$classLevelStats[$class][(string)$level['code']]??null;?><td><?= (int)($ls['students_with_badge']??0)?> élève(s)<br><small><?= (int)($ls['badges']??0)?> badge(s)</small></td><?php endforeach;?><td><strong><?= (int)$row['badges']?></strong></td></tr><?php endforeach;?></tbody></table></div></section>

<section class="card chart-card"><div class="chart-title"><div><h2>Progression individuelle</h2><p>Niveau le plus élevé obtenu dans chaque module. L'identifiant pédagogique est utilisé, sans nom ni prénom.</p></div></div><div class="filter-row"><input class="input" id="student-search" type="search" placeholder="Rechercher TCT1-12 ou TCT1"><select class="input" id="class-filter"><option value="">Toutes les classes</option><?php $seen=[];foreach($studentStats as $student){$c=(string)$student['class_code'];if(isset($seen[$c]))continue;$seen[$c]=true;?><option value="<?=e_admin_stats($c)?>"><?=e_admin_stats($c)?></option><?php }?></select></div><div class="stats-table-wrap"><table class="stats-table" id="student-level-table"><thead><tr><th>Élève</th><th>Classe</th><?php foreach($moduleStats as $module):?><th><?=e_admin_stats((string)($module['icon']?:'📘'))?> <?=e_admin_stats((string)$module['title'])?></th><?php endforeach;?></tr></thead><tbody><?php foreach($studentStats as $student):?><tr data-class="<?=e_admin_stats((string)$student['class_code'])?>" data-search="<?=e_admin_stats(strtolower((string)$student['login_code'].' '.(string)$student['class_code']))?>"><td><strong><?=e_admin_stats((string)$student['login_code'])?></strong></td><td><?=e_admin_stats((string)$student['class_code'])?></td><?php foreach($moduleStats as $module):$smod=$student['modules'][(int)$module['id']]??null;$level=(int)($smod['highest_level']??0);$def=$levelByOrder[$level]??null;?><td><?php if($def):?><span class="level-pill"><?=e_admin_stats((string)$def['icon'])?> <?=e_admin_stats((string)$def['name'])?></span><br><small><?= (int)($smod['badges']??0)?> / 4 badges</small><?php else:?><span class="level-pill level-none">— Aucun badge</span><?php endif;?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><p class="stats-note">Cette vue sert au suivi pédagogique de progression. Elle n'établit pas de classement entre élèves.</p></section>

<script>
(()=>{const search=document.getElementById('student-search'),classFilter=document.getElementById('class-filter'),rows=[...document.querySelectorAll('#student-level-table tbody tr')];const refresh=()=>{const q=(search.value||'').trim().toLowerCase(),c=classFilter.value;rows.forEach(r=>{const okQ=!q||(r.dataset.search||'').includes(q),okC=!c||r.dataset.class===c;r.style.display=okQ&&okC?'':'none';});};search.addEventListener('input',refresh);classFilter.addEventListener('change',refresh);})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
