<?php
declare(strict_types=1);

use App\Core\Url;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Défi — Tech4U-QUEST</title>
<link rel="icon" type="image/png" href="<?= e(Url::asset('images/icon.png')) ?>">
<link rel="stylesheet" href="<?= e(Url::asset('css/app.css')) ?>">
<style>.question-title{white-space:pre-wrap}.question-title.code-question{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;font-size:clamp(1rem,2.1vw,1.35rem);line-height:1.65;background:rgba(2,6,23,.55);border:1px solid rgba(148,163,184,.18);border-radius:14px;padding:1rem 1.1rem;overflow-x:auto}.answer span:last-child{white-space:pre-wrap}</style>
</head>
<body>
<header class="site-header"><div class="container nav"><a class="brand" href="<?= e(Url::to('dashboard')) ?>"><span class="brand-mark">⚡</span><span>Tech4U <b>QUEST</b></span></a><?php if ($data): ?><div class="lives" aria-label="<?= (int)$data['attempt']['lives'] ?> vies"><?= str_repeat('♥ ', (int)$data['attempt']['lives']) ?></div><?php endif; ?></div></header>
<main class="container page">
<?php if ($error): ?>
<div class="card card-pad notice-error"><strong><?= e((string)$error) ?></strong><div class="form-actions"><a class="btn btn-secondary" href="<?= e(Url::to('dashboard')) ?>">Retour au tableau de bord</a></div></div>
<?php elseif ($data):
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
<?php if ($feedback): ?><div class="card notice" style="border-color:#f59e0b"><strong><?= e((string)$feedback) ?></strong></div><?php endif; ?>
<h1 class="question-title<?= $isCodeQuestion ? ' code-question' : '' ?>"><?= e($questionText) ?></h1>
<form method="post" action="<?= e(Url::to('question/' . $attemptId)) ?>">
<input type="hidden" name="csrf_token" value="<?= e((string)$csrfToken) ?>">
<input type="hidden" name="attempt_question_id" value="<?= (int)$data['attempt_question_id'] ?>">
<div class="answers">
<?php if (in_array($data['type'], ['qcm','true_false'], true)): ?>
<?php foreach ($data['answers'] as $i => $a): ?><label class="answer"><input type="radio" name="answer_id" value="<?= (int)$a['id'] ?>" required><span class="answer-key"><?= chr(65 + $i) ?></span><span><?= e((string)$a['answer']) ?></span></label><?php endforeach; ?>
<?php elseif ($data['type'] === 'multiple'): ?>
<p class="muted-copy">Plusieurs réponses peuvent être correctes.</p><?php foreach ($data['answers'] as $i => $a): ?><label class="answer"><input type="checkbox" name="answer_ids[]" value="<?= (int)$a['id'] ?>"><span class="answer-key"><?= chr(65 + $i) ?></span><span><?= e((string)$a['answer']) ?></span></label><?php endforeach; ?>
<?php else: ?>
<div class="form-group"><label class="label" for="short_answer">Ta réponse</label><input class="input" id="short_answer" name="short_answer" autocomplete="off" required></div>
<?php endif; ?>
</div>
<div class="form-actions"><button class="btn btn-primary" type="submit">Valider ma réponse</button></div>
</form>
</section><aside class="side-stack"><div class="card side-card"><h3>Progression</h3><div class="score-big" style="font-size:42px;margin:8px 0"><?= (int)$attempt['score'] ?> / <?= $total ?></div><p class="muted-copy"><?= (int)$attempt['lives'] ?> vie(s) restante(s).</p></div><div class="card side-card"><h3>Règle</h3><p class="muted-copy">Une mauvaise réponse = −1 vie. La question reste affichée jusqu’à la bonne réponse ou jusqu’à épuisement des vies.</p></div></aside></div>
<?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/_copyright.php'; ?>
</body>
</html>
