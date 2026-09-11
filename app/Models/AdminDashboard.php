<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class AdminDashboard
{
    public function __construct(private PDO $db)
    {
    }

    public function stats(): array
    {
        $scalar = fn(string $sql): int => (int)$this->db->query($sql)->fetchColumn();

        return [
            'students' => $scalar('SELECT COUNT(*) FROM students WHERE active = 1'),
            'classes' => $scalar('SELECT COUNT(DISTINCT class_code) FROM students WHERE active = 1'),
            'modules' => $scalar('SELECT COUNT(*) FROM modules WHERE active = 1'),
            'categories' => $scalar('SELECT COUNT(*) FROM categories WHERE active = 1'),
            'questions' => $scalar('SELECT COUNT(*) FROM questions WHERE active = 1'),
            'attempts' => $scalar('SELECT COUNT(*) FROM attempts'),
            'badges' => $scalar('SELECT COUNT(*) FROM path_badges'),
            'awarded_badges' => $scalar('SELECT COUNT(*) FROM student_path_badges'),
        ];
    }

    public function moduleStatus(): array
    {
        $modules = $this->db->query(
            'SELECT m.id,m.icon,m.title,m.recommended_bank_size,ms.question_count,ms.initial_lives,
                    COUNT(DISTINCT c.id) categories,
                    COUNT(DISTINCT CASE WHEN q.active=1 THEN q.id END) active_questions
             FROM modules m
             LEFT JOIN module_settings ms ON ms.module_id=m.id
             LEFT JOIN categories c ON c.module_id=m.id AND c.active=1
             LEFT JOIN questions q ON q.category_id=c.id
             WHERE m.active=1
             GROUP BY m.id
             ORDER BY m.display_order,m.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $quota = $this->db->prepare(
            'SELECT COALESCE(SUM(question_count),0)
             FROM module_category_settings
             WHERE module_id=:id'
        );
        foreach ($modules as &$module) {
            $quota->execute(['id' => $module['id']]);
            $module['configured_quota'] = (int)$quota->fetchColumn();
        }
        unset($module);

        return $modules;
    }

    public function expectedBadgeCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM module_paths')->fetchColumn();
    }

    public function recentAttempts(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->query(
            'SELECT a.id,s.login_code,m.title module_title,a.score,a.total_questions,a.status,a.started_at
             FROM attempts a
             JOIN students s ON s.id=a.student_id
             JOIN modules m ON m.id=a.module_id
             ORDER BY a.id DESC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
