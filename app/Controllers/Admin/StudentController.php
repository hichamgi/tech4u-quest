<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\Student;
use App\Services\StudentCsvImportService;
use RuntimeException;
use Throwable;

final class StudentController
{
    public function __construct(
        private Student $studentModel,
        private StudentCsvImportService $importService
    ) {
    }

    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $message = $error = null;
        $importResult = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide. Recharge la page et recommence.';
            } elseif (($_POST['action'] ?? '') === 'reset_demo') {
                try {
                    $this->studentModel->resetDemoProgress(2);
                    $message = 'Compte DEMO-1 réinitialisé : progression, tentatives, réponses, scores et badges effacés. Le compte et son mot de passe sont inchangés.';
                } catch (Throwable $e) {
                    if ($e instanceof RuntimeException) {
                        $error = $e->getMessage();
                    } else {
                        Logger::exception($e, ['controller'=>self::class,'action'=>'reset_demo']);
                        $error = 'Impossible de réinitialiser le compte DEMO pour le moment.';
                    }
                }
            } elseif (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
                $error = 'Aucun fichier CSV reçu.';
            } elseif ((int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Erreur pendant l’envoi du fichier CSV.';
            } else {
                try {
                    $importResult = $this->importService->import(
                        (string)$_FILES['csv']['tmp_name'],
                        (int)$_FILES['csv']['size']
                    );

                    if (!empty($importResult['errors'])) {
                        $error = 'Import annulé : aucune modification n’a été enregistrée. Corrige les lignes signalées puis réimporte le fichier.';
                    } else {
                        $message = sprintf(
                            'Import atomique terminé : %d ajouté(s), %d mis à jour, %d inchangé(s).',
                            $importResult['created'],
                            $importResult['updated'],
                            $importResult['unchanged']
                        );
                    }
                } catch (Throwable $e) {
                    if ($e instanceof RuntimeException) {
                        $error = $e->getMessage();
                    } else {
                        Logger::exception($e, ['controller'=>self::class,'action'=>'import']);
                        $error = 'Impossible d’importer les élèves pour le moment.';
                    }
                }
            }
        }

        $filterClass = strtoupper(trim((string)($_GET['class'] ?? '')));

        try {
            $students = $this->studentModel->adminList($filterClass);
            $classes = $this->studentModel->classSummaries();
            $totals = $this->studentModel->adminTotals();
            $demoStudent = $this->studentModel->demoSummary(2);
        } catch (Throwable $e) {
            Logger::exception($e, ['controller'=>self::class,'action'=>'load']);
            $students = [];
            $classes = [];
            $totals = ['total'=>0,'active'=>0,'must_change'=>0];
            $demoStudent = null;
            $error ??= 'Impossible de charger la liste des élèves pour le moment.';
        }

        View::render('admin/students', compact(
            'message',
            'error',
            'importResult',
            'filterClass',
            'students',
            'classes',
            'totals',
            'demoStudent'
        ));
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
