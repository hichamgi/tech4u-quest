<?php
declare(strict_types=1);
use App\Core\Auth;
function e_admin_settings(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
$title='Paramètres — Tech4U-QUEST';$activePage='settings';require __DIR__.'/_header.php';
?>
<div class="page-head"><div><span class="eyebrow">⚙️ PARAMÈTRES GÉNÉRAUX</span><h1>Configuration du site</h1><p>Paramètres globaux de l’année en cours.</p></div></div>
<?php if($message):?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf"><strong><?=e_admin_settings($message)?></strong></div><?php endif;?><?php if($error):?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#fb7185"><strong><?=e_admin_settings($error)?></strong></div><?php endif;?>
<section class="card" style="padding:1.25rem;max-width:760px"><form method="post"><input type="hidden" name="csrf_token" value="<?=e_admin_settings(Auth::csrfToken())?>"><div class="form-group"><label class="label" for="site_name">Nom du site</label><input class="input" id="site_name" name="site_name" maxlength="80" value="<?=e_admin_settings((string)($settings['site_name']??'Tech4U-QUEST'))?>" required></div><div class="form-group"><label class="label" for="school_year">Année scolaire</label><input class="input" id="school_year" name="school_year" pattern="20[0-9]{2}-20[0-9]{2}" placeholder="2026-2027" value="<?=e_admin_settings((string)($settings['school_year']??'2026-2027'))?>" required><small>Format : 2026-2027</small></div><button class="btn btn-primary">Enregistrer</button></form></section>
<?php require __DIR__.'/_footer.php';?>
