<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Student;
use App\Models\User;

final class Auth
{
    private const SESSION_KEY = 'auth_user';

    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_name((string)($config['session_name'] ?? 'tech4u_quest'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function attempt(string $identifier, string $password): bool
    {
        self::boot();

        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return false;
        }

        $db = Database::connection();
        $user = (new User($db))->findForAuthentication($identifier);

        if ($user && (int)$user['active'] === 1 && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY] = [
                'type' => 'staff',
                'id' => (int)$user['id'],
                'login' => (string)$user['login'],
                'role' => (string)$user['role'],
            ];
            return true;
        }

        $student = (new Student($db))->findForAuthentication($identifier);

        if ($student && (int)$student['active'] === 1 && password_verify($password, (string)$student['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY] = [
                'type' => 'student',
                'id' => (int)$student['id'],
                'login' => (string)$student['login'],
                'class_code' => (string)$student['class_code'],
                'student_number' => (int)$student['student_number'],
                'must_change_password' => (int)$student['must_change_password'] === 1,
            ];
            return true;
        }

        return false;
    }

    public static function user(): ?array
    {
        self::boot();
        $user = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null
            && ($user['type'] ?? null) === 'staff'
            && ($user['role'] ?? null) === 'admin';
    }

    public static function isStudent(): bool
    {
        $user = self::user();
        return $user !== null && ($user['type'] ?? null) === 'student';
    }

    public static function studentNeedsPasswordChange(): bool
    {
        $user = self::user();
        if (!$user || ($user['type'] ?? null) !== 'student') {
            return false;
        }

        return strtoupper((string)($user['class_code'] ?? '')) !== 'DEMO'
            && !empty($user['must_change_password']);
    }

    public static function requireAdmin(string $loginPath = '../login.php'): array
    {
        $user = self::refreshProtectedSession('staff');
        if (!$user || ($user['role'] ?? null) !== 'admin') {
            self::invalidateCurrentUser();
            header('Location: ' . $loginPath);
            exit;
        }
        return $user;
    }

    public static function requireStudent(
        string $loginPath = 'login.php',
        string $passwordChangePath = 'change-password.php',
        bool $allowPendingPasswordChange = false
    ): array {
        $user = self::refreshProtectedSession('student');
        if (!$user) {
            self::invalidateCurrentUser();
            header('Location: ' . $loginPath);
            exit;
        }

        if (!$allowPendingPasswordChange && self::studentNeedsPasswordChange()) {
            header('Location: ' . $passwordChangePath);
            exit;
        }

        return $user;
    }

    public static function markStudentPasswordChanged(): void
    {
        self::boot();
        if (isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['must_change_password'] = false;
        }
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }

        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::boot();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $token): bool
    {
        self::boot();
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals((string)$_SESSION['csrf_token'], $token);
    }

    private static function refreshProtectedSession(string $expectedType): ?array
    {
        $sessionUser = self::user();
        if (!$sessionUser || ($sessionUser['type'] ?? null) !== $expectedType) {
            return null;
        }

        $id = (int)($sessionUser['id'] ?? 0);
        $login = (string)($sessionUser['login'] ?? '');
        if ($id < 1 || $login === '') {
            return null;
        }

        $db = Database::connection();

        if ($expectedType === 'staff') {
            $record = (new User($db))->findForAuthentication($login);
            if (!$record || (int)$record['id'] !== $id || (int)$record['active'] !== 1) {
                return null;
            }

            $_SESSION[self::SESSION_KEY] = [
                'type' => 'staff',
                'id' => (int)$record['id'],
                'login' => (string)$record['login'],
                'role' => (string)$record['role'],
            ];
            return $_SESSION[self::SESSION_KEY];
        }

        $record = (new Student($db))->findForAuthentication($login);
        if (!$record || (int)$record['id'] !== $id || (int)$record['active'] !== 1) {
            return null;
        }

        $_SESSION[self::SESSION_KEY] = [
            'type' => 'student',
            'id' => (int)$record['id'],
            'login' => (string)$record['login'],
            'class_code' => (string)$record['class_code'],
            'student_number' => (int)$record['student_number'],
            'must_change_password' => (int)$record['must_change_password'] === 1,
        ];
        return $_SESSION[self::SESSION_KEY];
    }

    private static function invalidateCurrentUser(): void
    {
        self::boot();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }
}
