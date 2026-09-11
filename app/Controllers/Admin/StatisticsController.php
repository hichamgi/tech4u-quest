<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Services\StatisticsService;
use Throwable;

final class StatisticsController
{
    public function __construct(private StatisticsService $statistics)
    {
    }

    public function index(): void
    {
        $admin = Auth::requireAdmin(Url::to('login'));

        try {
            $data = $this->statistics->build();
        } catch (Throwable $e) {
            Logger::exception($e, ['controller' => self::class, 'action' => 'index']);
            $data = [
                'kpis' => [
                    'students' => 0,
                    'active_students' => 0,
                    'attempts' => 0,
                    'completed' => 0,
                    'game_over' => 0,
                    'badges' => 0,
                    'answers' => 0,
                    'success_rate' => 0,
                ],
                'moduleStats' => [],
                'pathStats' => [],
                'classStats' => [],
                'classLevelStats' => [],
                'studentStats' => [],
                'levelDefs' => [],
                'activity' => [],
            ];
        }

        View::render('admin/statistics', ['admin' => $admin] + $data);
    }
}
