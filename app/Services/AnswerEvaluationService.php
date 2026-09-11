<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AnswerEvaluationService
{
    /** @return array{is_correct:bool,answer_id:?int,short_answer:?string} */
    public function evaluate(array $question, array $answers, array $input): array
    {
        $type = (string)($question['type'] ?? '');
        $isCorrect = false;
        $answerId = null;
        $shortAnswer = null;

        if ($type === 'qcm' || $type === 'true_false') {
            $answerId = (int)($input['answer_id'] ?? 0);
            if ($answerId < 1) {
                throw new RuntimeException('Choisis une réponse.');
            }

            $found = false;
            foreach ($answers as $answer) {
                if ((int)$answer['id'] !== $answerId) {
                    continue;
                }
                $found = true;
                $isCorrect = (int)$answer['is_correct'] === 1;
                break;
            }
            if (!$found) {
                throw new RuntimeException('Réponse invalide pour cette question.');
            }
        } elseif ($type === 'multiple') {
            $selected = array_values(array_unique(array_map('intval', (array)($input['answer_ids'] ?? []))));
            sort($selected);
            if ($selected === []) {
                throw new RuntimeException('Choisis au moins une réponse.');
            }

            $correctIds = [];
            $allowedIds = [];
            foreach ($answers as $answer) {
                $id = (int)$answer['id'];
                $allowedIds[] = $id;
                if ((int)$answer['is_correct'] === 1) {
                    $correctIds[] = $id;
                }
            }
            sort($correctIds);

            foreach ($selected as $selectedId) {
                if (!in_array($selectedId, $allowedIds, true)) {
                    throw new RuntimeException('Réponse invalide.');
                }
            }

            $isCorrect = $selected === $correctIds;
            $shortAnswer = json_encode($selected, JSON_THROW_ON_ERROR);
        } elseif ($type === 'short') {
            $shortAnswer = trim((string)($input['short_answer'] ?? ''));
            if ($shortAnswer === '') {
                throw new RuntimeException('Saisis une réponse.');
            }

            $normalized = $this->normalizeShortAnswer($shortAnswer);
            foreach ($answers as $answer) {
                if (
                    (int)$answer['is_correct'] === 1
                    && $this->normalizeShortAnswer((string)$answer['answer']) === $normalized
                ) {
                    $isCorrect = true;
                    break;
                }
            }
        } else {
            throw new RuntimeException('Type de question non pris en charge.');
        }

        return [
            'is_correct' => $isCorrect,
            'answer_id' => $answerId,
            'short_answer' => $shortAnswer,
        ];
    }

    public function normalizeShortAnswer(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
            'ç'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ñ'=>'n',
            'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','œ'=>'oe',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'ý'=>'y','ÿ'=>'y',
            '’'=>"'",
        ]);
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
