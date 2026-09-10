<?php
declare(strict_types=1);

use App\Core\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = [];

$check = static function (bool $condition, string $label, ?string $detail = null) use (&$failures): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }

    $message = $detail !== null && $detail !== '' ? "{$label} — {$detail}" : $label;
    $failures[] = $message;
    echo "[FAIL] {$message}\n";
};

try {
    $db = Database::connection();

    $integrity = (string)$db->query('PRAGMA integrity_check')->fetchColumn();
    $check($integrity === 'ok', 'SQLite integrity_check', $integrity);

    $foreignKeys = $db->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_ASSOC);
    $check($foreignKeys === [], 'SQLite foreign_key_check', $foreignKeys === [] ? null : count($foreignKeys) . ' anomalie(s)');

    $requiredMigrations = [
        '2026-09-10_progression_schema_v1',
        '2026-09-10_login_rate_limit_v1',
    ];
    $migrationStmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE version=:version LIMIT 1');
    foreach ($requiredMigrations as $version) {
        $migrationStmt->execute(['version' => $version]);
        $check($migrationStmt->fetchColumn() !== false, 'Migration ' . $version);
    }

    $moduleCount = (int)$db->query('SELECT COUNT(*) FROM modules')->fetchColumn();
    $check($moduleCount === 4, '4 modules pédagogiques', 'trouvé : ' . $moduleCount);

    $pathCount = (int)$db->query('SELECT COUNT(*) FROM module_paths')->fetchColumn();
    $check($pathCount === 16, '16 niveaux de module', 'trouvé : ' . $pathCount);

    $badgeCount = (int)$db->query('SELECT COUNT(*) FROM path_badges')->fetchColumn();
    $check($badgeCount === 16, '16 badges de parcours', 'trouvé : ' . $badgeCount);

    $invalidLives = (int)$db->query('SELECT COUNT(*) FROM module_settings WHERE initial_lives <> 3')->fetchColumn();
    $check($invalidLives === 0, '3 vies pour tous les modules', 'configurations invalides : ' . $invalidLives);

    $duplicateAttempts = (int)$db->query(
        "SELECT COUNT(*) FROM (
            SELECT student_id,module_id,path_id
            FROM attempts
            WHERE status='in_progress'
            GROUP BY student_id,module_id,path_id
            HAVING COUNT(*) > 1
        )"
    )->fetchColumn();
    $check($duplicateAttempts === 0, 'Aucune double tentative active', 'doublons : ' . $duplicateAttempts);

    $missingBadges = (int)$db->query(
        'SELECT COUNT(*)
         FROM module_paths p
         LEFT JOIN path_badges b ON b.path_id=p.id
         WHERE b.id IS NULL'
    )->fetchColumn();
    $check($missingBadges === 0, 'Chaque niveau possède un badge', 'niveaux sans badge : ' . $missingBadges);

    $badPathProgression = (int)$db->query(
        "SELECT COUNT(*) FROM module_paths
         WHERE (display_order=1 AND pool_percent<>25)
            OR (display_order=2 AND pool_percent<>50)
            OR (display_order=3 AND pool_percent<>75)
            OR (display_order=4 AND pool_percent<>100)"
    )->fetchColumn();
    $check($badPathProgression === 0, 'Progression 25/50/75/100 cohérente', 'niveaux incohérents : ' . $badPathProgression);

    $rateLimitTable = (string)$db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='login_rate_limits' LIMIT 1"
    )->fetchColumn();
    $check($rateLimitTable === 'login_rate_limits', 'Table de limitation des connexions présente');
} catch (\Throwable $e) {
    $failures[] = 'Exception pendant le contrôle : ' . $e->getMessage();
    fwrite(STDERR, '[FAIL] ' . end($failures) . "\n");
}

echo "\n";
if ($failures !== []) {
    echo count($failures) . " contrôle(s) en échec.\n";
    exit(1);
}

echo "Tous les contrôles sont OK.\n";
exit(0);
