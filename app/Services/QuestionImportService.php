<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Models\Question;
use PDO;
use RuntimeException;
use Throwable;

final class QuestionImportService
{
    public function __construct(
        private PDO $db,
        private Question $questions,
        private QuestionBankService $bank
    ) {
    }

    /** @return array{valid:array,errors:array} */
    public function analyze(string $path, int $size): array
    {
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Fichier CSV invalide.');
        }
        if ($size > 3000000) {
            throw new RuntimeException('Le fichier dépasse 3 Mo.');
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Impossible d’ouvrir le CSV.');
        }

        try {
            $first = fgets($handle);
            $delimiter = substr_count((string)$first, ';') >= substr_count((string)$first, ',') ? ';' : ',';
            rewind($handle);

            $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
            $header = array_map(
                static fn($value): string => strtolower(
                    preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$value)) ?? trim((string)$value)
                ),
                (array)$header
            );

            foreach (['category_id', 'question', 'type', 'difficulty'] as $required) {
                if (!in_array($required, $header, true)) {
                    throw new RuntimeException('Colonne obligatoire absente : ' . $required);
                }
            }

            $valid = [];
            $errors = [];
            $line = 1;

            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $line++;
                if (count(array_filter($row, static fn($value): bool => trim((string)$value) !== '')) === 0) {
                    continue;
                }

                $row = array_pad($row, count($header), '');
                $data = array_combine($header, array_slice($row, 0, count($header)));
                if (!$data) {
                    $errors[] = "Ligne {$line} : structure invalide.";
                    continue;
                }

                $categoryId = (int)($data['category_id'] ?? 0);
                if (!$this->questions->activeCategoryExists($categoryId)) {
                    $errors[] = "Ligne {$line} : catégorie {$categoryId} inexistante.";
                    continue;
                }

                $type = (string)($data['type'] ?? 'qcm');
                $difficulty = (int)($data['difficulty'] ?? 0);
                $question = trim((string)($data['question'] ?? ''));
                if (
                    $question === ''
                    || !in_array($type, ['qcm', 'true_false', 'multiple', 'short'], true)
                    || $difficulty < 1
                    || $difficulty > 5
                ) {
                    $errors[] = "Ligne {$line} : question/type/difficulté invalide.";
                    continue;
                }

                $answers = [];
                for ($i = 1; $i <= 6; $i++) {
                    $text = trim((string)($data['answer_' . $i] ?? ''));
                    if ($text !== '') {
                        $answers[] = [
                            'answer' => $text,
                            'is_correct' => (int)($data['correct_' . $i] ?? 0) === 1 ? 1 : 0,
                        ];
                    }
                }

                if (!$this->answersAreCoherent($type, $answers)) {
                    $errors[] = "Ligne {$line} : réponses incohérentes pour le type {$type}.";
                    continue;
                }

                if ($this->questions->normalizedTextExists($question)) {
                    $errors[] = "Ligne {$line} : question déjà existante.";
                    continue;
                }

                $valid[] = [
                    'category_id' => $categoryId,
                    'question' => $question,
                    'type' => $type,
                    'difficulty' => $difficulty,
                    'lesson' => $data['lesson'] ?? '',
                    'topic' => $data['topic'] ?? '',
                    'explanation' => $data['explanation'] ?? '',
                    'exclusion_group' => $data['exclusion_group'] ?? '',
                    'active' => ((string)($data['active'] ?? '1') !== '0') ? 1 : 0,
                    'answers' => $answers,
                ];
            }

            return ['valid' => $valid, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    public function commit(array $validRows): int
    {
        if ($validRows === []) {
            throw new RuntimeException('Aucune question valide à importer.');
        }

        $this->db->beginTransaction();
        try {
            foreach ($validRows as $index => $data) {
                try {
                    $this->bank->save($data);
                } catch (Throwable $e) {
                    if ($e instanceof RuntimeException) {
                        throw new RuntimeException('Ligne ' . ($index + 2) . ' : ' . $e->getMessage(), 0, $e);
                    }

                    Logger::exception($e, [
                        'service' => self::class,
                        'action' => 'commit',
                        'line' => $index + 2,
                    ]);
                    throw new RuntimeException(
                        'Ligne ' . ($index + 2) . ' : erreur technique lors de l’enregistrement.',
                        0,
                        $e
                    );
                }
            }

            $this->db->commit();
            return count($validRows);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function answersAreCoherent(string $type, array $answers): bool
    {
        $correct = array_sum(array_column($answers, 'is_correct'));

        return match ($type) {
            'qcm' => count($answers) >= 2 && $correct === 1,
            'multiple' => count($answers) >= 2 && $correct >= 1,
            'true_false' => count($answers) === 2 && $correct === 1,
            'short' => count($answers) >= 1 && $correct >= 1,
            default => false,
        };
    }
}
