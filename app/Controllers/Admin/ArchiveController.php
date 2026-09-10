<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Services\ArchiveService;
use RuntimeException;
use Throwable;

final class ArchiveController
{
    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $service = new ArchiveService();
        $message = $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } elseif (($_POST['action'] ?? '') === 'archive') {
                try {
                    $result = $service->archiveAndResetStudents();
                    $message = 'Archivage terminé : ' . $result['archive'] . '. La nouvelle base current.sqlite conserve les données communes, sans recopier les élèves ni leurs données.';
                } catch (Throwable $e) {
                    if ($e instanceof RuntimeException) {
                        $error = $e->getMessage();
                    } else {
                        Logger::exception($e, ['action' => 'archive_and_reset']);
                        $error = 'L’archivage a échoué. Consulte les journaux pour le détail technique.';
                    }
                }
            }
        }

        try {
            $archives = $service->listArchives();
        } catch (Throwable $e) {
            Logger::exception($e, ['action' => 'list_archives']);
            $archives = [];
            $error ??= 'Impossible de charger la liste des archives.';
        }

        View::render('admin/archive', compact('archives', 'message', 'error'));
    }
}
