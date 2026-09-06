<?php
declare(strict_types=1);
namespace App\Controllers\Admin;
use App\Core\Auth;use App\Core\Url;use App\Core\View;use App\Services\ArchiveService;use Throwable;
final class ArchiveController{public function index():void{Auth::requireAdmin(Url::to('login'));$service=new ArchiveService();$message=$error=null;if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){if(!Auth::validateCsrf($_POST['csrf_token']??null))$error='Jeton de sécurité invalide.';elseif(($_POST['action']??'')==='archive')try{$r=$service->archiveAndResetStudents();$message='Archivage terminé : '.$r['archive'].'. La nouvelle base current.sqlite conserve les données communes, sans recopier les élèves ni leurs données.';}catch(Throwable $e){$error=$e->getMessage();}}$archives=$service->listArchives();View::render('admin/archive',compact('archives','message','error'));}}
