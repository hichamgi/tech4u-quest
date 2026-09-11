<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Module
{
    public function __construct(private PDO $db)
    {
    }

    public function activeCategoryBelongs(int $moduleId, int $categoryId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM categories
             WHERE id = :category AND module_id = :module AND active = 1'
        );
        $stmt->execute(['category' => $categoryId, 'module' => $moduleId]);

        return $stmt->fetchColumn() !== false;
    }

    public function usableQuestionCount(int $categoryId): int
    {
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE
                    WHEN q.active=1 AND (q.exclusion_group IS NULL OR TRIM(q.exclusion_group)='') THEN 1
                    ELSE 0
                END)
                + COUNT(DISTINCT CASE
                    WHEN q.active=1 AND q.exclusion_group IS NOT NULL AND TRIM(q.exclusion_group)<>''
                    THEN q.exclusion_group
                END) AS usable_questions
             FROM questions q
             WHERE q.category_id = :category"
        );
        $stmt->execute(['category' => $categoryId]);

        return (int)$stmt->fetchColumn();
    }

    public function paths(int $moduleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id,display_order
             FROM module_paths
             WHERE module_id = :module
             ORDER BY display_order,id'
        );
        $stmt->execute(['module' => $moduleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertCategoryQuota(int $moduleId, int $categoryId, int $count): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO module_category_settings(module_id,category_id,question_count)
             VALUES(:module,:category,:count)
             ON CONFLICT(module_id,category_id)
             DO UPDATE SET question_count=excluded.question_count'
        );
        $stmt->execute([
            'module' => $moduleId,
            'category' => $categoryId,
            'count' => $count,
        ]);
    }

    public function updatePathConfiguration(int $moduleId, int $pathId, int $questionCount, int $percent): void
    {
        $stmt = $this->db->prepare(
            'UPDATE module_paths
             SET question_count = :count, pool_percent = :percent, active = 1
             WHERE id = :id AND module_id = :module'
        );
        $stmt->execute([
            'count' => $questionCount,
            'percent' => $percent,
            'id' => $pathId,
            'module' => $moduleId,
        ]);
    }

    public function updateSettings(int $moduleId, int $questionCount): void
    {
        $stmt = $this->db->prepare(
            'UPDATE module_settings
             SET question_count = :count, initial_lives = 3, badge_enabled = 1
             WHERE module_id = :module'
        );
        $stmt->execute(['count' => $questionCount, 'module' => $moduleId]);
    }

    public function setActive(int $moduleId, bool $active): void
    {
        $stmt = $this->db->prepare('UPDATE modules SET active = :active WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $moduleId]);
    }

    public function adminList(): array
    {
        return $this->db->query(
            'SELECT m.id,m.title,m.description,m.icon,m.recommended_bank_size,m.active,
                    ms.question_count,ms.initial_lives,
                    COUNT(DISTINCT c.id) category_total,
                    COUNT(DISTINCT CASE WHEN q.active=1 THEN q.id END) active_questions
             FROM modules m
             LEFT JOIN module_settings ms ON ms.module_id=m.id
             LEFT JOIN categories c ON c.module_id=m.id AND c.active=1
             LEFT JOIN questions q ON q.category_id=c.id
             GROUP BY m.id
             ORDER BY m.display_order,m.id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function adminCategories(int $moduleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.id,c.name,c.description,c.recommended_bank_size,
                    COALESCE(mcs.question_count,0) draw_count,
                    COUNT(CASE WHEN q.active=1 THEN q.id END) active_questions,
                    SUM(CASE
                        WHEN q.active=1 AND (q.exclusion_group IS NULL OR TRIM(q.exclusion_group)='') THEN 1
                        ELSE 0
                    END)
                    + COUNT(DISTINCT CASE
                        WHEN q.active=1 AND q.exclusion_group IS NOT NULL AND TRIM(q.exclusion_group)<>''
                        THEN q.exclusion_group
                    END) AS usable_questions
             FROM categories c
             LEFT JOIN module_category_settings mcs
                    ON mcs.category_id=c.id AND mcs.module_id=c.module_id
             LEFT JOIN questions q ON q.category_id=c.id
             WHERE c.module_id=:module_id AND c.active=1
             GROUP BY c.id
             ORDER BY c.display_order,c.id"
        );
        $stmt->execute(['module_id' => $moduleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function adminPaths(int $moduleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id,p.code,p.name,p.icon,p.description,p.pool_percent,p.question_count,p.display_order,
                    pb.name AS badge_name,pb.icon AS badge_icon,
                    COUNT(DISTINCT spb.student_id) AS badge_holders
             FROM module_paths p
             LEFT JOIN path_badges pb ON pb.path_id=p.id
             LEFT JOIN student_path_badges spb ON spb.badge_id=pb.id
             WHERE p.module_id=:module_id
             GROUP BY p.id
             ORDER BY p.display_order,p.id'
        );
        $stmt->execute(['module_id' => $moduleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
