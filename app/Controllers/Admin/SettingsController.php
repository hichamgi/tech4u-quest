<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\Setting;
use Throwable;

final class SettingsController
{
    public function __construct(private Setting $settingsModel)
    {
    }

    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
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
                        $this->settingsModel->saveMany([
                            'site_name' => $siteName,
                            'school_year' => $schoolYear,
                        ]);
                        $message = 'Paramètres enregistrés.';
                    } catch (Throwable $e) {
                        Logger::exception($e, ['action' => 'save_settings']);
                        $error = 'Impossible d’enregistrer les paramètres pour le moment.';
                    }
                }
            }
        }

        try {
            $settings = $this->settingsModel->all();
        } catch (Throwable $e) {
            Logger::exception($e, ['action' => 'load_settings']);
            $settings = [];
            $error ??= 'Impossible de charger les paramètres.';
        }

        View::render('admin/settings', compact('settings', 'message', 'error'));
    }
}
