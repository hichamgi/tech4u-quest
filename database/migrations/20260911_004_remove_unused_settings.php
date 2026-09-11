<?php
declare(strict_types=1);

return [
    'version' => '2026-09-11_remove_unused_question_settings_v1',
    'up' => static function (PDO $pdo): void {
        $keys = [
            'default_question_type',
            'difficulty_1_weight',
            'difficulty_2_weight',
            'difficulty_3_weight',
            'difficulty_4_weight',
            'difficulty_5_weight',
        ];

        $delete = $pdo->prepare('DELETE FROM settings WHERE key=:key');
        foreach ($keys as $key) {
            $delete->execute(['key' => $key]);
        }
    },
];
