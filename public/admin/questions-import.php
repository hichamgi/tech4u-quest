<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Core/Database.php';
require_once dirname(__DIR__,2).'/app/Core/Auth.php';
require_once dirname(__DIR__,2).'/app/Services/QuestionBankService.php';
use App\Core\Auth; use App\Core\Database; use App\Services\QuestionBankService;
Auth::requireAdmin('../login.php'); $db=Database::connection(); $svc=new QuestionBankService($db); Auth::boot();
function e(string $v): string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
$errors=[];$preview=$_SESSION['question_import_preview']??null;$message=null;
if(isset($_GET['template'])){
 header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="questions-template.csv"');echo "\xEF\xBB\xBF";echo "category_id;question;type;difficulty;lesson;topic;explanation;answer_1;correct_1;answer_2;correct_2;answer_3;correct_3;answer_4;correct_4;answer_5;correct_5;answer_6;correct_6;active\n";echo "1;Qu est-ce que l informatique ?;qcm;1;Leçon 1;Vocabulaire;Explication;Traitement automatique de l information;1;Réseaux uniquement;0;Matériel uniquement;0;Images uniquement;0;;;;;1\n";exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!Auth::validateCsrf($_POST['csrf_token']??null))$errors[]='Jeton de sécurité invalide.';
 elseif(($_POST['action']??'')==='commit' && is_array($preview)){
  $ok=0;$failed=[]; foreach($preview['valid'] as $i=>$data){try{$svc->save($data);$ok++;}catch(Throwable $x){$failed[]='Ligne '.($i+2).' : '.$x->getMessage();}}
  unset($_SESSION['question_import_preview']);$preview=null;$message=$ok.' question(s) importée(s).';$errors=$failed;
 } else {
  if(!isset($_FILES['csv'])||$_FILES['csv']['error']!==UPLOAD_ERR_OK)$errors[]='Fichier CSV invalide.';
  elseif($_FILES['csv']['size']>3_000_000)$errors[]='Le fichier dépasse 3 Mo.';
  else {
   $h=fopen($_FILES['csv']['tmp_name'],'rb'); if(!$h)$errors[]='Impossible d’ouvrir le CSV.'; else {
    $first=fgets($h);$delim=(substr_count((string)$first,';')>=substr_count((string)$first,','))?';':',';rewind($h);
    $header=fgetcsv($h,0,$delim,'"','\\');$header=array_map(fn($v)=>strtolower(trim((string)$v)),(array)$header);
    $required=['category_id','question','type','difficulty'];foreach($required as $r)if(!in_array($r,$header,true))$errors[]='Colonne obligatoire absente : '.$r;
    $valid=[];$rowErrors=[];$line=1;
    if(!$errors) while(($row=fgetcsv($h,0,$delim,'"','\\'))!==false){$line++;if(count(array_filter($row,fn($x)=>trim((string)$x)!==''))===0)continue;$row=array_pad($row,count($header),'');$d=array_combine($header,array_slice($row,0,count($header)));if(!$d){$rowErrors[]="Ligne $line : structure invalide.";continue;}
      $cid=(int)($d['category_id']??0);$exists=$db->prepare('SELECT 1 FROM categories WHERE id=:id AND active=1');$exists->execute(['id'=>$cid]);if(!$exists->fetchColumn()){$rowErrors[]="Ligne $line : catégorie $cid inexistante.";continue;}
      $type=(string)($d['type']??'qcm');$difficulty=(int)($d['difficulty']??0);$question=trim((string)($d['question']??''));if($question===''||!in_array($type,['qcm','true_false','multiple','short'],true)||$difficulty<1||$difficulty>5){$rowErrors[]="Ligne $line : question/type/difficulté invalide.";continue;}
      $answers=[];for($i=1;$i<=6;$i++){$txt=trim((string)($d['answer_'.$i]??''));if($txt!=='')$answers[]=['answer'=>$txt,'is_correct'=>(int)($d['correct_'.$i]??0)===1?1:0];}
      $correct=array_sum(array_column($answers,'is_correct'));$bad=($type==='qcm'&&(count($answers)<2||$correct!==1))||($type==='multiple'&&(count($answers)<2||$correct<1))||($type==='true_false'&&(count($answers)!==2||$correct!==1))||($type==='short'&&(count($answers)<1||$correct<1));if($bad){$rowErrors[]="Ligne $line : réponses incohérentes pour le type $type.";continue;}
      $du=$db->prepare('SELECT id FROM questions WHERE lower(trim(question))=lower(trim(:q)) LIMIT 1');$du->execute(['q'=>$question]);if($du->fetchColumn()){$rowErrors[]="Ligne $line : question déjà existante.";continue;}
      $valid[]=['category_id'=>$cid,'question'=>$question,'type'=>$type,'difficulty'=>$difficulty,'lesson'=>$d['lesson']??'','topic'=>$d['topic']??'','explanation'=>$d['explanation']??'','active'=>((string)($d['active']??'1')!=='0')?1:0,'answers'=>$answers];
    }
    fclose($h);$preview=['valid'=>$valid,'errors'=>$rowErrors];$_SESSION['question_import_preview']=$preview;
   }
  }
 }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Import questions CSV</title><link rel="stylesheet" href="../assets/css/app.css"></head><body><main class="admin-main" style="max-width:1100px;margin:auto"><div class="page-head"><div><span class="eyebrow">📥 IMPORT CSV</span><h1>Importer des questions</h1><p>Analyse et prévisualisation avant insertion.</p></div><div style="display:flex;gap:.6rem"><a class="btn btn-secondary" href="questions.php">Retour</a><a class="btn btn-secondary" href="?template=1">Télécharger le modèle</a></div></div><?php if($message):?><div class="card" style="padding:1rem;margin-bottom:1rem;border-color:#2dd4bf"><?=e($message)?></div><?php endif;?><?php foreach($errors as $er):?><div class="card" style="padding:.8rem;margin-bottom:.5rem;border-color:#fb7185"><?=e($er)?></div><?php endforeach;?><section class="card" style="padding:1.25rem"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e(Auth::csrfToken())?>"><input class="input" type="file" name="csv" accept=".csv,text/csv" required><button class="btn btn-primary" style="margin-top:1rem">Analyser le CSV</button></form></section><?php if(is_array($preview)):?><section class="card" style="padding:1.25rem;margin-top:1rem"><h2>Prévisualisation</h2><p>✅ <?=count($preview['valid'])?> valides · ❌ <?=count($preview['errors'])?> erreur(s)</p><?php if($preview['errors']):?><div style="max-height:220px;overflow:auto"><?php foreach(array_slice($preview['errors'],0,100) as $er):?><p>❌ <?=e($er)?></p><?php endforeach;?></div><?php endif;?><?php if($preview['valid']):?><div style="overflow:auto"><table class="table"><thead><tr><th>Catégorie</th><th>Question</th><th>Type</th><th>Difficulté</th><th>Réponses</th></tr></thead><tbody><?php foreach(array_slice($preview['valid'],0,50) as $v):?><tr><td><?=$v['category_id']?></td><td><?=e($v['question'])?></td><td><?=$v['type']?></td><td><?=$v['difficulty']?></td><td><?=count($v['answers'])?></td></tr><?php endforeach;?></tbody></table></div><form method="post"><input type="hidden" name="csrf_token" value="<?=e(Auth::csrfToken())?>"><button class="btn btn-primary" name="action" value="commit">Importer les <?=count($preview['valid'])?> questions valides</button></form><?php endif;?></section><?php endif;?></main></body></html>