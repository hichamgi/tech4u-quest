<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Module;
use PDO;
use RuntimeException;

final class ModuleConfigurationService
{
    private const LEVEL_PERCENTS = [1 => 25, 2 => 50, 3 => 75, 4 => 100];

    public function __construct(private PDO $db, private Module $modules)
    {
    }

    public function save(int $moduleId, bool $active, array $categoryCounts): void
    {
        if ($moduleId < 1) {
            throw new RuntimeException('Paramètres du module invalides.');
        }

        $normalizedCounts = [];
        $quotaSum = 0;

        foreach ($categoryCounts as $categoryIdRaw => $countRaw) {
            $categoryId = filter_var(
                $categoryIdRaw,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $count = filter_var(
                $countRaw,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 100]]
            );

            if ($categoryId === false || $count === false) {
                throw new RuntimeException('Quota de catégorie invalide.');
            }

            if (!$this->modules->activeCategoryBelongs($moduleId, (int)$categoryId)) {
                throw new RuntimeException('Une catégorie ne correspond pas au module sélectionné.');
            }

            if ((int)$count > 0) {
                $usable = $this->modules->usableQuestionCount((int)$categoryId);
                if ($usable < (int)$count) {
                    throw new RuntimeException(
                        "La catégorie #{$categoryId} ne permet que {$usable} question(s) compatible(s) " .
                        "avec les groupes d’exclusion, pour un quota demandé de {$count}."
                    );
                }
            }

            $normalizedCounts[(int)$categoryId] = (int)$count;
            $quotaSum += (int)$count;
        }

        if ($quotaSum < 1 || $quotaSum > 100) {
            throw new RuntimeException('La somme des quotas doit être comprise entre 1 et 100 questions.');
        }

        $paths = $this->modules->paths($moduleId);
        if (count($paths) !== 4) {
            throw new RuntimeException('Les quatre niveaux du module ne sont pas disponibles.');
        }

        $this->db->beginTransaction();
        try {
            foreach ($normalizedCounts as $categoryId => $count) {
                $this->modules->upsertCategoryQuota($moduleId, $categoryId, $count);
            }

            foreach ($paths as $path) {
                $order = (int)$path['display_order'];
                $percent = self::LEVEL_PERCENTS[$order] ?? null;
                if ($percent === null) {
                    throw new RuntimeException('Ordre de niveau invalide.');
                }

                $questionCount = max(1, (int)ceil($quotaSum * ($percent / 100)));
                $this->modules->updatePathConfiguration(
                    $moduleId,
                    (int)$path['id'],
                    $questionCount,
                    $percent
                );
            }

            $this->modules->updateSettings($moduleId, $quotaSum);
            $this->modules->setActive($moduleId, $active);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
