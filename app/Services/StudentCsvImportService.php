<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class StudentCsvImportService
{
    private const MAX_FILE_SIZE = 2_000_000;
    private const CSV_ESCAPE = '\\';

    public function __construct(private PDO $db)
    {
    }

    /**
     * Formats accepted:
     *
     * With header:
     * id;class_code;student_number;password;active;must_change_password
     *
     * Without header (5 columns):
     * id;class_code;student_number;password;active
     *
     * Without header (6 columns):
     * id;class_code;student_number;password;active;must_change_password
     *
     * Semicolon, comma and tab delimiters are accepted.
     * Existing students are synchronized by their stable MySQL numeric ID.
     */
    public function import(string $path, int $size): array
    {
        if ($size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Le fichier CSV est vide ou dépasse 2 Mo.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Le fichier CSV est inaccessible.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Impossible d’ouvrir le fichier CSV.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new RuntimeException('Le fichier CSV est vide.');
            }

            $delimiter = $this->detectDelimiter($firstLine);
            rewind($handle);

            $firstRow = fgetcsv($handle, 0, $delimiter, '"', self::CSV_ESCAPE);
            if ($firstRow === false || $this->isEmptyRow($firstRow)) {
                throw new RuntimeException('Première ligne CSV invalide.');
            }

            $normalizedFirstRow = array_map([$this, 'normalizeHeader'], $firstRow);
            $hasHeader = in_array('id', $normalizedFirstRow, true)
                && in_array('class_code', $normalizedFirstRow, true)
                && in_array('student_number', $normalizedFirstRow, true);

            if ($hasHeader) {
                $header = $normalizedFirstRow;
                $firstDataRow = null;
            } else {
                $columnCount = count($firstRow);
                if ($columnCount === 5) {
                    $header = ['id', 'class_code', 'student_number', 'password', 'active'];
                } elseif ($columnCount === 6) {
                    $header = ['id', 'class_code', 'student_number', 'password', 'active', 'must_change_password'];
                } else {
                    throw new RuntimeException(
                        'CSV sans en-tête : 5 ou 6 colonnes sont attendues. ' .
                        'Format : id;class_code;student_number;password;active[;must_change_password]'
                    );
                }
                $firstDataRow = $firstRow;
            }

            $required = ['id', 'class_code', 'student_number'];
            foreach ($required as $column) {
                if (!in_array($column, $header, true)) {
                    throw new RuntimeException("Colonne obligatoire absente : {$column}");
                }
            }

            $result = [
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'errors' => [],
                'rows' => 0,
            ];

            $lineNumber = $hasHeader ? 1 : 0;

            if ($firstDataRow !== null) {
                $lineNumber = 1;
                $this->processRow($firstDataRow, $header, $lineNumber, $result);
            }

            while (($row = fgetcsv($handle, 0, $delimiter, '"', self::CSV_ESCAPE)) !== false) {
                $lineNumber++;
                if ($this->isEmptyRow($row)) {
                    continue;
                }
                $this->processRow($row, $header, $lineNumber, $result);
            }

            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function processRow(array $row, array $header, int $lineNumber, array &$result): void
    {
        $result['rows']++;

        if (count($row) > count($header)) {
            $result['errors'][] = "Ligne {$lineNumber} : trop de colonnes.";
            return;
        }

        $row = array_pad($row, count($header), '');
        $data = array_combine($header, array_slice($row, 0, count($header)));
        if (!is_array($data)) {
            $result['errors'][] = "Ligne {$lineNumber} : structure CSV invalide.";
            return;
        }

        try {
            $status = $this->syncStudent($data);
            $result[$status]++;
        } catch (\Throwable $e) {
            $result['errors'][] = "Ligne {$lineNumber} : " . $e->getMessage();
        }
    }

    private function syncStudent(array $data): string
    {
        $id = filter_var(trim((string)($data['id'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $number = filter_var(trim((string)($data['student_number'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $classCode = strtoupper(trim((string)($data['class_code'] ?? '')));
        $password = trim((string)($data['password'] ?? ''));
        $active = $this->boolValue($data['active'] ?? '1', true);
        $mustChange = $this->boolValue($data['must_change_password'] ?? '1', true);

        if ($id === false) {
            throw new RuntimeException('ID élève invalide.');
        }
        if ($number === false) {
            throw new RuntimeException('Numéro élève invalide.');
        }
        if ($classCode === '' || !preg_match('/^[A-Z0-9_-]{1,30}$/', $classCode)) {
            throw new RuntimeException('Code classe invalide.');
        }

        $login = $classCode . '-' . $number;

        $stmt = $this->db->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $collision = $this->db->prepare('SELECT id FROM students WHERE login_code = :login AND id <> :id LIMIT 1');
        $collision->execute(['login' => $login, 'id' => $id]);
        if ($collision->fetchColumn() !== false) {
            throw new RuntimeException("Le code {$login} est déjà utilisé par un autre élève.");
        }

        if (!$existing) {
            if (mb_strlen($password) < 6) {
                throw new RuntimeException('Un nouvel élève doit avoir un mot de passe d’au moins 6 caractères.');
            }

            $insert = $this->db->prepare(
                'INSERT INTO students(id, class_code, student_number, login_code, password_hash, must_change_password, active)
                 VALUES(:id, :class_code, :student_number, :login_code, :password_hash, :must_change, :active)'
            );
            $insert->execute([
                'id' => $id,
                'class_code' => $classCode,
                'student_number' => $number,
                'login_code' => $login,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'must_change' => $mustChange ? 1 : 0,
                'active' => $active ? 1 : 0,
            ]);
            return 'created';
        }

        $changes = (
            (string)$existing['class_code'] !== $classCode
            || (int)$existing['student_number'] !== $number
            || (string)$existing['login_code'] !== $login
            || (int)$existing['active'] !== ($active ? 1 : 0)
            || (int)$existing['must_change_password'] !== ($mustChange ? 1 : 0)
            || $password !== ''
        );

        if (!$changes) {
            return 'unchanged';
        }

        $this->db->beginTransaction();
        try {
            $params = [
                'id' => $id,
                'class_code' => $classCode,
                'student_number' => $number,
                'login_code' => $login,
                'must_change' => $mustChange ? 1 : 0,
                'active' => $active ? 1 : 0,
            ];
            $passwordSql = '';
            if ($password !== '') {
                if (mb_strlen($password) < 6) {
                    throw new RuntimeException('Le nouveau mot de passe doit contenir au moins 6 caractères.');
                }
                $passwordSql = ', password_hash = :password_hash';
                $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $update = $this->db->prepare(
                'UPDATE students
                 SET class_code = :class_code,
                     student_number = :student_number,
                     login_code = :login_code,
                     must_change_password = :must_change,
                     active = :active,
                     updated_at = CURRENT_TIMESTAMP' . $passwordSql . '
                 WHERE id = :id'
            );
            $update->execute($params);

            if ((string)$existing['login_code'] !== $login) {
                $history = $this->db->prepare(
                    'INSERT INTO student_login_history(student_id, old_login_code, new_login_code)
                     VALUES(:student_id, :old_login, :new_login)'
                );
                $history->execute([
                    'student_id' => $id,
                    'old_login' => (string)$existing['login_code'],
                    'new_login' => $login,
                ]);
            }

            $this->db->commit();
            return 'updated';
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function detectDelimiter(string $line): string
    {
        $scores = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];
        arsort($scores);
        $delimiter = (string)array_key_first($scores);
        return ($scores[$delimiter] ?? 0) > 0 ? $delimiter : ';';
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
        return strtolower($value);
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return $default;
        }
        if (in_array($value, ['1', 'true', 'oui', 'yes', 'actif', 'active'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'non', 'no', 'inactif', 'inactive'], true)) {
            return false;
        }
        throw new RuntimeException("Valeur booléenne invalide : {$value}");
    }
}
