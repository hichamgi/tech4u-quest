<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\GameService;
use PDO;
use PDOException;
use RuntimeException;

$failures = 0;

$check = static function (bool $ok, string $label) use (&$failures): void {
    if ($ok) {
        echo "[OK] {$label}\n";
        return;
    }
    $failures++;
    echo "[FAIL] {$label}\n";
};

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec(<<<'SQL'
CREATE TABLE students (
    id INTEGER PRIMARY KEY,
    class_code TEXT NOT NULL,
    student_number INTEGER NOT NULL,
    login_code TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE modules (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    description TEXT,
    icon TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    display_order INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE module_settings (
    module_id INTEGER PRIMARY KEY,
    question_count INTEGER NOT NULL,
    initial_lives INTEGER NOT NULL,
    badge_enabled INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY(module_id) REFERENCES modules(id)
);
CREATE TABLE categories (
    id INTEGER PRIMARY KEY,
    module_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    recommended_bank_size INTEGER NOT NULL DEFAULT 0,
    display_order INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY(module_id) REFERENCES modules(id)
);
CREATE TABLE module_category_settings (
    module_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    question_count INTEGER NOT NULL,
    PRIMARY KEY(module_id,category_id)
);
CREATE TABLE module_paths (
    id INTEGER PRIMARY KEY,
    module_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    icon TEXT,
    description TEXT,
    pool_percent INTEGER NOT NULL,
    question_count INTEGER NOT NULL,
    display_order INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY(module_id) REFERENCES modules(id)
);
CREATE TABLE questions (
    id INTEGER PRIMARY KEY,
    category_id INTEGER NOT NULL,
    question TEXT NOT NULL,
    type TEXT NOT NULL,
    difficulty INTEGER NOT NULL,
    explanation TEXT,
    lesson TEXT,
    topic TEXT,
    exclusion_group TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY(category_id) REFERENCES categories(id)
);
CREATE TABLE question_answers (
    id INTEGER PRIMARY KEY,
    question_id INTEGER NOT NULL,
    answer TEXT NOT NULL,
    is_correct INTEGER NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(question_id) REFERENCES questions(id)
);
CREATE TABLE attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    module_id INTEGER NOT NULL,
    path_id INTEGER,
    attempt_number INTEGER NOT NULL DEFAULT 1,
    total_questions INTEGER NOT NULL,
    current_position INTEGER NOT NULL DEFAULT 1,
    lives INTEGER NOT NULL,
    score INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'in_progress',
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    FOREIGN KEY(student_id) REFERENCES students(id),
    FOREIGN KEY(module_id) REFERENCES modules(id),
    FOREIGN KEY(path_id) REFERENCES module_paths(id)
);
CREATE UNIQUE INDEX uniq_attempts_in_progress_student_path
ON attempts(student_id,path_id)
WHERE status='in_progress' AND path_id IS NOT NULL;
CREATE TABLE attempt_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    wrong_answers INTEGER NOT NULL DEFAULT 0,
    answered INTEGER NOT NULL DEFAULT 0,
    completed INTEGER NOT NULL DEFAULT 0,
    UNIQUE(attempt_id,position),
    FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    FOREIGN KEY(question_id) REFERENCES questions(id)
);
CREATE TABLE attempt_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id INTEGER NOT NULL,
    attempt_question_id INTEGER NOT NULL,
    answer_id INTEGER,
    short_answer TEXT,
    is_correct INTEGER NOT NULL,
    answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    FOREIGN KEY(attempt_question_id) REFERENCES attempt_questions(id) ON DELETE CASCADE,
    FOREIGN KEY(answer_id) REFERENCES question_answers(id)
);
CREATE TABLE path_badges (
    id INTEGER PRIMARY KEY,
    module_id INTEGER NOT NULL,
    path_id INTEGER NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    icon TEXT,
    FOREIGN KEY(module_id) REFERENCES modules(id),
    FOREIGN KEY(path_id) REFERENCES module_paths(id)
);
CREATE TABLE student_path_badges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    badge_id INTEGER NOT NULL,
    attempt_id INTEGER,
    obtained_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(student_id,badge_id),
    FOREIGN KEY(student_id) REFERENCES students(id),
    FOREIGN KEY(badge_id) REFERENCES path_badges(id),
    FOREIGN KEY(attempt_id) REFERENCES attempts(id)
);
SQL);

