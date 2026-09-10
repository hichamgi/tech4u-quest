<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Services\StudentCsvImportService;
use PDO;
use RuntimeException;
use Throwable;

final class StudentController
{
    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        $message = $error = null;
        $importResult = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide. Recharge la page et recommence.';
            } elseif (($_POST['action'] ?? '') === 'reset_demo') {
                try {
                    $demoId = 2;
                    $db->beginTransaction();
                    $s = $db->prepare('SELECT id FROM students WHERE id=:id AND class_code=:class AND student_number=:number LIMIT 1');
                    $s->execute(['id'=>$demoId,'class'=>'DEMO','number'=>1]);
                    if (!$s->fetchColumn()) {
                        throw new RuntimeException('Le compte DEMO-1 (ID 2) n’existe pas. Importe-le d’abord avec le CSV élèves.');
                    }

                    // Les badges de parcours référencent les tentatives : ils doivent être supprimés avant les tentatives.
                    $db->prepare('DELETE FROM student_path_badges WHERE student_id=:id')->execute(['id'=>$demoId]);
                    // Ancien système conservé uniquement pour compatibilité avec les bases historiques.
                    $db->prepare('DELETE FROM student_badges WHERE student_id=:id')->execute(['id'=>$demoId]);
                    $db->prepare('DELETE FROM attempts WHERE student_id=:id')->execute(['id'=>$demoId]);
                    $db->commit();

                    $message = 'Compte DEMO-1 réinitialisé : progression, tentatives, réponses, scores et badges effacés. Le compte et son mot de passe sont inchangés.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    if ($e instanceof RuntimeException) {
                        $error = $e->getMessage();
                    } else {
                        Logger::exception($e, ['controller'=>'Admin\\StudentController','action'=>'reset_demo']);
                        $error = 'Impossible de réinitialiser le compte DEMO pour le moment.';
                    }
                }
            } elseif (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
                $error = 'Aucun fichier CSV reçu.';
            } elseif ((int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Erreur pendant l’envoi du fichier CSV.';
            } else {
                try {
                    $service = new StudentCsvImportService($db);
                    $importResult = $service->import((string)$_FILES['csv']['tmp_name'], (int)$_FILES['csv']['size']);
                    $message = sprintf('Import terminé : %d ajouté(s), %d mis à jour, %d inchangé(s).', $importResult['created'], $importResult['updated'], $importResult['unchanged']);
                } catch (Throwable $e) {
                    if ($e instanceof RuntimeException) {
                        $error = $e->getMessage();
                    } else {
                        Logger::exception($e, ['controller'=>'Admin\\StudentController','action'=>'import']);
                        $error = 'Impossible d’importer les élèves pour le moment.';
                    }
                }
            }
        }

        $filterClass = strtoupper(trim((string)($_GET['class'] ?? '')));
        $params = [];
        $sql = 'SELECT id,class_code,student_number,login_code,must_change_password,active,created_at,updated_at FROM students';
        if ($filterClass !== '') {
            $sql .= ' WHERE class_code=:class_code';
            $params['class_code'] = $filterClass;
        }
        $sql .= ' ORDER BY class_code,student_number,id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $classes = $db->query('SELECT class_code,COUNT(*) total,SUM(active) active_total FROM students GROUP BY class_code ORDER BY class_code')->fetchAll(PDO::FETCH_ASSOC);
        $totals = $db->query('SELECT COUNT(*) total,COALESCE(SUM(active),0) active,COALESCE(SUM(must_change_password),0) must_change FROM students')->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active'=>0,'must_change'=>0];

        $demo = $db->prepare(
            'SELECT s.id,s.login_code,s.active,
                    (SELECT COUNT(*) FROM attempts a WHERE a.student_id=s.id) attempts,
                    (SELECT COUNT(*) FROM student_path_badges spb WHERE spb.student_id=s.id) badges
             FROM students s
             WHERE s.id=:id AND s.class_code=:class AND s.student_number=:number
             LIMIT 1'
        );
        $demo->execute(['id'=>2,'class'=>'DEMO','number'=>1]);
        $demoStudent = $demo->fetch(PDO::FETCH_ASSOC) ?: null;

        View::render('admin/students', compact('message','error','importResult','filterClass','students','classes','totals','demoStudent'));
    }

    public function template(): void
    {
        Auth::requireAdmin(Url::to('login'));
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tech4u-eleves-template.csv"');
        echo "\xEF\xBB\xBF";
        echo "id;class_code;student_number;password;active;must_change_password\n";
        echo "157;TCT1;12;MotDePasseTemporaire;1;1\n158;TCT1;13;MotDePasseTemporaire;1;1\n";
    }
}
