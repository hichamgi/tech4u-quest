<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use PDO;
use Throwable;

final class SettingsController
{
    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        $message = $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                $siteName = trim((string)($_POST['site_name'] ?? ''));
                $schoolYear = trim((string)($_POST['school_year'] ?? ''));

                if ($siteName === '' || mb_strlen($siteName) > 80) {
                    $error = 'Nom du site invalide.';
                } elseif (!preg_match('/^20\d{2}-20\d{2}$/', $schoolYear)) {
                    $error = 'L’année scolaire doit être au format 2026-2027.';
                } else {
                    try {
                        $db->beginTransaction();
                        $stmt = $db->prepare(
                            'INSERT INTO settings(key,value) VALUES(:key,:value)
                             ON CONFLICT(key) DO UPDATE SET value=excluded.value'
                        );
                        $stmt->execute(['key'=>'site_name','value'=>$siteName]);
                        $stmt->execute(['key'=>'school_year','value'=>$schoolYear]);
                        $db->commit();
                        $message = 'Paramètres enregistrés.';
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        Logger::exception($e, ['action' => 'save_settings']);
                        $error = 'Impossible d’enregistrer les paramètres pour le moment.';
                    }
                }
            }
        }

        try {
            $settings = $db->query('SELECT key,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            Logger::exception($e, ['action' => 'load_settings']);
            $settings = [];
            $error ??= 'Impossible de charger les paramètres.';
        }

        View::render('admin/settings', compact('settings', 'message', 'error'));
    }
}
