<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Url;
use App\Core\View;
use App\Services\QuestionBankService;
use PDO;
use RuntimeException;
use Throwable;

final class QuestionController
{
    private const QUESTIONS_PER_PAGE = 50;

    public function index(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        $svc = new QuestionBankService($db);
        $message = $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $id = (int)($_POST['id'] ?? 0);
                    $action = (string)($_POST['action'] ?? '');
                    if ($action === 'toggle') {
                        $svc->toggle($id);
                        $message = 'État de la question modifié.';
                    } elseif ($action === 'duplicate') {
                        $new = $svc->duplicate($id);
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

        $modules = $db->query('SELECT id,title FROM modules ORDER BY display_order,id')->fetchAll(PDO::FETCH_ASSOC);
        $categories = $db->query('SELECT id,module_id,name FROM categories ORDER BY module_id,display_order,id')->fetchAll(PDO::FETCH_ASSOC);
        $groups = $db->query("SELECT DISTINCT exclusion_group FROM questions WHERE exclusion_group IS NOT NULL AND trim(exclusion_group)<>'' ORDER BY exclusion_group")->fetchAll(PDO::FETCH_COLUMN);

        $from = ' FROM questions q JOIN categories c ON c.id=q.category_id JOIN modules m ON m.id=c.module_id WHERE 1=1';
        $where = '';
        $params = [];

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
        if ($q !== '') {
            $where .= ' AND q.question LIKE :q';
            $params['q'] = '%' . $q . '%';
        }
        if ($active === '1' || $active === '0') {
            $where .= ' AND q.active=:a';
            $params['a'] = (int)$active;
        }
        if ($group !== '') {
            $where .= ' AND q.exclusion_group=:g';
            $params['g'] = $group;
        }

        $countStmt = $db->prepare('SELECT COUNT(*)' . $from . $where);
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / self::QUESTIONS_PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::QUESTIONS_PER_PAGE;

        $sql = 'SELECT q.id,q.question,q.type,q.difficulty,q.lesson,q.topic,q.active,q.exclusion_group,c.name category_name,m.title module_title,'
             . '(SELECT COUNT(*) FROM question_answers a WHERE a.question_id=q.id) answer_count'
             . $from . $where
             . ' ORDER BY q.id DESC LIMIT ' . self::QUESTIONS_PER_PAGE . ' OFFSET ' . $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        $svc = new QuestionBankService($db);
        $error = null;
        $question = ['category_id'=>'','question'=>'','type'=>'qcm','difficulty'=>1,'lesson'=>'','topic'=>'','explanation'=>'','exclusion_group'=>'','active'=>1];
        $answers = [['answer'=>'','is_correct'=>1],['answer'=>'','is_correct'=>0],['answer'=>'','is_correct'=>0],['answer'=>'','is_correct'=>0]];

        if ($id > 0) {
            $s = $db->prepare('SELECT * FROM questions WHERE id=:id');
            $s->execute(['id'=>$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo 'Question introuvable.';
                return;
            }
            $question = $row;
            $a = $db->prepare('SELECT answer,is_correct FROM question_answers WHERE question_id=:id ORDER BY display_order,id');
            $a->execute(['id'=>$id]);
            $answers = $a->fetchAll(PDO::FETCH_ASSOC) ?: $answers;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $posted = [];
                    foreach ((array)($_POST['answer'] ?? []) as $i => $text) {
                        $posted[] = ['answer'=>$text,'is_correct'=>isset($_POST['correct'][$i]) ? 1 : 0];
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
                    $saved = $svc->save($data, $id ?: null);
                    header('Location: ' . Url::to('admin/questions?message=saved&id=' . $saved));
                    exit;
                } catch (Throwable $e) {
                    $error = $this->safeError($e, 'save');
                    $question = array_merge($question, $_POST);
                    $answers = $posted ?? $answers;
                }
            }
        }

        $categories = $db->query('SELECT c.id,c.name,m.title module_title FROM categories c JOIN modules m ON m.id=c.module_id WHERE c.active=1 ORDER BY m.display_order,c.display_order,c.id')->fetchAll(PDO::FETCH_ASSOC);
        View::render('admin/questions/edit', compact('id','question','answers','categories','error'));
    }

    public function exclusions(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        $message = $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                try {
                    $groups = $_POST['group'] ?? [];
                    if (!is_array($groups)) throw new RuntimeException('Données invalides.');
                    $stmt = $db->prepare('UPDATE questions SET exclusion_group=:g,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
                    $db->beginTransaction();
                    foreach ($groups as $id => $g) {
                        $id = (int)$id;
                        if ($id < 1) continue;
                        $g = trim((string)$g);
                        if ($g !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,50}$/', $g)) {
                            throw new RuntimeException('Nom de groupe invalide pour la question #' . $id . '.');
                        }
                        $stmt->execute(['g'=>$g !== '' ? $g : null,'id'=>$id]);
                    }
                    $db->commit();
                    $message = 'Groupes d’exclusion enregistrés.';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = $this->safeError($e, 'exclusions');
                }
            }
        }

