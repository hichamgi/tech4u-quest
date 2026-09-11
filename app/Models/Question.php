<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

final class Question
{
    public function __construct(private PDO $db)
    {
    }

    public function modules(): array
    {
        return $this->db->query(
            'SELECT id,title FROM modules ORDER BY display_order,id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function categories(bool $activeOnly = false): array
    {
        $sql = 'SELECT c.id,c.module_id,c.name,m.title module_title
                FROM categories c
                JOIN modules m ON m.id=c.module_id';
        if ($activeOnly) {
            $sql .= ' WHERE c.active=1';
        }
        $sql .= ' ORDER BY m.display_order,c.display_order,c.id';

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exclusionGroups(): array
    {
        return $this->db->query(
            "SELECT DISTINCT exclusion_group
             FROM questions
             WHERE exclusion_group IS NOT NULL AND trim(exclusion_group)<>''
             ORDER BY exclusion_group"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    public function paginate(array $filters, int $page, int $perPage): array
    {
        $from = ' FROM questions q
                  JOIN categories c ON c.id=q.category_id
                  JOIN modules m ON m.id=c.module_id
                  WHERE 1=1';
        [$where, $params] = $this->buildQuestionFilters($filters);

        $countStmt = $this->db->prepare('SELECT COUNT(*)' . $from . $where);
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT q.id,q.question,q.type,q.difficulty,q.lesson,q.topic,q.active,q.exclusion_group,
                       c.name category_name,m.title module_title,
                       (SELECT COUNT(*) FROM question_answers a WHERE a.question_id=q.id) answer_count'
             . $from . $where
             . ' ORDER BY q.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalRows' => $totalRows,
        ];
    }

    public function findWithAnswers(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM questions WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            return null;
        }

        $answers = $this->db->prepare(
            'SELECT answer,is_correct
             FROM question_answers
             WHERE question_id=:id
             ORDER BY display_order,id'
        );
        $answers->execute(['id' => $id]);

        return [
            'question' => $question,
            'answers' => $answers->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function updateExclusionGroups(array $groups): int
    {
        $stmt = $this->db->prepare(
            'UPDATE questions
             SET exclusion_group=:g,updated_at=CURRENT_TIMESTAMP
             WHERE id=:id'
        );

        $updated = 0;
        $this->db->beginTransaction();
        try {
            foreach ($groups as $id => $group) {
                $id = (int)$id;
                if ($id < 1) {
                    continue;
                }

                $group = trim((string)$group);
                if ($group !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $group)) {
                    throw new RuntimeException('Nom de groupe invalide pour la question #' . $id . '.');
                }

                $stmt->execute([
                    'g' => $group !== '' ? $group : null,
                    'id' => $id,
                ]);
                $updated++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $updated;
    }

    public function paginateExclusions(array $filters, int $page, int $perPage): array
    {
        $from = ' FROM questions q
                  JOIN categories c ON c.id=q.category_id
                  JOIN modules m ON m.id=c.module_id
                  WHERE q.active=1';
        $where = '';
        $params = [];

        $module = (int)($filters['module'] ?? 0);
        $category = (int)($filters['category'] ?? 0);
        $search = trim((string)($filters['search'] ?? ''));

        if ($module > 0) {
            $where .= ' AND m.id=:m';
            $params['m'] = $module;
        }
        if ($category > 0) {
            $where .= ' AND c.id=:c';
            $params['c'] = $category;
        }
        if ($search !== '') {
            $where .= ' AND (q.question LIKE :search OR q.exclusion_group LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*)' . $from . $where);
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT q.id,q.question,q.exclusion_group,
                       c.id category_id,c.name category_name,
                       m.id module_id,m.title module_title'
             . $from . $where
             . ' ORDER BY m.display_order,c.display_order,q.id'
             . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'questions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'totalPages' => $totalPages,
            'totalRows' => $totalRows,
        ];
    }

    public function activeCategoryExists(int $categoryId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM categories WHERE id=:id AND active=1');
        $stmt->execute(['id' => $categoryId]);
        return $stmt->fetchColumn() !== false;
    }

    public function normalizedTextExists(string $question): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM questions
             WHERE lower(trim(question))=lower(trim(:q))
             LIMIT 1'
        );
        $stmt->execute(['q' => $question]);
        return $stmt->fetchColumn() !== false;
    }

    public function exportRows(): array
    {
        $questions = $this->db->query(
            'SELECT q.*,c.module_id
             FROM questions q
             JOIN categories c ON c.id=q.category_id
             ORDER BY q.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $answerStmt = $this->db->prepare(
            'SELECT answer,is_correct
             FROM question_answers
             WHERE question_id=:id
             ORDER BY display_order,id'
        );

        foreach ($questions as &$question) {
            $answerStmt->execute(['id' => $question['id']]);
            $question['answers'] = $answerStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($question);

        return $questions;
    }

    public function referenceRows(): array
    {
        return $this->db->query(
            'SELECT m.id module_id,m.title module,
                    c.id category_id,c.name category,c.recommended_bank_size
             FROM modules m
             JOIN categories c ON c.module_id=m.id
             ORDER BY m.display_order,m.id,c.display_order,c.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildQuestionFilters(array $filters): array
    {
        $where = '';
        $params = [];

        $moduleId = (int)($filters['module_id'] ?? 0);
        $categoryId = (int)($filters['category_id'] ?? 0);
        $type = trim((string)($filters['type'] ?? ''));
        $query = trim((string)($filters['q'] ?? ''));
        $active = (string)($filters['active'] ?? '');
        $group = trim((string)($filters['group'] ?? ''));

        if ($moduleId > 0) {
            $where .= ' AND m.id=:m';
            $params['m'] = $moduleId;
        }
        if ($categoryId > 0) {
            $where .= ' AND c.id=:c';
            $params['c'] = $categoryId;
        }
        if (in_array($type, ['qcm', 'true_false', 'multiple', 'short'], true)) {
            $where .= ' AND q.type=:t';
            $params['t'] = $type;
        }
        if ($query !== '') {
            $where .= ' AND q.question LIKE :q';
            $params['q'] = '%' . $query . '%';
        }
        if ($active === '1' || $active === '0') {
            $where .= ' AND q.active=:a';
            $params['a'] = (int)$active;
        }
        if ($group !== '') {
            $where .= ' AND q.exclusion_group=:g';
            $params['g'] = $group;
        }

        return [$where, $params];
    }
}
