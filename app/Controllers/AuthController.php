<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Url;
use App\Core\View;

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

            if (!Auth::validateCsrf(is_string($csrf) ? $csrf : null)) {
                $error = 'Session expirée. Recharge la page et réessaie.';
            } elseif (Auth::attempt($identifier, $password)) {
                $this->redirectAfterLogin();
            } else {
                $error = 'Identifiant ou mot de passe incorrect.';
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
                $db = Database::connection();
                $stmt = $db->prepare('SELECT password_hash FROM students WHERE id = :id AND active = 1 LIMIT 1');
                $stmt->execute(['id' => (int)$student['id']]);
                $currentHash = $stmt->fetchColumn();

                if (!$currentHash) {
                    $error = 'Compte élève introuvable.';
                } elseif (password_verify($newPassword, (string)$currentHash)) {
                    $error = 'Choisis un mot de passe différent du mot de passe provisoire.';
                } else {
                    $update = $db->prepare(
                        'UPDATE students
                         SET password_hash = :password_hash,
                             must_change_password = 0,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $update->execute([
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'id' => (int)$student['id'],
                    ]);

                    Auth::markStudentPasswordChanged();
                    header('Location: ' . Url::to('dashboard'));
                    exit;
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
