<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Url;
use App\Core\View;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class StatisticsController
{
    public function index(): void
    {
        $admin = Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();

        $studentFilter = "s.active=1 AND UPPER(s.class_code) <> 'DEMO'";

        $kpis = $db->query(
            "SELECT
                COUNT(DISTINCT s.id) AS students,
                COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN s.id END) AS active_students,
                COUNT(DISTINCT a.id) AS attempts,
                COUNT(DISTINCT CASE WHEN a.status='completed' THEN a.id END) AS completed,
                COUNT(DISTINCT CASE WHEN a.status='game_over' THEN a.id END) AS game_over
             FROM students s
             LEFT JOIN attempts a ON a.student_id=s.id
             WHERE {$studentFilter}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $answerStats = $db->query(
            "SELECT COUNT(aa.id) AS answers,
                    SUM(CASE WHEN aa.is_correct=1 THEN 1 ELSE 0 END) AS correct_answers
             FROM attempt_answers aa
             JOIN attempts a ON a.id=aa.attempt_id
             JOIN students s ON s.id=a.student_id
             WHERE {$studentFilter}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $badgeCount = (int)$db->query(
            "SELECT COUNT(*)
             FROM student_badges sb
             JOIN students s ON s.id=sb.student_id
             WHERE {$studentFilter}"
        )->fetchColumn();

        $answers = (int)($answerStats['answers'] ?? 0);
        $correctAnswers = (int)($answerStats['correct_answers'] ?? 0);
        $successRate = $answers > 0 ? (int)round(($correctAnswers / $answers) * 100) : 0;

        $moduleStats = $db->query(
            "SELECT m.id,m.icon,m.title,
                    COUNT(a.id) AS attempts,
                    COUNT(CASE WHEN a.status='completed' THEN 1 END) AS completed,
                    COUNT(CASE WHEN a.status='game_over' THEN 1 END) AS game_over,
                    COALESCE(ROUND(AVG(CASE WHEN a.total_questions>0 THEN (100.0*a.score/a.total_questions) END)),0) AS avg_progress
             FROM modules m
             LEFT JOIN attempts a ON a.module_id=m.id
             LEFT JOIN students s ON s.id=a.student_id AND {$studentFilter}
             WHERE a.id IS NULL OR s.id IS NOT NULL
             GROUP BY m.id
             ORDER BY m.display_order,m.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $pathStats = $db->query(
            "SELECT p.code,p.name,p.icon,p.pool_percent,p.display_order,
                    COUNT(a.id) AS attempts,
                    COUNT(CASE WHEN a.status='completed' THEN 1 END) AS completed,
                    COUNT(DISTINCT CASE WHEN a.status='completed' THEN a.student_id END) AS students_completed
             FROM module_paths p
             LEFT JOIN attempts a ON a.path_id=p.id
             LEFT JOIN students s ON s.id=a.student_id AND {$studentFilter}
             WHERE a.id IS NULL OR s.id IS NOT NULL
             GROUP BY p.code,p.name,p.icon,p.pool_percent,p.display_order
             ORDER BY p.display_order"
        )->fetchAll(PDO::FETCH_ASSOC);

        $classStats = $db->query(
            "SELECT s.class_code,
                    COUNT(DISTINCT s.id) AS students,
                    COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN s.id END) AS active_students,
                    COUNT(DISTINCT a.id) AS attempts,
                    COUNT(DISTINCT CASE WHEN a.status='completed' THEN a.id END) AS completed,
                    COUNT(DISTINCT sb.id) AS badges
             FROM students s
             LEFT JOIN attempts a ON a.student_id=s.id
             LEFT JOIN student_badges sb ON sb.student_id=s.id
             WHERE {$studentFilter}
             GROUP BY s.class_code
             ORDER BY s.class_code"
        )->fetchAll(PDO::FETCH_ASSOC);

        $rawActivity = $db->query(
            "SELECT substr(a.started_at,1,10) AS day,COUNT(*) AS attempts
             FROM attempts a
             JOIN students s ON s.id=a.student_id
             WHERE {$studentFilter}
               AND a.started_at >= datetime('now','-13 days')
             GROUP BY substr(a.started_at,1,10)
             ORDER BY day"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $activity = [];
        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        for ($i = 13; $i >= 0; $i--) {
            $date = $today->modify('-'.$i.' days');
            $key = $date->format('Y-m-d');
            $activity[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'attempts' => (int)($rawActivity[$key] ?? 0),
            ];
        }

        $kpis = [
            'students' => (int)($kpis['students'] ?? 0),
            'active_students' => (int)($kpis['active_students'] ?? 0),
            'attempts' => (int)($kpis['attempts'] ?? 0),
            'completed' => (int)($kpis['completed'] ?? 0),
            'game_over' => (int)($kpis['game_over'] ?? 0),
            'badges' => $badgeCount,
            'answers' => $answers,
            'success_rate' => $successRate,
        ];

        View::render('admin/statistics', compact('admin','kpis','moduleStats','pathStats','classStats','activity'));
    }
}