        $module = (int)($_GET['module'] ?? 0);
        $category = (int)($_GET['category'] ?? 0);
        $sql = 'SELECT q.id,q.question,q.exclusion_group,c.id category_id,c.name category_name,m.id module_id,m.title module_title FROM questions q JOIN categories c ON c.id=q.category_id JOIN modules m ON m.id=c.module_id WHERE q.active=1';
        $p = [];
        if ($module > 0) {$sql .= ' AND m.id=:m'; $p['m']=$module;}
        if ($category > 0) {$sql .= ' AND c.id=:c'; $p['c']=$category;}
        $sql .= ' ORDER BY m.display_order,c.display_order,q.id';
        $s = $db->prepare($sql);
        $s->execute($p);
        $questions = $s->fetchAll(PDO::FETCH_ASSOC);
        $modules = $db->query('SELECT id,title FROM modules ORDER BY display_order,id')->fetchAll(PDO::FETCH_ASSOC);
        $categories = $db->query('SELECT c.id,c.name,m.title module_title FROM categories c JOIN modules m ON m.id=c.module_id WHERE c.active=1 ORDER BY m.display_order,c.display_order,c.id')->fetchAll(PDO::FETCH_ASSOC);
        View::render('admin/questions/exclusions', compact('message','error','module','category','questions','modules','categories'));
    }

    public function import(): void
    {
        Auth::requireAdmin(Url::to('login'));
        Auth::boot();
        $db = Database::connection();
        $svc = new QuestionBankService($db);
        $errors = [];
        $preview = $_SESSION['question_import_preview'] ?? null;
        $message = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Jeton de sécurité invalide.';
            } elseif (($_POST['action'] ?? '') === 'commit' && is_array($preview)) {
                $ok = 0;
                $failed = [];
                foreach ($preview['valid'] as $i => $data) {
                    try {
                        $svc->save($data);
                        $ok++;
                    } catch (Throwable $e) {
                        if ($e instanceof RuntimeException) {
                            $failed[] = 'Ligne ' . ($i + 2) . ' : ' . $e->getMessage();
                        } else {
                            Logger::exception($e, ['controller'=>'Admin\\QuestionController','action'=>'import_commit','line'=>$i+2]);
                            $failed[] = 'Ligne ' . ($i + 2) . ' : erreur technique lors de l’enregistrement.';
                        }
                    }
                }
                unset($_SESSION['question_import_preview']);
                $preview = null;
                $message = $ok . ' question(s) importée(s).';
                $errors = $failed;
            } else {
                $file = $_FILES['csv'] ?? null;
                if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $errors[] = 'Fichier CSV invalide.';
                } elseif ((int)$file['size'] > 3000000) {
                    $errors[] = 'Le fichier dépasse 3 Mo.';
                } else {
                    $h = fopen((string)$file['tmp_name'], 'rb');
                    if (!$h) {
                        $errors[] = 'Impossible d’ouvrir le CSV.';
                    } else {
                        $first = fgets($h);
                        $delim = substr_count((string)$first, ';') >= substr_count((string)$first, ',') ? ';' : ',';
                        rewind($h);
                        $header = fgetcsv($h, 0, $delim, '"', '\\');
                        $header = array_map(static fn($v)=>strtolower(preg_replace('/^\xEF\xBB\xBF/','',trim((string)$v)) ?? trim((string)$v)), (array)$header);
                        foreach (['category_id','question','type','difficulty'] as $r) {
                            if (!in_array($r, $header, true)) $errors[] = 'Colonne obligatoire absente : ' . $r;
                        }
                        $valid = [];
                        $rowErrors = [];
                        $line = 1;
                        if (!$errors) {
                            while (($row = fgetcsv($h, 0, $delim, '"', '\\')) !== false) {
                                $line++;
                                if (count(array_filter($row, static fn($x)=>trim((string)$x) !== '')) === 0) continue;
                                $row = array_pad($row, count($header), '');
                                $d = array_combine($header, array_slice($row, 0, count($header)));
                                if (!$d) {$rowErrors[] = "Ligne $line : structure invalide."; continue;}
                                $cid = (int)($d['category_id'] ?? 0);
                                $exists = $db->prepare('SELECT 1 FROM categories WHERE id=:id AND active=1');
                                $exists->execute(['id'=>$cid]);
                                if (!$exists->fetchColumn()) {$rowErrors[] = "Ligne $line : catégorie $cid inexistante."; continue;}
                                $type = (string)($d['type'] ?? 'qcm');
                                $difficulty = (int)($d['difficulty'] ?? 0);
                                $question = trim((string)($d['question'] ?? ''));
                                if ($question === '' || !in_array($type,['qcm','true_false','multiple','short'],true) || $difficulty < 1 || $difficulty > 5) {
                                    $rowErrors[] = "Ligne $line : question/type/difficulté invalide.";
                                    continue;
                                }
                                $answers = [];
                                for ($i=1; $i<=6; $i++) {
                                    $txt = trim((string)($d['answer_'.$i] ?? ''));
                                    if ($txt !== '') $answers[] = ['answer'=>$txt,'is_correct'=>(int)($d['correct_'.$i] ?? 0) === 1 ? 1 : 0];
                                }
                                $correct = array_sum(array_column($answers,'is_correct'));
                                $bad = ($type==='qcm' && (count($answers)<2 || $correct!==1))
                                    || ($type==='multiple' && (count($answers)<2 || $correct<1))
                                    || ($type==='true_false' && (count($answers)!==2 || $correct!==1))
                                    || ($type==='short' && (count($answers)<1 || $correct<1));
                                if ($bad) {$rowErrors[] = "Ligne $line : réponses incohérentes pour le type $type."; continue;}
                                $du = $db->prepare('SELECT id FROM questions WHERE lower(trim(question))=lower(trim(:q)) LIMIT 1');
                                $du->execute(['q'=>$question]);
                                if ($du->fetchColumn()) {$rowErrors[] = "Ligne $line : question déjà existante."; continue;}
                                $valid[] = [
                                    'category_id'=>$cid,'question'=>$question,'type'=>$type,'difficulty'=>$difficulty,
                                    'lesson'=>$d['lesson']??'','topic'=>$d['topic']??'','explanation'=>$d['explanation']??'',
                                    'exclusion_group'=>$d['exclusion_group']??'','active'=>((string)($d['active']??'1') !== '0') ? 1 : 0,
                                    'answers'=>$answers,
                                ];
                            }
                        }
                        fclose($h);
                        $preview = ['valid'=>$valid,'errors'=>$rowErrors];
                        $_SESSION['question_import_preview'] = $preview;
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
        $db = Database::connection();
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="tech4u-questions.csv"');
        $out = fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF");
        $header = ['id','module_id','category_id','question','type','difficulty','lesson','topic','explanation','exclusion_group','active'];
        for ($i=1; $i<=6; $i++) {$header[]='answer_'.$i; $header[]='correct_'.$i;}
        fputcsv($out,$header,';','"','\\');
        $qs = $db->query('SELECT q.*,c.module_id FROM questions q JOIN categories c ON c.id=q.category_id ORDER BY q.id');
        $ans = $db->prepare('SELECT answer,is_correct FROM question_answers WHERE question_id=:id ORDER BY display_order,id');
        while ($question = $qs->fetch(PDO::FETCH_ASSOC)) {
            $row = [$question['id'],$question['module_id'],$question['category_id'],$question['question'],$question['type'],$question['difficulty'],$question['lesson'],$question['topic'],$question['explanation'],$question['exclusion_group'],$question['active']];
            $ans->execute(['id'=>$question['id']]);
            $answers = $ans->fetchAll(PDO::FETCH_ASSOC);
            for ($i=0; $i<6; $i++) {$row[]=$answers[$i]['answer']??''; $row[]=$answers[$i]['is_correct']??'';}
            fputcsv($out,$row,';','"','\\');
        }
        fclose($out);
    }

    public function reference(): void
    {
        Auth::requireAdmin(Url::to('login'));
        $db = Database::connection();
        header('Content-Type:text/csv; charset=UTF-8');
        header('Content-Disposition:attachment; filename="modules_categories.csv"');
        $out = fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,['module_id','module','category_id','category','recommended_bank_size'],';','"','\\');
        $rows = $db->query('SELECT m.id module_id,m.title module,c.id category_id,c.name category,c.recommended_bank_size FROM modules m JOIN categories c ON c.module_id=m.id ORDER BY m.display_order,m.id,c.display_order,c.id');
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) fputcsv($out,$row,';','"','\\');
        fclose($out);
    }

    private function safeError(Throwable $e, string $action): string
    {
        if ($e instanceof RuntimeException) return $e->getMessage();
        Logger::exception($e, ['controller'=>'Admin\\QuestionController','action'=>$action]);
        return 'Une erreur technique est survenue. Consulte le journal de l’application.';
    }
}
