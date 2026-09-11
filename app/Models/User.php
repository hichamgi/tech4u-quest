<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class User
{
    public function __construct(private PDO $db)
    {
    }

    public function findForAuthentication(string $identifier): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username AS login, password_hash, role, active
             FROM users
             WHERE username = :identifier
             LIMIT 1'
        );
        $stmt->execute(['identifier' => trim($identifier)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }
}
