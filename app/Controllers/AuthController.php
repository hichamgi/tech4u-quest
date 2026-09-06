<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
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
                    ? Url::to('change-password.php')
                    : Url::to('dashboard')
            ));
            exit;
        }

        header('Location: ' . Url::to());
        exit;
    }
}
