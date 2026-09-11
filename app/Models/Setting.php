<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Setting
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query('SELECT key,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function saveMany(array $values): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO settings(key,value) VALUES(:key,:value)
                 ON CONFLICT(key) DO UPDATE SET value=excluded.value'
            );

            foreach ($values as $key => $value) {
                $stmt->execute([
                    'key' => (string)$key,
                    'value' => (string)$value,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
