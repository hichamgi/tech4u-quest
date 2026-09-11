<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\AdminDashboard;
use App\Models\Setting;
use Throwable;

final class DashboardController
{
    public function __construct(
        private AdminDashboard $dashboard,
        private Setting $settingsModel
    ) {
    }

    public function index(): void
    {
        $admin = Auth::requireAdmin(Url::to('login'));
        $error = null;

        try {
            $stats = $this->dashboard->stats();
            $settings = $this->settingsModel->all();
            $schoolYear = (string)($settings['school_year'] ?? 'Non définie');
            $moduleStatus = $this->dashboard->moduleStatus();
            $expectedBadgeCount = $this->dashboard->expectedBadgeCount();
            $recent = $this->dashboard->recentAttempts(10);
        } catch (Throwable $e) {
            Logger::exception($e, ['controller' => self::class, 'action' => 'index']);
            $stats = [
                'students' => 0,
                'classes' => 0,
                'modules' => 0,
                'categories' => 0,
                'questions' => 0,
                'attempts' => 0,
                'badges' => 0,
                'awarded_badges' => 0,
            ];
            $schoolYear = 'Non définie';
            $moduleStatus = [];
            $expectedBadgeCount = 0;
            $recent = [];
            $error = 'Impossible de charger toutes les données du tableau de bord pour le moment.';
        }

        $configItems = [
            ['title'=>'Année scolaire','ok'=>$schoolYear !== '' && $schoolYear !== 'Non définie','detail'=>$schoolYear,'url'=>Url::to('admin/settings')],
            ['title'=>'Liste des élèves','ok'=>$stats['students'] > 0,'detail'=>$stats['students'].' élève(s) actif(s) dans '.$stats['classes'].' classe(s)','url'=>Url::to('admin/students')],
            ['title'=>'Modules pédagogiques','ok'=>$stats['modules'] === 4,'detail'=>$stats['modules'].' module(s) actif(s)','url'=>Url::to('admin/modules')],
            ['title'=>'Catégories pédagogiques','ok'=>$stats['categories'] === 40,'detail'=>$stats['categories'].' catégorie(s) active(s)','url'=>Url::to('admin/modules')],
            ['title'=>'Banque de questions','ok'=>$stats['questions'] >= 330,'detail'=>$stats['questions'].' / 330 questions cibles','url'=>Url::to('admin/questions')],
            ['title'=>'Badges de parcours','ok'=>$expectedBadgeCount > 0 && $stats['badges'] === $expectedBadgeCount,'detail'=>$stats['badges'].' / '.$expectedBadgeCount.' badge(s) configuré(s)','url'=>Url::to('admin/modules')],
        ];

        View::render('admin/dashboard', compact(
            'admin',
            'stats',
            'schoolYear',
            'moduleStatus',
            'configItems',
            'recent',
            'error'
        ));
    }
}
