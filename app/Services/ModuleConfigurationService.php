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

        $this->assertGlobalExclusionCapacity($moduleId, $normalizedCounts);

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

    /**
     * Validate the complete module draw, including exclusion groups shared by
     * different categories. This is a bipartite matching problem:
     * each requested quota slot must receive one unique selectable token.
     * Ungrouped questions are individual tokens; every exclusion group is one
     * shared token regardless of how many categories contain that group.
     */
    private function assertGlobalExclusionCapacity(int $moduleId, array $categoryCounts): void
    {
        $positiveCounts = array_filter(
            $categoryCounts,
            static fn(int $count): bool => $count > 0
        );
        if ($positiveCounts === []) {
            return;
        }

        $rows = $this->modules->questionAvailability($moduleId, array_keys($positiveCounts));
        $tokensByCategory = [];

        foreach ($rows as $row) {
            $categoryId = (int)$row['category_id'];
            if (!isset($positiveCounts[$categoryId])) {
                continue;
            }

            $group = trim((string)($row['exclusion_group'] ?? ''));
            $token = $group !== '' ? 'g:' . $group : 'q:' . (int)$row['id'];
            $tokensByCategory[$categoryId][$token] = true;
        }

        $slots = [];
        foreach ($positiveCounts as $categoryId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $slots[] = (int)$categoryId;
            }
        }

        // token => slot index
        $matchedToken = [];
        foreach ($slots as $slotIndex => $categoryId) {
            $visited = [];
            if (!$this->augmentSlot(
                $slotIndex,
                $categoryId,
                $slots,
                $tokensByCategory,
                $matchedToken,
                $visited
            )) {
                throw new RuntimeException(
                    'La combinaison des quotas est impossible avec les groupes d’exclusion actuels. ' .
                    'Un même groupe est utilisé dans plusieurs catégories et ne peut fournir qu’une seule question par parcours.'
                );
            }
        }
    }

    /**
     * Kuhn augmenting-path matching. Exact for the module sizes used here and
     * small enough to remain deterministic and inexpensive (<= 100 slots).
     */
    private function augmentSlot(
        int $slotIndex,
        int $categoryId,
        array $slots,
        array $tokensByCategory,
        array &$matchedToken,
        array &$visited
    ): bool {
        foreach (array_keys($tokensByCategory[$categoryId] ?? []) as $token) {
            if (isset($visited[$token])) {
                continue;
            }
            $visited[$token] = true;

            if (!isset($matchedToken[$token])) {
                $matchedToken[$token] = $slotIndex;
                return true;
            }

            $otherSlot = (int)$matchedToken[$token];
            $otherCategory = (int)$slots[$otherSlot];
            if ($this->augmentSlot(
                $otherSlot,
                $otherCategory,
                $slots,
                $tokensByCategory,
                $matchedToken,
                $visited
            )) {
                $matchedToken[$token] = $slotIndex;
                return true;
            }
        }

        return false;
    }
}
