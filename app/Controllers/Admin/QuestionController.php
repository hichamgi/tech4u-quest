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
use App\Services\QuestionImportService;
use RuntimeException;
use Throwable;

final class QuestionController
{
    private const QUESTIONS_PER_PAGE = 50;
    private const EXCLUSIONS_PER_PAGE = 50;

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

    public function exclusions(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $model = new Question(Database::connection());
        $message = $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $groups = $_POST['group'] ?? [];
                    if (!is_array($groups)) {
                        throw new RuntimeException('Données invalides.');
                    }
                    $updated = $model->updateExclusionGroups($groups);
                    $message = $updated . ' question(s) mise(s) à jour sur cette page.';
                } catch (Throwable $e) {
                    $error = $this->safeError($e, 'exclusions');
                }
            }
        }

        $module = max(0, (int)($_GET['module'] ?? 0));
        $category = max(0, (int)($_GET['category'] ?? 0));
        $search = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));

        try {
            $result = $model->paginateExclusions([
                'module' => $module,
                'category' => $category,
                'search' => $search,
            ], $page, self::EXCLUSIONS_PER_PAGE);
            $questions = $result['questions'];
            $page = $result['page'];
            $totalPages = $result['totalPages'];
            $totalRows = $result['totalRows'];
            $modules = $model->modules();
            $categories = $model->categories(true);
        } catch (Throwable $e) {
            Logger::exception($e, ['controller' => self::class, 'action' => 'exclusions_load']);
            $questions = [];
            $modules = [];
            $categories = [];
            $page = 1;
            $totalPages = 1;
            $totalRows = 0;
            $error ??= 'Impossible de charger les groupes d’exclusion pour le moment.';
        }

        View::render('admin/questions/exclusions', compact(
            'message','error','module','category','search','questions','modules','categories',
            'page','totalPages','totalRows'
        ));
    }

    public function import(): void
    {
        Auth::requireAdmin(Url::to('login'));
        Auth::boot();
        $db = Database::connection();
        $model = new Question($db);
        $bank = new QuestionBankService($db);
        $importer = new QuestionImportService($db, $model, $bank);
        $errors = [];
        $preview = $_SESSION['question_import_preview'] ?? null;
        $message = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Jeton de sécurité invalide.';
            } elseif (($_POST['action'] ?? '') === 'commit' && is_array($preview)) {
                $validRows = is_array($preview['valid'] ?? null) ? $preview['valid'] : [];
                try {
                    $imported = $importer->commit($validRows);
                    unset($_SESSION['question_import_preview']);
                    $preview = null;
                    $message = $imported . ' question(s) importée(s). Import atomique terminé avec succès.';
                } catch (Throwable $e) {
                    if ($e instanceof RuntimeException) {
                        $errors[] = $e->getMessage() . ' Import annulé : aucune question du lot n’a été enregistrée.';
                    } else {
                        Logger::exception($e, [
                            'controller'=>self::class,
                            'action'=>'import_commit_atomic',
                        ]);
                        $errors[] = 'Erreur technique pendant l’import. Import annulé : aucune question du lot n’a été enregistrée.';
                    }
                }
            } else {
                $file = $_FILES['csv'] ?? null;
                if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $errors[] = 'Fichier CSV invalide.';
                } else {
                    try {
                        $preview = $importer->analyze(
                            (string)$file['tmp_name'],
                            (int)($file['size'] ?? 0)
                        );
                        $_SESSION['question_import_preview'] = $preview;
                    } catch (Throwable $e) {
                        $errors[] = $this->safeError($e, 'import_analyze');
                    }
                }
            }
        }

        View::render('admin/questions/import', compact('errors','preview','message'));
    }

    public function template(): void
    {
        Auth::requireAdmin(Url::to('login'));
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="questions-template.csv"');
        echo "\xEF\xBB\xBF";
        echo "category_id;question;type;difficulty;lesson;topic;explanation;exclusion_group;answer_1;correct_1;answer_2;correct_2;answer_3;correct_3;answer_4;correct_4;answer_5;correct_5;answer_6;correct_6;active\n";
    }

    public function export(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $model = new Question(Database::connection());
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="tech4u-questions.csv"');
        $out = fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF");
        $header = ['id','module_id','category_id','question','type','difficulty','lesson','topic','explanation','exclusion_group','active'];
        for ($i=1; $i<=6; $i++) {
            $header[]='answer_'.$i;
            $header[]='correct_'.$i;
        }
        fputcsv($out,$header,';','"','\\');

        foreach ($model->exportRows() as $question) {
            $row = [
                $question['id'],
                $question['module_id'],
                $question['category_id'],
                $question['question'],
                $question['type'],
                $question['difficulty'],
                $question['lesson'],
                $question['topic'],
                $question['explanation'],
                $question['exclusion_group'],
                $question['active'],
            ];
            $answers = $question['answers'] ?? [];
            for ($i=0; $i<6; $i++) {
                $row[] = $answers[$i]['answer'] ?? '';
                $row[] = $answers[$i]['is_correct'] ?? '';
            }
            fputcsv($out,$row,';','"','\\');
        }
        fclose($out);
    }

    public function reference(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $model = new Question(Database::connection());
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="modules_categories.csv"');
        $out = fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['module_id','module','category_id','category','recommended_bank_size'],';','"','\\');
        foreach ($model->referenceRows() as $row) {
            fputcsv($out,$row,';','"','\\');
        }
        fclose($out);
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
