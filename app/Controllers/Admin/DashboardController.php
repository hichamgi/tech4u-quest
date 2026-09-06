<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Url;
use App\Core\View;
use PDO;

final class DashboardController
{
    public function index(): void
    {
        $admin = Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();

        $scalar = static fn(string $sql): int => (int)$db->query($sql)->fetchColumn();
        $stats = [
            'students' => $scalar('SELECT COUNT(*) FROM students WHERE active = 1'),
            'classes' => $scalar('SELECT COUNT(DISTINCT class_code) FROM students WHERE active = 1'),
            'modules' => $scalar('SELECT COUNT(*) FROM modules WHERE active = 1'),
            'categories' => $scalar('SELECT COUNT(*) FROM categories WHERE active = 1'),
            'questions' => $scalar('SELECT COUNT(*) FROM questions WHERE active = 1'),
            'attempts' => $scalar('SELECT COUNT(*) FROM attempts'),
            'badges' => $scalar('SELECT COUNT(*) FROM badges'),
            'awarded_badges' => $scalar('SELECT COUNT(*) FROM student_badges'),
        ];

        $settings = $db->query('SELECT key,value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        $schoolYear = (string)($settings['school_year'] ?? 'Non définie');

        $moduleStatus = $db->query(
            'SELECT m.id,m.icon,m.title,m.recommended_bank_size,ms.question_count,ms.initial_lives,ms.badge_enabled,
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

        $quota = $db->prepare('SELECT COALESCE(SUM(question_count),0) FROM module_category_settings WHERE module_id=:id');
        foreach ($moduleStatus as &$module) {
            $quota->execute(['id'=>$module['id']]);
            $module['configured_quota'] = (int)$quota->fetchColumn();
        }
        unset($module);

        $configItems = [
            ['title'=>'Année scolaire','ok'=>$schoolYear !== '' && $schoolYear !== 'Non définie','detail'=>$schoolYear,'url'=>Url::to('admin/settings')],
            ['title'=>'Liste des élèves','ok'=>$stats['students'] > 0,'detail'=>$stats['students'].' élève(s) actif(s) dans '.$stats['classes'].' classe(s)','url'=>Url::to('admin/students')],
            ['title'=>'Modules pédagogiques','ok'=>$stats['modules'] === 4,'detail'=>$stats['modules'].' module(s) actif(s)','url'=>Url::to('admin/modules')],
            ['title'=>'Catégories pédagogiques','ok'=>$stats['categories'] === 40,'detail'=>$stats['categories'].' catégorie(s) active(s)','url'=>Url::to('admin/modules')],
            ['title'=>'Banque de questions','ok'=>$stats['questions'] >= 330,'detail'=>$stats['questions'].' / 330 questions cibles','url'=>Url::to('admin/questions')],
            ['title'=>'Badges','ok'=>$stats['badges'] >= 4,'detail'=>$stats['badges'].' badge(s) configuré(s)','url'=>Url::to('admin/modules')],
        ];

        $recent = $db->query(
            'SELECT a.id,s.login_code,m.title module_title,a.score,a.total_questions,a.status,a.started_at
             FROM attempts a
             JOIN students s ON s.id=a.student_id
             JOIN modules m ON m.id=a.module_id
             ORDER BY a.id DESC LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('admin/dashboard', compact('admin','stats','schoolYear','moduleStatus','configItems','recent'));
    }
}
