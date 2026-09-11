<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class StudentCsvImportService
{
    private const MAX_FILE_SIZE = 2_000_000;
    private const CSV_ESCAPE = '\\';
    private const IMPORT_MAX_EXECUTION_SECONDS = 300;

    public function __construct(private PDO $db)
    {
    }

    /**
     * Validate the complete CSV before writing anything. If one row is invalid,
     * the whole import is rejected. Password hashes are prepared before the
     * write transaction so SQLite is locked for the shortest practical time.
     */
    public function import(string $path, int $size): array
    {
        @set_time_limit(self::IMPORT_MAX_EXECUTION_SECONDS);

        if ($size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Le fichier CSV est vide ou dépasse 2 Mo.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Le fichier CSV est inaccessible.');
        }

        $parsed = $this->parse($path);
        $rows = $parsed['rows'];
        $errors = $parsed['errors'];

        $result = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => $errors,
            'rows' => count($rows) + count($errors),
        ];

        if ($errors !== []) {
            return $result;
        }

        $existingRows = $this->db->query(
            'SELECT id,class_code,student_number,login_code,password_hash,must_change_password,active
             FROM students'
        )->fetchAll(PDO::FETCH_ASSOC);

        $existingById = [];
        $existingLoginOwners = [];
        foreach ($existingRows as $existing) {
            $id = (int)$existing['id'];
            $existingById[$id] = $existing;
            $existingLoginOwners[(string)$existing['login_code']] = $id;
        }

        $seenIds = [];
        $seenLogins = [];
        $prepared = [];

        foreach ($rows as $row) {
            $line = (int)$row['_line'];
            $id = (int)$row['id'];
            $login = (string)$row['login_code'];

            if (isset($seenIds[$id])) {
                $result['errors'][] = "Ligne {$line} : ID élève {$id} déjà présent ligne {$seenIds[$id]}.";
                continue;
            }
            $seenIds[$id] = $line;

            if (isset($seenLogins[$login])) {
                $result['errors'][] = "Ligne {$line} : code {$login} déjà présent ligne {$seenLogins[$login]}.";
                continue;
            }
            $seenLogins[$login] = $line;

            $owner = $existingLoginOwners[$login] ?? null;
            if ($owner !== null && $owner !== $id) {
                $result['errors'][] = "Ligne {$line} : le code {$login} est déjà utilisé par un autre élève.";
                continue;
            }

            $existing = $existingById[$id] ?? null;
            if ($existing === null && mb_strlen((string)$row['password']) < 6) {
                $result['errors'][] = "Ligne {$line} : un nouvel élève doit avoir un mot de passe d’au moins 6 caractères.";
                continue;
            }
            if ($existing !== null && (string)$row['password'] !== '' && mb_strlen((string)$row['password']) < 6) {
                $result['errors'][] = "Ligne {$line} : le nouveau mot de passe doit contenir au moins 6 caractères.";
                continue;
            }

            $changes = $existing === null || (
                (string)$existing['class_code'] !== (string)$row['class_code']
                || (int)$existing['student_number'] !== (int)$row['student_number']
                || (string)$existing['login_code'] !== $login
                || (int)$existing['active'] !== (int)$row['active']
                || (int)$existing['must_change_password'] !== (int)$row['must_change_password']
                || (string)$row['password'] !== ''
            );

            if (!$changes) {
                $result['unchanged']++;
                continue;
            }

            $row['_existing'] = $existing;
            $row['password_hash'] = (string)$row['password'] !== ''
                ? password_hash((string)$row['password'], PASSWORD_DEFAULT)
                : null;
            unset($row['password']);
            $prepared[] = $row;

            if ($existing === null) {
                $result['created']++;
            } else {
                $result['updated']++;
            }
        }

        if ($result['errors'] !== []) {
            $result['created'] = 0;
            $result['updated'] = 0;
            $result['unchanged'] = 0;
            return $result;
        }

        $insert = $this->db->prepare(
            'INSERT INTO students(id,class_code,student_number,login_code,password_hash,must_change_password,active)
             VALUES(:id,:class_code,:student_number,:login_code,:password_hash,:must_change,:active)'
        );
        $updateWithPassword = $this->db->prepare(
            'UPDATE students
             SET class_code=:class_code,student_number=:student_number,login_code=:login_code,
                 must_change_password=:must_change,active=:active,password_hash=:password_hash,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=:id'
        );
        $updateWithoutPassword = $this->db->prepare(
            'UPDATE students
             SET class_code=:class_code,student_number=:student_number,login_code=:login_code,
                 must_change_password=:must_change,active=:active,updated_at=CURRENT_TIMESTAMP
             WHERE id=:id'
        );
        $history = $this->db->prepare(
            'INSERT INTO student_login_history(student_id,old_login_code,new_login_code)
             VALUES(:student_id,:old_login,:new_login)'
        );

        $this->db->beginTransaction();
        try {
            foreach ($prepared as $row) {
                $params = [
                    'id' => $row['id'],
                    'class_code' => $row['class_code'],
                    'student_number' => $row['student_number'],
                    'login_code' => $row['login_code'],
                    'must_change' => $row['must_change_password'],
                    'active' => $row['active'],
                ];
                $existing = $row['_existing'];

                if ($existing === null) {
                    $params['password_hash'] = $row['password_hash'];
                    $insert->execute($params);
                    continue;
                }

                if ($row['password_hash'] !== null) {
                    $params['password_hash'] = $row['password_hash'];
                    $updateWithPassword->execute($params);
                } else {
                    $updateWithoutPassword->execute($params);
                }

                if ((string)$existing['login_code'] !== (string)$row['login_code']) {
                    $history->execute([
                        'student_id' => $row['id'],
                        'old_login' => (string)$existing['login_code'],
                        'new_login' => (string)$row['login_code'],
                    ]);
                }
            }

            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new RuntimeException(
                'Import annulé : aucune modification n’a été enregistrée. ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /** @return array{rows:array,errors:array} */
    private function parse(string $path): array
    {
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
                    $header = ['id','class_code','student_number','password','active'];
                } elseif ($columnCount === 6) {
                    $header = ['id','class_code','student_number','password','active','must_change_password'];
                } else {
                    throw new RuntimeException(
                        'CSV sans en-tête : 5 ou 6 colonnes sont attendues. Format : ' .
                        'id;class_code;student_number;password;active[;must_change_password]'
                    );
                }
                $firstDataRow = $firstRow;
            }

            foreach (['id','class_code','student_number'] as $column) {
                if (!in_array($column, $header, true)) {
                    throw new RuntimeException("Colonne obligatoire absente : {$column}");
                }
            }

            $rows = [];
            $errors = [];
            $line = $hasHeader ? 1 : 0;

            $consume = function (array $csvRow, int $lineNumber) use ($header, &$rows, &$errors): void {
                if ($this->isEmptyRow($csvRow)) return;
                if (count($csvRow) > count($header)) {
                    $errors[] = "Ligne {$lineNumber} : trop de colonnes.";
                    return;
                }

                $csvRow = array_pad($csvRow, count($header), '');
                $data = array_combine($header, array_slice($csvRow, 0, count($header)));
                if (!is_array($data)) {
                    $errors[] = "Ligne {$lineNumber} : structure CSV invalide.";
                    return;
                }

                try {
                    $rows[] = $this->normalizeRow($data, $lineNumber);
                } catch (RuntimeException $e) {
                    $errors[] = "Ligne {$lineNumber} : " . $e->getMessage();
                }
            };

            if ($firstDataRow !== null) {
                $line = 1;
                $consume($firstDataRow, $line);
            }

            while (($csvRow = fgetcsv($handle, 0, $delimiter, '"', self::CSV_ESCAPE)) !== false) {
                $line++;
                $consume($csvRow, $line);
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    private function normalizeRow(array $data, int $line): array
    {
        $id = filter_var(trim((string)($data['id'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $number = filter_var(trim((string)($data['student_number'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $classCode = strtoupper(trim((string)($data['class_code'] ?? '')));

        if ($id === false) throw new RuntimeException('ID élève invalide.');
        if ($number === false) throw new RuntimeException('Numéro élève invalide.');
        if ($classCode === '' || !preg_match('/^[A-Z0-9_-]{1,30}$/', $classCode)) {
            throw new RuntimeException('Code classe invalide.');
        }

        return [
            '_line' => $line,
            'id' => (int)$id,
            'class_code' => $classCode,
            'student_number' => (int)$number,
            'login_code' => $classCode . '-' . (int)$number,
            'password' => trim((string)($data['password'] ?? '')),
            'active' => $this->boolValue($data['active'] ?? '1', true) ? 1 : 0,
            'must_change_password' => $this->boolValue($data['must_change_password'] ?? '1', true) ? 1 : 0,
        ];
    }

    private function detectDelimiter(string $line): string
    {
        $scores = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
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
            if (trim((string)$value) !== '') return false;
        }
        return true;
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') return $default;
        if (in_array($value, ['1','true','oui','yes','actif','active'], true)) return true;
        if (in_array($value, ['0','false','non','no','inactif','inactive'], true)) return false;
        throw new RuntimeException("Valeur booléenne invalide : {$value}");
    }
}
