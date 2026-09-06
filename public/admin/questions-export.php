<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Core/Database.php';
require_once dirname(__DIR__,2).'/app/Core/Auth.php';
use App\Core\Auth; use App\Core\Database;
Auth::requireAdmin('../login.php'); $db=Database::connection();
header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="tech4u-questions.csv"');
$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
$header=['id','module_id','category_id','question','type','difficulty','lesson','topic','explanation','active'];for($i=1;$i<=6;$i++){ $header[]='answer_'.$i;$header[]='correct_'.$i;}fputcsv($out,$header,';','"','\\');
$qs=$db->query('SELECT q.*,c.module_id FROM questions q JOIN categories c ON c.id=q.category_id ORDER BY q.id');$ans=$db->prepare('SELECT answer,is_correct FROM question_answers WHERE question_id=:id ORDER BY display_order,id');
while($q=$qs->fetch(PDO::FETCH_ASSOC)){$row=[$q['id'],$q['module_id'],$q['category_id'],$q['question'],$q['type'],$q['difficulty'],$q['lesson'],$q['topic'],$q['explanation'],$q['active']];$ans->execute(['id'=>$q['id']]);$a=$ans->fetchAll(PDO::FETCH_ASSOC);for($i=0;$i<6;$i++){$row[]=$a[$i]['answer']??'';$row[]=$a[$i]['is_correct']??'';}fputcsv($out,$row,';','"','\\');}
fclose($out);