$db->exec("INSERT INTO students VALUES(1,'TCT1',1,'TCT1-1','x',0,1)");
$db->exec("INSERT INTO modules VALUES(1,'Test','Module test','🧪',1,1)");
$db->exec("INSERT INTO module_settings VALUES(1,1,3,1)");
$db->exec("INSERT INTO categories VALUES(101,1,'Catégorie','',10,1,1)");
$db->exec("INSERT INTO module_category_settings VALUES(1,101,1)");
$db->exec("INSERT INTO module_paths VALUES(101,1,'discovery','Facile','🟢','',25,1,1,1)");
$db->exec("INSERT INTO module_paths VALUES(102,1,'training','Moyen','🔵','',50,1,2,1)");
$db->exec("INSERT INTO questions VALUES(1001,101,'2 + 2 = ?','qcm',1,'','','',NULL,1)");
$db->exec("INSERT INTO question_answers VALUES(2001,1001,'4',1,1)");
$db->exec("INSERT INTO question_answers VALUES(2002,1001,'5',0,2)");
$db->exec("INSERT INTO path_badges VALUES(101,1,101,'Badge facile','','🟢')");
$db->exec("INSERT INTO path_badges VALUES(102,1,102,'Badge moyen','','🔵')");

$game = new GameService($db);

$locked = false;
try {
    $game->startOrResume(1, 1, 102, false, false);
} catch (RuntimeException $e) {
    $locked = str_contains($e->getMessage(), 'verrouillé');
}
$check($locked, 'Le niveau 2 reste verrouillé sans badge du niveau 1');

$attemptId = $game->startOrResume(1, 1, 101);
$check($attemptId > 0, 'Création de la tentative niveau 1');
$check($game->startOrResume(1, 1, 101) === $attemptId, 'Reprise de la tentative existante');

$q1 = $game->currentQuestion($attemptId, 1);
$q2 = $game->currentQuestion($attemptId, 1);
$check(
    array_column($q1['answers'], 'id') === array_column($q2['answers'], 'id'),
    'Ordre des réponses stable dans une tentative'
);

$duplicateBlocked = false;
try {
    $stmt = $db->prepare(
        "INSERT INTO attempts(student_id,module_id,path_id,attempt_number,total_questions,current_position,lives,score,status)
         VALUES(1,1,101,99,1,1,3,0,'in_progress')"
    );
    $stmt->execute();
} catch (PDOException) {
    $duplicateBlocked = true;
}
$check($duplicateBlocked, 'Index UNIQUE bloque une deuxième tentative in_progress');

for ($i = 1; $i <= 3; $i++) {
    $question = $game->currentQuestion($attemptId, 1);
    $result = $game->submit($attemptId, 1, [
        'attempt_question_id' => $question['attempt_question_id'],
        'answer_id' => 2002,
    ]);
    if ($i < 3) {
        $check($result['status'] === 'in_progress', "Erreur {$i} conserve la tentative en cours");
    } else {
        $check($result['status'] === 'game_over', 'Troisième erreur déclenche game_over');
    }
}

$retryId = $game->startOrResume(1, 1, 101);
$check($retryId !== $attemptId, 'Après game_over une nouvelle tentative est créée');
$retryQuestion = $game->currentQuestion($retryId, 1);
$complete = $game->submit($retryId, 1, [
    'attempt_question_id' => $retryQuestion['attempt_question_id'],
    'answer_id' => 2001,
]);
$check($complete['status'] === 'completed', 'Bonne réponse termine le parcours');

$badge = (int)$db->query('SELECT COUNT(*) FROM student_path_badges WHERE student_id=1 AND badge_id=101')->fetchColumn();
$check($badge === 1, 'Badge attribué une seule fois à la fin du parcours');

$level2 = $game->startOrResume(1, 1, 102, false, false);
$check($level2 > 0, 'Le badge du niveau 1 déverrouille le niveau 2');

if ($failures > 0) {
    echo "{$failures} contrôle(s) fonctionnel(s) en échec.\n";
    exit(1);
}

echo "Tous les contrôles fonctionnels du moteur sont OK.\n";
exit(0);
