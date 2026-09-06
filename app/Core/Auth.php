<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

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

        $stmt = $db->prepare(
            'SELECT id, username AS login, password_hash, role, active
             FROM users
             WHERE username = :identifier
             LIMIT 1'
        );
        $stmt->execute(['identifier' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

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

        $stmt = $db->prepare(
            'SELECT id, login_code AS login, class_code, student_number, password_hash,
                    must_change_password, active
             FROM students
             WHERE login_code = :identifier
             LIMIT 1'
        );
        $stmt->execute(['identifier' => strtoupper($identifier)]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

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

    public static function requireAdmin(string $loginPath = '../login.php'): array
    {
        $user = self::user();
        if (!$user || ($user['type'] ?? null) !== 'staff' || ($user['role'] ?? null) !== 'admin') {
            header('Location: ' . $loginPath);
            exit;
        }
        return $user;
    }

    public static function requireStudent(string $loginPath = 'login.php'): array
    {
        $user = self::user();
        if (!$user || ($user['type'] ?? null) !== 'student') {
            header('Location: ' . $loginPath);
            exit;
        }
        return $user;
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
}
