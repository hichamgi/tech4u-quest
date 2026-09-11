<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class QuestionSelectionService
{
    public function __construct(private PDO $db)
    {
    }

    public function buildForAttempt(int $moduleId, array $path, ?array $previous, int $questionCount): array
    {
        if ($previous && (string)$previous['status'] === 'game_over') {
            return $this->buildRetryPath($moduleId, $path, $previous, $questionCount);
        }
        return $this->buildFreshPath($moduleId, $path, $questionCount);
    }

    private function buildRetryPath(int $moduleId, array $path, array $previous, int $questionCount): array
    {
        $oldStmt = $this->db->prepare(
            'SELECT aq.position,aq.question_id,q.category_id,q.exclusion_group
             FROM attempt_questions aq
             JOIN questions q ON q.id=aq.question_id
             WHERE aq.attempt_id=:attempt
             ORDER BY aq.position'
        );
        $oldStmt->execute(['attempt' => $previous['id']]);
        $oldPath = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($oldPath) !== $questionCount) {
            return $this->buildFreshPath($moduleId, $path, $questionCount);
        }

        $encountered = min((int)$previous['current_position'], $questionCount);
        $newPath = [];
        $usedIds = [];
        $usedGroups = [];

        foreach ($oldPath as $old) {
            $position = (int)$old['position'];
            if ($position <= $encountered) continue;
            $questionId = (int)$old['question_id'];
            $newPath[$position] = $questionId;
            $usedIds[] = $questionId;
            $group = trim((string)($old['exclusion_group'] ?? ''));
            if ($group !== '') $usedGroups[$group] = true;
        }

        foreach ($oldPath as $old) {
            $position = (int)$old['position'];
            if ($position > $encountered) continue;

            $oldId = (int)$old['question_id'];
            $categoryId = (int)$old['category_id'];
            $questionId = $this->pickQuestion(
                $categoryId,
                $path,
                array_values(array_unique(array_merge($usedIds, [$oldId]))),
                array_keys($usedGroups),
                false
            );
            if ($questionId === null) {
                $questionId = $this->pickQuestion($categoryId, $path, $usedIds, array_keys($usedGroups), true);
            }

            $newPath[$position] = $questionId;
            $usedIds[] = $questionId;
            $meta = $this->questionMeta($questionId);
            $group = trim((string)($meta['exclusion_group'] ?? ''));
            if ($group !== '') $usedGroups[$group] = true;
        }

        ksort($newPath);
        return $newPath;
    }

    private function buildFreshPath(int $moduleId, array $path, int $questionCount): array
    {
        $quota = $this->categoryAllocation($moduleId, $questionCount);
        $questionIds = [];
        $usedGroups = [];

        foreach ($quota as $categoryId => $needed) {
            for ($i = 0; $i < $needed; $i++) {
                $questionId = $this->pickQuestion((int)$categoryId, $path, $questionIds, array_keys($usedGroups), true);
                $questionIds[] = $questionId;
                $meta = $this->questionMeta($questionId);
                $group = trim((string)($meta['exclusion_group'] ?? ''));
                if ($group !== '') $usedGroups[$group] = true;
            }
        }

        shuffle($questionIds);
        $result = [];
        foreach ($questionIds as $index => $questionId) {
            $result[$index + 1] = $questionId;
        }
        return $result;
    }

    private function categoryAllocation(int $moduleId, int $questionCount): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id,COALESCE(mcs.question_count,1) AS weight
             FROM categories c
             LEFT JOIN module_category_settings mcs ON mcs.category_id=c.id AND mcs.module_id=c.module_id
             WHERE c.module_id=:module AND c.active=1
             ORDER BY c.display_order,c.id'
        );
        $stmt->execute(['module' => $moduleId]);
        $rows = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $row): bool => (int)$row['weight'] > 0
        ));
        if (!$rows) {
            throw new RuntimeException('Aucune catégorie avec un quota positif pour ce module.');
        }

        $totalWeight = array_sum(array_map(static fn(array $row): int => (int)$row['weight'], $rows));
        if ($totalWeight < 1) {
            throw new RuntimeException('La répartition des catégories est invalide.');
        }

        $allocation = [];
        $remainders = [];
        $assigned = 0;
        foreach ($rows as $row) {
            $raw = $questionCount * ((int)$row['weight'] / $totalWeight);
            $base = (int)floor($raw);
            $allocation[(int)$row['id']] = $base;
            $remainders[(int)$row['id']] = $raw - $base;
            $assigned += $base;
        }

        arsort($remainders);
        foreach (array_keys($remainders) as $categoryId) {
            if ($assigned >= $questionCount) break;
            $allocation[$categoryId]++;
            $assigned++;
        }

        return array_filter($allocation, static fn(int $count): bool => $count > 0);
    }

    private function pickQuestion(
        int $categoryId,
        array $path,
        array $exclude = [],
        array $excludedGroups = [],
        bool $required = true
    ): ?int {
        $stmt = $this->db->prepare(
            'SELECT id,difficulty,exclusion_group
             FROM questions
             WHERE category_id=:category AND active=1
             ORDER BY difficulty,id'
        );
        $stmt->execute(['category' => $categoryId]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$all) {
            if (!$required) return null;
            throw new RuntimeException("Aucune question active dans la catégorie {$categoryId}.");
        }

        $excludeIds = array_flip(array_map('intval', $exclude));
        $excludeGroups = array_flip(array_map('strval', $excludedGroups));
        $eligible = array_values(array_filter($all, static function (array $question) use ($excludeIds, $excludeGroups): bool {
            if (isset($excludeIds[(int)$question['id']])) return false;
            $group = trim((string)($question['exclusion_group'] ?? ''));
            return $group === '' || !isset($excludeGroups[$group]);
        }));

        if (!$eligible) {
            if (!$required) return null;
            throw new RuntimeException(
                "La banque du parcours est insuffisante dans la catégorie {$categoryId}. Augmente la banque ou ajuste les exclusions."
            );
        }

        $weights = $this->difficultyWeights((string)$path['code']);
        $weighted = [];
        $total = 0;
        foreach ($eligible as $question) {
            $weight = max(1, $weights[(int)$question['difficulty']] ?? 1);
            $total += $weight;
            $weighted[] = [$question, $total];
        }

        $pick = random_int(1, $total);
        foreach ($weighted as [$question, $limit]) {
            if ($pick <= $limit) return (int)$question['id'];
        }
        return (int)$eligible[array_key_last($eligible)]['id'];
    }

    private function difficultyWeights(string $code): array
    {
        return match ($code) {
            'discovery' => [1=>70,2=>30,3=>1,4=>1,5=>1],
            'training' => [1=>25,2=>20,3=>45,4=>10,5=>1],
            'mastery' => [1=>10,2=>15,3=>50,4=>20,5=>5],
            'expert' => [1=>5,2=>10,3=>40,4=>25,5=>20],
            default => [1=>20,2=>20,3=>20,4=>20,5=>20],
        };
    }

    private function questionMeta(int $questionId): array
    {
        $stmt = $this->db->prepare('SELECT id,category_id,exclusion_group FROM questions WHERE id=:id');
        $stmt->execute(['id' => $questionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Question introuvable.');
        return $row;
    }
}
