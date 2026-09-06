<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Apply the pedagogical exclusion groups identified during the question-bank audit.
 *
 * Usage:
 *   php scripts/apply-question-exclusion-groups.php --dry-run
 *   php scripts/apply-question-exclusion-groups.php
 *
 * The script only updates questions.exclusion_group for the IDs listed below.
 * It does not delete questions, attempts, students, scores, badges, or answers.
 */

$groups = [
    'binary-capacity'          => [12, 20],
    'binary-255'               => [13, 18],
    'binary-157'               => [15, 17],
    'arith-2-3-4'              => [174, 175, 176, 244, 245],
    'python-input-function'    => [187, 252],
    'python-print-function'    => [188, 251],
    'pascal-read'              => [185, 221],
    'pascal-write'             => [186, 222],
    'pascal-assignment'        => [220, 226, 355],
    'python-indentation'       => [240, 270],
    'python-string-int-error'  => [348, 362],
    'algo-jour'                => [205, 206, 207],
    'algo-impots'              => [210, 211],
    'algo-tva-prix'            => [212, 213, 214],
    'python-note-ifelse'       => [264, 265],
    'python-note-elif'         => [266, 267, 268, 269],
    'ascii-a1'                 => [376, 377],
    'ascii-cat'                => [370, 381],
    'ascii-hi'                 => [341, 383],
];

$dryRun = in_array('--dry-run', $argv, true);
$db = Database::connection();

$allIds = [];
foreach ($groups as $group => $ids) {
    foreach ($ids as $id) {
        if (isset($allIds[$id])) {
            throw new RuntimeException("La question #{$id} est présente dans plusieurs groupes ({$allIds[$id]} et {$group}).");
        }
        $allIds[$id] = $group;
    }
}

$check = $db->prepare('SELECT id, question, exclusion_group FROM questions WHERE id = :id');
$missing = [];
$current = [];
foreach ($allIds as $id => $group) {
    $check->execute(['id' => $id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $missing[] = $id;
        continue;
    }
    $current[$id] = $row;
}

if ($missing) {
    throw new RuntimeException(
        'Aucune modification effectuée. Questions introuvables dans current.sqlite : ' . implode(', ', $missing)
    );
}

$changes = 0;
foreach ($allIds as $id => $group) {
    $old = trim((string)($current[$id]['exclusion_group'] ?? ''));
    if ($old !== $group) {
        $changes++;
    }
}

echo "Tech4U-QUEST — groupes d’exclusion pédagogiques\n";
echo 'Base : ' . (string)$db->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'] . "\n";
echo 'Groupes : ' . count($groups) . "\n";
echo 'Questions concernées : ' . count($allIds) . "\n";
echo 'Modifications nécessaires : ' . $changes . "\n\n";

foreach ($groups as $group => $ids) {
    echo str_pad($group, 28) . ' : ' . implode(', ', array_map(static fn(int $id): string => '#' . $id, $ids)) . "\n";
}

if ($dryRun) {
    echo "\nDRY-RUN : aucune modification n’a été écrite.\n";
    exit(0);
}

$db->beginTransaction();
try {
    $update = $db->prepare(
        'UPDATE questions
         SET exclusion_group = :group_name,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    foreach ($allIds as $id => $group) {
        $update->execute([
            'group_name' => $group,
            'id' => $id,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo "\nMise à jour terminée : {$changes} question(s) modifiée(s).\n";
echo "Aucune autre donnée de current.sqlite n’a été supprimée ou recréée.\n";
