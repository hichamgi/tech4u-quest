<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Core/Database.php';
require_once dirname(__DIR__,2).'/app/Core/Auth.php';
use App\Core\Auth; use App\Core\Database;
Auth::requireAdmin('../login.php'); $db=Database::connection();
header('Content-Type:text/csv; charset=UTF-8');header('Content-Disposition:attachment; filename="modules_categories.csv"');
$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['module_id','module','category_id','category','recommended_bank_size'],';','"','\\');
$rows=$db->query('SELECT m.id module_id,m.title module,c.id category_id,c.name category,c.recommended_bank_size FROM modules m JOIN categories c ON c.module_id=m.id ORDER BY m.display_order,m.id,c.display_order,c.id');while($r=$rows->fetch(PDO::FETCH_ASSOC))fputcsv($out,$r,';','"','\\');fclose($out);