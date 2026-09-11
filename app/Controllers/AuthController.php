<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\Student;
use App\Services\LoginRateLimiter;
use Throwable;

final class AuthController
{
    public function login(): void
    {
        Auth::boot();

        if (Auth::check()) {
            $this->redirectAfterLogin();
        }

        $error = '';
        $identifier = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $identifier = trim((string)($_POST['identifier'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $csrf = $_POST['csrf_token'] ?? null;
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

            if (!Auth::validateCsrf(is_string($csrf) ? $csrf : null)) {
                $error = 'Session expirée. Recharge la page et réessaie.';
            } else {
                try {
                    $db = Database::connection();
                    $limiter = new LoginRateLimiter($db);

                    if ($limiter->isBlocked($identifier, $ip)) {
                        http_response_code(429);
                        $error = 'Trop de tentatives de connexion. Réessaie dans quelques minutes.';
                    } elseif (Auth::attempt($identifier, $password)) {
                        $limiter->clear($identifier, $ip);
                        $this->redirectAfterLogin();
                    } else {
                        $limiter->registerFailure($identifier, $ip);
                        $error = 'Identifiant ou mot de passe incorrect.';
                    }
                } catch (Throwable $e) {
                    Logger::exception($e, ['area' => 'login']);
                    $error = 'La connexion est temporairement indisponible. Réessaie dans un instant.';
                }
            }
        }

        View::render('auth/login', [
            'error' => $error,
            'identifier' => $identifier,
            'csrfToken' => Auth::csrfToken(),
        ]);
    }

    public function changePassword(): void
    {
        $student = Auth::requireStudent(Url::to('login'), Url::to('change-password'), true);

        if (strtoupper((string)($student['class_code'] ?? '')) === 'DEMO' || !Auth::studentNeedsPasswordChange()) {
            header('Location: ' . Url::to('dashboard'));
            exit;
        }

        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $csrf = $_POST['csrf_token'] ?? null;
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if (!Auth::validateCsrf(is_string($csrf) ? $csrf : null)) {
                $error = 'Session expirée. Recharge la page et recommence.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Les deux mots de passe ne correspondent pas.';
            } else {
                try {
                    $studentModel = new Student(Database::connection());
                    $currentHash = $studentModel->activePasswordHash((int)$student['id']);

                    if ($currentHash === null) {
                        $error = 'Compte élève introuvable.';
                    } elseif (password_verify($newPassword, $currentHash)) {
                        $error = 'Choisis un mot de passe différent du mot de passe provisoire.';
                    } else {
                        $studentModel->setPassword((int)$student['id'], $newPassword, false);
                        Auth::markStudentPasswordChanged();
                        header('Location: ' . Url::to('dashboard'));
                        exit;
                    }
                } catch (Throwable $e) {
                    Logger::exception($e, ['area' => 'change_password', 'student_id' => (int)$student['id']]);
                    $error = 'Impossible de modifier le mot de passe pour le moment. Réessaie dans un instant.';
                }
            }
        }

        View::render('auth/change-password', [
            'student' => $student,
            'error' => $error,
            'csrfToken' => Auth::csrfToken(),
        ]);
    }

    public function logout(): void
    {
        Auth::boot();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo 'Méthode non autorisée.';
            return;
        }

        $csrf = $_POST['csrf_token'] ?? null;
        if (!Auth::validateCsrf(is_string($csrf) ? $csrf : null)) {
            http_response_code(419);
            echo 'Session expirée. Recharge la page puis réessaie.';
            return;
        }

        Auth::logout();
        header('Location: ' . Url::to());
        exit;
    }

    private function redirectAfterLogin(): never
    {
        if (Auth::isAdmin()) {
            header('Location: ' . Url::to('admin/'));
            exit;
        }

        if (Auth::isStudent()) {
            header('Location: ' . (
                Auth::studentNeedsPasswordChange()
                    ? Url::to('change-password')
                    : Url::to('dashboard')
            ));
            exit;
        }

        header('Location: ' . Url::to());
        exit;
    }
}
