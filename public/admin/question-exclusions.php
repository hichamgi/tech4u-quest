<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Core/Database.php';
require_once dirname(__DIR__,2).'/app/Core/Auth.php';
use App\Core\Auth; use App\Core\Database;
Auth::requireAdmin('../login.php'); $db=Database::connection();
function e(string $v): string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
$message=null;$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!Auth::validateCsrf($_POST['csrf_token']??null)){$error='Jeton de sécurité invalide.';}
 else try{
  $groups=$_POST['group']??[]; if(!is_array($groups))throw new RuntimeException('Données invalides.');
  $stmt=$db->prepare('UPDATE questions SET exclusion_group=:g,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
  $db->beginTransaction();
  foreach($groups as $id=>$group){$id=(int)$id;if($id<1)continue;$group=trim((string)$group);if($group!==''&&!preg_match('/^[A-Za-z0-9_.-]{1,50}$/',$group))throw new RuntimeException('Nom de groupe invalide pour la question #'.$id.'.');$stmt->execute(['g'=>$group!==''?$group:null,'id'=>$id]);}
  $db->commit();$message='Groupes d’exclusion enregistrés.';
 }catch(Throwable $x){if($db->inTransaction())$db->rollBack();$error=$x->getMessage();}
}
$module=(int)($_GET['module']??0);$category=(int)($_GET['category']??0);
$sql='SELECT q.id,q.question,q.exclusion_group,c.id category_id,c.name category_name,m.id module_id,m.title module_title FROM questions q JOIN categories c ON c.id=q.category_id JOIN modules m ON m.id=c.module_id WHERE q.active=1';$p=[];
if($module>0){$sql.=' AND m.id=:m';$p['m']=$module;} if($category>0){$sql.=' AND c.id=:c';$p['c']=$category;}$sql.=' ORDER BY m.display_order,c.display_order,q.id';$s=$db->prepare($sql);$s->execute($p);$questions=$s->fetchAll(PDO::FETCH_ASSOC);
$modules=$db->query('SELECT id,title FROM modules WHERE active=1 ORDER BY display_order,id')->fetchAll(PDO::FETCH_ASSOC);
$categories=$db->query('SELECT c.id,c.name,m.title module_title FROM categories c JOIN modules m ON m.id=c.module_id WHERE c.active=1 AND m.active=1 ORDER BY m.display_order,c.display_order,c.id')->fetchAll(PDO::FETCH_ASSOC);
$activePage='questions.php';
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Exclusions de questions — Tech4U-QUEST</title><link rel="icon" type="image/png" href="../assets/images/icon.png"><link rel="stylesheet" href="../assets/css/app.css"></head><body><div class="admin-shell"><?php require __DIR__.'/_sidebar.php';?><main class="admin-main"><div class="page-head"><div><span class="eyebrow">🔀 EXCLUSIONS</span><h1>Questions incompatibles</h1><p>Donne le même groupe aux questions qui ne doivent jamais apparaître ensemble dans une même tentative. Exemple : <b>ascii-cat</b>. Une seule question de ce groupe sera tirée.</p></div><a class="btn btn-secondary" href="questions.php">Retour aux questions</a></div>
<?php if($message):?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#fb7185"><?=e($error)?></div><?php endif;?>
<form method="get" class="card" style="padding:1rem;margin-bottom:1rem"><div class="grid" style="grid-template-columns:1fr 1fr auto;gap:.75rem"><select class="input" name="module"><option value="0">Tous les modules</option><?php foreach($modules as $m):?><option value="<?=$m['id']?>" <?=$module===(int)$m['id']?'selected':''?>><?=e($m['title'])?></option><?php endforeach;?></select><select class="input" name="category"><option value="0">Toutes les catégories</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>" <?=$category===(int)$c['id']?'selected':''?>><?=e($c['module_title'].' — '.$c['name'])?></option><?php endforeach;?></select><button class="btn btn-secondary">Filtrer</button></div></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(Auth::csrfToken())?>"><div class="card" style="overflow:auto"><table class="table"><thead><tr><th>ID</th><th>Module / catégorie</th><th>Question</th><th>Groupe d’exclusion</th></tr></thead><tbody><?php foreach($questions as $q):?><tr><td>#<?=$q['id']?></td><td><small><?=e($q['module_title'])?><br><?=e($q['category_name'])?></small></td><td style="min-width:360px;white-space:pre-wrap"><?=e($q['question'])?></td><td><input class="input" style="min-width:180px" name="group[<?=$q['id']?>]" value="<?=e((string)($q['exclusion_group']??''))?>" placeholder="ex. ascii-cat"></td></tr><?php endforeach;?></tbody></table></div><div style="margin-top:1rem"><button class="btn btn-primary" type="submit">Enregistrer les groupes</button></div></form></main></div></body></html>