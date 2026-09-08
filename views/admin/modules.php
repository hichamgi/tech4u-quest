<?php
declare(strict_types=1);
use App\Core\Auth;
function e_admin_modules(string $v): string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
$title='Modules — Administration Tech4U-QUEST';$activePage='modules';require __DIR__.'/_header.php';
?>
<div class="page-head"><div><span class="eyebrow">🧭 CONFIGURATION PÉDAGOGIQUE</span><h1>Modules et réglages</h1><p>Définis la répartition pédagogique par catégorie. Le niveau Expert utilise le quota complet ; Facile, Moyen et Difficile utilisent automatiquement 25 %, 50 % et 75 % de ce quota. Toutes les tentatives utilisent 3 vies.</p></div></div>
<?php if($message):?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf"><strong><?=e_admin_modules($message)?></strong></div><?php endif;?>
<?php if($error):?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#fb7185"><strong><?=e_admin_modules($error)?></strong></div><?php endif;?>
<?php foreach($modules as $module):
$categories=$module['categories'];
$paths=$module['paths']??[];
$target=max(1,(int)$module['recommended_bank_size']);
$percent=min(100,(int)round(((int)$module['active_questions']/$target)*100));
$quotaTotal=array_sum(array_map(static fn(array $c):int=>(int)$c['draw_count'],$categories));
$isActive=(int)$module['active']===1;
?>
<section class="card" style="padding:1.25rem;margin-bottom:1rem;<?= $isActive?'':'opacity:.78;' ?>">
<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start"><div><span class="eyebrow"><?=e_admin_modules((string)($module['icon']?:'📘'))?> MODULE <?= (int)$module['id']?></span><h2 style="margin:.35rem 0"><?=e_admin_modules((string)$module['title'])?></h2><p><?=e_admin_modules((string)($module['description']??''))?></p></div><div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap"><span class="chip"><?= $isActive?'🟢 Visible aux élèves':'🔴 Masqué aux élèves' ?></span><span class="chip">❤️ 3 vies</span><span class="chip"><?= (int)$module['active_questions']?> / <?= (int)$module['recommended_bank_size']?> questions</span></div></div>
<div class="progress" style="margin:.8rem 0 1.25rem"><span style="width:<?=$percent?>%"></span></div>
<form method="post" class="module-config-form"><input type="hidden" name="csrf_token" value="<?=e_admin_modules(Auth::csrfToken())?>"><input type="hidden" name="module_id" value="<?= (int)$module['id']?>">
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem"><div class="form-group"><label class="label">Disponibilité élèves</label><label><input type="checkbox" name="module_active" value="1" <?=$isActive?'checked':''?>> <strong>Module actif</strong></label></div><div class="form-group"><label class="label">Quota pédagogique total</label><input class="input question-total" type="number" value="<?=$quotaTotal?>" readonly><small style="color:var(--muted)">Correspond au nombre de questions du mode Expert.</small></div><div class="form-group"><label class="label">Badges de niveaux</label><label><input type="checkbox" name="badge_enabled" value="1" <?= (int)$module['badge_enabled']===1?'checked':''?>> Activer l'attribution des badges</label></div></div>

<h3 style="margin:1.4rem 0 .75rem">Niveaux du module</h3>
<p style="color:var(--muted);margin-top:0">Les pourcentages représentent maintenant une part du <strong>quota pédagogique total</strong>, et non une part de la banque de questions. Le nombre de questions est calculé automatiquement.</p>
<div style="overflow:auto"><table class="table"><thead><tr><th>Niveau</th><th>Part du quota</th><th>Questions / tentative</th><th>Badge</th><th>Élèves avec badge</th><th>Actif</th></tr></thead><tbody>
<?php foreach($paths as $path):
$levelPercent=(int)$path['pool_percent'];
$computedQuestions=max(1,(int)ceil($quotaTotal*($levelPercent/100)));
?>
<tr>
<td><strong><?=e_admin_modules((string)($path['icon']?:'🎯'))?> <?=e_admin_modules((string)$path['name'])?></strong><br><small><?=e_admin_modules((string)($path['description']??''))?></small></td>
<td><strong><?= $levelPercent ?> %</strong></td>
<td><strong class="level-question-count" data-percent="<?= $levelPercent ?>"><?= $computedQuestions ?></strong></td>
<td><?=e_admin_modules((string)($path['badge_icon']?:$path['icon']?:'🏅'))?> <?=e_admin_modules((string)($path['badge_name']??('Badge '.$path['name'])))?></td>
<td><?= (int)($path['badge_holders']??0)?></td>
<td><label><input type="checkbox" name="path_active[<?= (int)$path['id']?>]" value="1" <?= (int)$path['active']===1?'checked':''?> <?= (int)$path['display_order']===1?'disabled':''?>> <?= (int)$path['display_order']===1?'Toujours actif':'Actif' ?></label><?php if((int)$path['display_order']===1):?><input type="hidden" name="path_active[<?= (int)$path['id']?>]" value="1"><?php endif;?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>

<h3 style="margin:1.4rem 0 .3rem">Répartition par catégorie</h3>
<p style="color:var(--muted);margin-top:0">Ces quotas définissent exactement le mode Expert. Pour les autres niveaux, la même répartition est réduite proportionnellement à 25 %, 50 % et 75 %.</p>
<div style="overflow:auto"><table class="table"><thead><tr><th>Catégorie</th><th>Questions actives</th><th>Cible banque</th><th>Quota Expert</th><th>État</th></tr></thead><tbody><?php foreach($categories as $category):$enough=(int)$category['active_questions']>=(int)$category['draw_count'];?><tr><td><strong><?=e_admin_modules((string)$category['name'])?></strong><br><small><?=e_admin_modules((string)($category['description']??''))?></small></td><td><?= (int)$category['active_questions']?></td><td><?= (int)$category['recommended_bank_size']?></td><td><input class="input category-quota" style="max-width:100px" type="number" min="0" max="100" name="category_count[<?= (int)$category['id']?>]" value="<?= (int)$category['draw_count']?>" required></td><td><span class="badge"><?=$enough?'OK':'À compléter'?></span></td></tr><?php endforeach;?></tbody></table></div>
<div style="margin-top:1rem"><button class="btn btn-primary" type="submit">Enregistrer la configuration</button></div></form></section><?php endforeach;?>
<script>
document.querySelectorAll('.module-config-form').forEach(form=>{
    const total=form.querySelector('.question-total');
    const quotas=form.querySelectorAll('.category-quota');
    const levels=form.querySelectorAll('.level-question-count');
    const refresh=()=>{
        let sum=0;
        quotas.forEach(input=>{
            const value=parseInt(input.value,10);
            if(!Number.isNaN(value)&&value>0) sum+=value;
        });
        total.value=sum;
        levels.forEach(level=>{
            const percent=parseInt(level.dataset.percent,10)||100;
            level.textContent=Math.max(1,Math.ceil(sum*(percent/100)));
        });
    };
    quotas.forEach(input=>input.addEventListener('input',refresh));
    refresh();
});
</script>
<?php require __DIR__.'/_footer.php';?>
