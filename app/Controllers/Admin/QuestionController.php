<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Models\Question;
use App\Services\QuestionBankService;
use RuntimeException;
use Throwable;

final class QuestionController
{
    private const QUESTIONS_PER_PAGE = 50;

    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        $model = new Question($db);
        $bank = new QuestionBankService($db);
        $message = $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $id = (int)($_POST['id'] ?? 0);
                    $action = (string)($_POST['action'] ?? '');
                    if ($action === 'toggle') {
                        $bank->toggle($id);
                        $message = 'État de la question modifié.';
                    } elseif ($action === 'duplicate') {
                        $new = $bank->duplicate($id);
                        $message = 'Question dupliquée (#' . $new . ').';
                    }
                } catch (Throwable $e) {
                    $error = $this->safeError($e, 'index_action');
                }
            }
        }

        if (($_GET['message'] ?? '') === 'saved') {
            $message = 'Question enregistrée (#' . (int)($_GET['id'] ?? 0) . ').';
        }

        $moduleId = (int)($_GET['module_id'] ?? 0);
        $categoryId = (int)($_GET['category_id'] ?? 0);
        $type = trim((string)($_GET['type'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $active = (string)($_GET['active'] ?? '');
        $group = trim((string)($_GET['group'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));

        try {
            $modules = $model->modules();
            $categories = $model->categories();
            $groups = $model->exclusionGroups();
            $result = $model->paginate([
                'module_id' => $moduleId,
                'category_id' => $categoryId,
                'type' => $type,
                'q' => $q,
                'active' => $active,
                'group' => $group,
            ], $page, self::QUESTIONS_PER_PAGE);
            $rows = $result['rows'];
            $page = $result['page'];
            $totalPages = $result['totalPages'];
            $totalRows = $result['totalRows'];
        } catch (Throwable $e) {
            Logger::exception($e, ['controller' => self::class, 'action' => 'index_load']);
            $modules = [];
            $categories = [];
            $groups = [];
            $rows = [];
            $page = 1;
            $totalPages = 1;
            $totalRows = 0;
            $error ??= 'Impossible de charger la banque de questions pour le moment.';
        }

        View::render('admin/questions/index', compact(
            'message', 'error', 'moduleId', 'categoryId', 'type', 'q', 'active', 'group',
            'modules', 'categories', 'groups', 'rows', 'page', 'totalPages', 'totalRows'
        ));
    }

    public function create(): void
    {
        $this->editInternal(0);
    }

    public function edit(string $id): void
    {
        $this->editInternal((int)$id);
    }

    private function editInternal(int $id): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        $model = new Question($db);
        $bank = new QuestionBankService($db);
        $error = null;
        $question = [
            'category_id'=>'',
            'question'=>'',
            'type'=>'qcm',
            'difficulty'=>1,
            'lesson'=>'',
            'topic'=>'',
            'explanation'=>'',
            'exclusion_group'=>'',
            'active'=>1,
        ];
        $answers = [
            ['answer'=>'','is_correct'=>1],
            ['answer'=>'','is_correct'=>0],
            ['answer'=>'','is_correct'=>0],
            ['answer'=>'','is_correct'=>0],
        ];

        if ($id > 0) {
            $record = $model->findWithAnswers($id);
            if ($record === null) {
                http_response_code(404);
                echo 'Question introuvable.';
                return;
            }
            $question = $record['question'];
            $answers = $record['answers'] ?: $answers;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $posted = [];
                    foreach ((array)($_POST['answer'] ?? []) as $i => $text) {
                        $posted[] = [
                            'answer' => $text,
                            'is_correct' => isset($_POST['correct'][$i]) ? 1 : 0,
                        ];
                    }
                    $data = [
                        'category_id'=>$_POST['category_id']??null,
                        'question'=>$_POST['question']??'',
                        'type'=>$_POST['type']??'qcm',
                        'difficulty'=>$_POST['difficulty']??1,
                        'lesson'=>$_POST['lesson']??'',
                        'topic'=>$_POST['topic']??'',
                        'explanation'=>$_POST['explanation']??'',
                        'exclusion_group'=>$_POST['exclusion_group']??'',
                        'active'=>isset($_POST['active']) ? 1 : 0,
                        'answers'=>$posted,
                    ];
                    $saved = $bank->save($data, $id ?: null);
                    header('Location: ' . Url::to('admin/questions?message=saved&id=' . $saved));
                    exit;
                } catch (Throwable $e) {
                    $error = $this->safeError($e, 'save');
                    $question = array_merge($question, $_POST);
                    $answers = $posted ?? $answers;
                }
            }
        }

        try {
            $categories = $model->categories(true);
        } catch (Throwable $e) {
            Logger::exception($e, ['controller' => self::class, 'action' => 'edit_categories']);
            $categories = [];
            $error ??= 'Impossible de charger les catégories.';
        }

        View::render('admin/questions/edit', compact('id','question','answers','categories','error'));
    }

    private function safeError(Throwable $e, string $action): string
    {
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }
        Logger::exception($e, ['controller'=>self::class,'action'=>$action]);
        return 'Une erreur technique est survenue. Consulte le journal de l’application.';
    }
}
