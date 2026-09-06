<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Auth.php';
require_once dirname(__DIR__) . '/app/Services/GameService.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\GameService;

$student = Auth::requireStudent('login.php');
$db = Database::connection();
$game = new GameService($db);
$attemptId = (int)($_GET['attempt'] ?? $_POST['attempt'] ?? 0);
$error = null;
$feedback = null;

if ($attemptId < 1) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Jeton de sécurité invalide.';
    } else {
        try {
            $result = $game->submit($attemptId, (int)$student['id'], $_POST);
            if ($result['status'] === 'completed') {
                header('Location: module-complete.php?attempt=' . $attemptId);
                exit;
            }
            if ($result['status'] === 'game_over') {
                header('Location: game-over.php?attempt=' . $attemptId);
                exit;
            }
            if ($result['correct']) {
                header('Location: question.php?attempt=' . $attemptId);
                exit;
            }
            $feedback = 'Mauvaise réponse : une vie a été retirée. Réessaie la même question.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

try {
    $data = $game->currentQuestion($attemptId, (int)$student['id']);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $data = null;
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Défi — Tech4U-QUEST</title><link rel="icon" type="image/png" href="assets/images/icon.png"><link rel="stylesheet" href="assets/css/app.css"><style>.question-title{white-space:pre-wrap}.question-title.code-question{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;font-size:clamp(1rem,2.1vw,1.35rem);line-height:1.65;background:rgba(2,6,23,.55);border:1px solid rgba(148,163,184,.18);border-radius:14px;padding:1rem 1.1rem;overflow-x:auto}.answer span:last-child{white-space:pre-wrap}</style></head><body>
<header class="site-header"><div class="container nav"><a class="brand" href="dashboard.php"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><?php if ($data): ?><div class="lives" aria-label="<?= (int)$data['attempt']['lives'] ?> vies"><?= str_repeat('♥ ', (int)$data['attempt']['lives']) ?></div><?php endif; ?></div></header>
<main class="container page">
<?php if ($error): ?><div class="card card-pad" style="border-color:#fb7185"><strong><?= e($error) ?></strong><div style="margin-top:1rem"><a class="btn btn-secondary" href="dashboard.php">Retour au tableau de bord</a></div></div><?php elseif ($data):
$attempt = $data['attempt'];
$position = (int)$data['position'];
$total = (int)$attempt['total_questions'];
$percent = (int)round((($position - 1) / max(1, $total)) * 100);
$questionText = (string)$data['question'];
$isCodeQuestion = str_contains($questionText, "\n") || preg_match('/(^|\n)\s*\d+[.)]\s+/m', $questionText) === 1;
?>
<div class="quest-layout"><section class="card quest-card">
<div class="question-meta"><span class="chip"><?= e((string)$data['category_name']) ?></span><strong>Question <?= $position ?> / <?= $total ?></strong></div>
<div class="progress"><span style="width:<?= $percent ?>%"></span></div>
<?php if ($feedback): ?><div class="card" style="padding:.9rem;margin:1rem 0;border-color:#f59e0b"><strong><?= e($feedback) ?></strong></div><?php endif; ?>
<h1 class="question-title<?= $isCodeQuestion ? ' code-question' : '' ?>"><?= e($questionText) ?></h1>
<form method="post" action="question.php?attempt=<?= $attemptId ?>">
<input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><input type="hidden" name="attempt" value="<?= $attemptId ?>">
<div class="answers">
<?php if (in_array($data['type'], ['qcm','true_false'], true)): ?>
<?php foreach ($data['answers'] as $i => $a): ?><label class="answer" style="cursor:pointer"><input type="radio" name="answer_id" value="<?= (int)$a['id'] ?>" required style="margin-right:.8rem"><span class="answer-key"><?= chr(65 + $i) ?></span><span><?= e((string)$a['answer']) ?></span></label><?php endforeach; ?>
<?php elseif ($data['type'] === 'multiple'): ?>
<p style="color:var(--muted)">Plusieurs réponses peuvent être correctes.</p><?php foreach ($data['answers'] as $i => $a): ?><label class="answer" style="cursor:pointer"><input type="checkbox" name="answer_ids[]" value="<?= (int)$a['id'] ?>" style="margin-right:.8rem"><span class="answer-key"><?= chr(65 + $i) ?></span><span><?= e((string)$a['answer']) ?></span></label><?php endforeach; ?>
<?php else: ?>
<div class="form-group"><label class="label" for="short_answer">Ta réponse</label><input class="input" id="short_answer" name="short_answer" autocomplete="off" required></div>
<?php endif; ?>
</div>
<button class="btn btn-primary" type="submit" style="margin-top:1rem">Valider ma réponse</button>
</form>
</section><aside class="side-stack"><div class="card side-card"><h3>Progression</h3><div class="score-big" style="font-size:42px;margin:8px 0"><?= (int)$attempt['score'] ?> / <?= $total ?></div><p style="color:var(--muted)"><?= (int)$attempt['lives'] ?> vie(s) restante(s).</p></div><div class="card side-card"><h3>Règle</h3><p style="color:var(--muted);line-height:1.6">Une mauvaise réponse = −1 vie. La question reste affichée jusqu’à la bonne réponse ou jusqu’à épuisement des vies.</p></div></aside></div>
<?php endif; ?>
</main></body></html>
