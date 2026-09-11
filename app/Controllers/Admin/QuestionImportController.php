<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Services\QuestionImportService;
use RuntimeException;
use Throwable;

final class QuestionImportController
{
    public function __construct(private QuestionImportService $importer)
    {
    }

    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        Auth::boot();

        $errors = [];
        $preview = $_SESSION['question_import_preview'] ?? null;
        $message = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Jeton de sécurité invalide.';
            } elseif (($_POST['action'] ?? '') === 'commit' && is_array($preview)) {
                $validRows = is_array($preview['valid'] ?? null) ? $preview['valid'] : [];
                try {
                    $imported = $this->importer->commit($validRows);
                    unset($_SESSION['question_import_preview']);
                    $preview = null;
                    $message = $imported . ' question(s) importée(s). Import atomique terminé avec succès.';
                } catch (Throwable $e) {
                    if ($e instanceof RuntimeException) {
                        $errors[] = $e->getMessage() . ' Import annulé : aucune question du lot n’a été enregistrée.';
                    } else {
                        Logger::exception($e, ['controller' => self::class, 'action' => 'commit']);
                        $errors[] = 'Erreur technique pendant l’import. Import annulé : aucune question du lot n’a été enregistrée.';
                    }
                }
            } else {
                $file = $_FILES['csv'] ?? null;
                if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $errors[] = 'Fichier CSV invalide.';
                } else {
                    try {
                        $preview = $this->importer->analyze(
                            (string)$file['tmp_name'],
                            (int)($file['size'] ?? 0)
                        );
                        $_SESSION['question_import_preview'] = $preview;
                    } catch (Throwable $e) {
                        $errors[] = $this->safeError($e, 'analyze');
                    }
                }
            }
        }

        View::render('admin/questions/import', compact('errors', 'preview', 'message'));
    }

    private function safeError(Throwable $e, string $action): string
    {
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }
        Logger::exception($e, ['controller' => self::class, 'action' => $action]);
        return 'Une erreur technique est survenue. Consulte le journal de l’application.';
    }
}
