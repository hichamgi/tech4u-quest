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
foreach ($activity as $row) {
    $maxDaily = max($maxDaily, (int)$row['attempts']);
}

$maxModuleAttempts = 1;
foreach ($moduleStats as $row) {
    $maxModuleAttempts = max($maxModuleAttempts, (int)$row['attempts']);
}

$maxPathAttempts = 1;
foreach ($pathStats as $row) {
    $maxPathAttempts = max($maxPathAttempts, (int)$row['attempts']);
}
?>
<style>
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}
.stat-card{padding:1.1rem}.stat-card span{display:block;color:#9eb1ca;font-size:.9rem}.stat-card strong{display:block;font-size:2rem;margin:.35rem 0}.stat-card small{color:#7890ad}
.chart-card{padding:1.25rem;margin-top:1rem}.chart-title{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1rem}.chart-title h2{margin:0}.chart-title p{margin:.35rem 0 0}
.daily-chart{display:grid;grid-template-columns:repeat(14,minmax(34px,1fr));gap:.45rem;align-items:end;height:230px;padding:1rem 0 .25rem;border-bottom:1px solid rgba(158,177,202,.22)}
.daily-col{height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:.4rem;min-width:0}.daily-value{font-size:.78rem;font-weight:800}.daily-bar-wrap{height:170px;width:72%;display:flex;align-items:flex-end}.daily-bar{width:100%;min-height:3px;border-radius:8px 8px 3px 3px;background:linear-gradient(180deg,#7c5cff,#16d9e3)}.daily-label{font-size:.72rem;color:#9eb1ca;white-space:nowrap}
.module-bars{display:grid;gap:1rem}.module-row{display:grid;grid-template-columns:minmax(210px,1.4fr) 3fr minmax(90px,.6fr);gap:1rem;align-items:center}.module-label strong{display:block}.module-label small{color:#9eb1ca}.bar-track{height:13px;border-radius:999px;background:rgba(158,177,202,.13);overflow:hidden}.bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#7c5cff,#16d9e3)}.module-number{text-align:right;font-weight:800}
.progress-stack{display:grid;gap:.45rem;margin-top:.55rem}.progress-line{display:flex;justify-content:space-between;gap:1rem;font-size:.82rem;color:#9eb1ca}
.path-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem}.path-stat{padding:1rem}.path-stat h3{margin:.25rem 0 .7rem}.path-stat .big{font-size:1.8rem;font-weight:900}.path-stat small{color:#9eb1ca}.path-stat .bar-track{margin:.75rem 0 .45rem}
.stats-table-wrap{overflow:auto}.stats-table{width:100%;border-collapse:collapse}.stats-table th,.stats-table td{padding:.8rem .7rem;border-bottom:1px solid rgba(158,177,202,.14);text-align:left}.stats-table th{color:#9eb1ca;font-size:.82rem}.stats-note{margin-top:1rem;color:#9eb1ca;font-size:.88rem}
@media(max-width:760px){.module-row{grid-template-columns:1fr}.module-number{text-align:left}.daily-chart{gap:.2rem;overflow-x:auto;grid-template-columns:repeat(14,42px)}.daily-label{font-size:.65rem}}
</style>

<div class="page-head">
    <div>
        <span class="eyebrow">📈 ANALYSE D'UTILISATION</span>
        <h1>Graphiques et statistiques</h1>
        <p>Vue globale de l'activité d'apprentissage. Les comptes de la classe DEMO sont exclus.</p>
    </div>
</div>

<section class="stats-grid">
    <div class="card stat-card"><span>Élèves</span><strong><?= $kpis['students'] ?></strong><small><?= $kpis['active_students'] ?> ont déjà joué</small></div>
    <div class="card stat-card"><span>Tentatives</span><strong><?= $kpis['attempts'] ?></strong><small><?= $kpis['completed'] ?> parcours terminés</small></div>
    <div class="card stat-card"><span>Réponses données</span><strong><?= $kpis['answers'] ?></strong><small><?= $kpis['success_rate'] ?>% correctes</small></div>
    <div class="card stat-card"><span>Badges débloqués</span><strong><?= $kpis['badges'] ?></strong><small>obtenus après Expert</small></div>
</section>

<section class="card chart-card">
    <div class="chart-title"><div><h2>Activité sur les 14 derniers jours</h2><p>Nombre de nouvelles tentatives démarrées chaque jour.</p></div></div>
    <div class="daily-chart" aria-label="Tentatives par jour">
        <?php foreach ($activity as $row): $height=max(2,(int)round(((int)$row['attempts']/$maxDaily)*100)); ?>
            <div class="daily-col" title="<?= e_admin_stats((string)$row['label']) ?> : <?= (int)$row['attempts'] ?> tentative(s)">
                <span class="daily-value"><?= (int)$row['attempts'] ?></span>
                <div class="daily-bar-wrap"><div class="daily-bar" style="height:<?= $height ?>%"></div></div>
                <span class="daily-label"><?= e_admin_stats((string)$row['label']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card chart-card">
    <div class="chart-title"><div><h2>Activité par module</h2><p>Tentatives et progression moyenne, sans classement entre élèves.</p></div></div>
    <div class="module-bars">
        <?php foreach ($moduleStats as $module): $attempts=(int)$module['attempts'];$width=$attempts>0?max(3,(int)round(($attempts/$maxModuleAttempts)*100)):0; ?>
            <div class="module-row">
                <div class="module-label"><strong><?= e_admin_stats((string)($module['icon'] ?: '📘')) ?> <?= e_admin_stats((string)$module['title']) ?></strong><small><?= (int)$module['completed'] ?> terminée(s) · <?= (int)$module['game_over'] ?> game over</small></div>
                <div><div class="bar-track"><div class="bar-fill" style="width:<?= $width ?>%"></div></div><div class="progress-stack"><div class="progress-line"><span>Progression moyenne</span><strong><?= (int)$module['avg_progress'] ?>%</strong></div></div></div>
                <div class="module-number"><?= $attempts ?> tentative<?= $attempts > 1 ? 's' : '' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card chart-card">
    <div class="chart-title"><div><h2>Progression par parcours</h2><p>Découverte → Entraînement → Maîtrise → Expert. Les données sont cumulées sur tous les modules.</p></div></div>
    <div class="path-grid">
        <?php foreach ($pathStats as $path):
            $attempts=(int)$path['attempts'];
            $completed=(int)$path['completed'];
            $rate=$attempts>0?(int)round(($completed/$attempts)*100):0;
            $width=$attempts>0?max(3,(int)round(($attempts/$maxPathAttempts)*100)):0;
        ?>
        <article class="card path-stat">
            <div><?= e_admin_stats((string)($path['icon'] ?: '🎯')) ?> <span class="badge"><?= (int)$path['pool_percent'] ?>%</span></div>
            <h3><?= e_admin_stats((string)$path['name']) ?></h3>
            <div class="big"><?= $attempts ?></div><small>tentative<?= $attempts>1?'s':'' ?></small>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $width ?>%"></div></div>
            <div class="progress-line"><span>Parcours terminés</span><strong><?= $completed ?></strong></div>
            <div class="progress-line"><span>Taux de complétion</span><strong><?= $rate ?>%</strong></div>
            <div class="progress-line"><span>Élèves ayant terminé</span><strong><?= (int)$path['students_completed'] ?></strong></div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card chart-card">
    <div class="chart-title"><div><h2>Activité par classe</h2><p>Indicateurs collectifs uniquement : aucun classement individuel.</p></div></div>
    <div class="stats-table-wrap">
        <table class="stats-table">
            <thead><tr><th>Classe</th><th>Élèves</th><th>Ont joué</th><th>Tentatives</th><th>Parcours terminés</th><th>Badges</th></tr></thead>
            <tbody>
            <?php if (!$classStats): ?><tr><td colspan="6">Aucune activité élève pour le moment.</td></tr><?php endif; ?>
            <?php foreach ($classStats as $row): ?>
                <tr><td><strong><?= e_admin_stats((string)$row['class_code']) ?></strong></td><td><?= (int)$row['students'] ?></td><td><?= (int)$row['active_students'] ?></td><td><?= (int)$row['attempts'] ?></td><td><?= (int)$row['completed'] ?></td><td><?= (int)$row['badges'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="stats-note">Ces statistiques décrivent l'utilisation de la plateforme d'apprentissage ; elles ne constituent pas des notes ni une évaluation des élèves.</p>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
