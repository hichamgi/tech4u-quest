PRAGMA foreign_keys = ON;

-- Run this only AFTER App\Core\Database::connection() has created the new
-- columns/tables required by the current application.
BEGIN IMMEDIATE;

-- Levels are always available structurally. Student access is controlled by
-- the badge progression rules, not by module_paths.active.
UPDATE module_paths SET active = 1;

-- Fixed level percentages.
UPDATE module_paths SET pool_percent = 25  WHERE display_order = 1;
UPDATE module_paths SET pool_percent = 50  WHERE display_order = 2;
UPDATE module_paths SET pool_percent = 75  WHERE display_order = 3;
UPDATE module_paths SET pool_percent = 100 WHERE display_order = 4;

-- The category quota sum is the Expert question count. Other levels are
-- derived automatically with ceil(25/50/75/100%).
UPDATE module_settings
SET question_count = COALESCE((
        SELECT SUM(mcs.question_count)
        FROM module_category_settings mcs
        WHERE mcs.module_id = module_settings.module_id
    ), question_count),
    initial_lives = 3;

UPDATE module_paths
SET question_count = MAX(1, CAST((
        COALESCE((
            SELECT SUM(mcs.question_count)
            FROM module_category_settings mcs
            WHERE mcs.module_id = module_paths.module_id
        ), 1) * pool_percent + 99
    ) / 100 AS INTEGER));

-- Keep level badge metadata synchronized with current level/module labels.
UPDATE path_badges
SET name = (
        SELECT p.name || ' — ' || m.title
        FROM module_paths p
        JOIN modules m ON m.id = p.module_id
        WHERE p.id = path_badges.path_id
    ),
    description = (
        SELECT 'Badge obtenu en terminant le mode ' || p.name || ' du module ' || m.title || '.'
        FROM module_paths p
        JOIN modules m ON m.id = p.module_id
        WHERE p.id = path_badges.path_id
    ),
    icon = (
        SELECT p.icon
        FROM module_paths p
        WHERE p.id = path_badges.path_id
    );

COMMIT;

PRAGMA foreign_key_check;
PRAGMA integrity_check;
