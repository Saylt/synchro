<?php

namespace Tygh\Addons\Synchro\Application;

use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Builds process hierarchies for applying staged entities.
 */
class EntityApplicationPlanBuilder
{
    const DEFAULT_ENTITIES_PER_PORTION = 30;

    const STAGE_FETCH = 'fetch';

    const STAGE_PREPARE = 'prepare';

    const STAGE_APPLY = 'apply';

    const STAGE_FINALIZE = 'finalize';

    /**
     * Builds an application plan for staged products.
     *
     * @param int             $staging_import_id      Staging import identifier
     * @param int             $total_items            Number of staged products
     * @param int|string|null $entities_per_portion   Products per process
     * @param int|string      $max_parallel_processes Maximum parallel processes
     * @param string          $source_type            Staged snapshot type
     * @param bool            $actualize              Whether only prices and stocks are updated
     *
     * @return array{
     *     staging_import_id: int,
     *     total_items: int,
     *     total_pages: int,
     *     page_limit: int,
     *     max_parallel_processes: int,
     *     source_type: string,
     *     ranges: array<array-key, array{page_from: int, page_to: int, process_group: int, process_stage: string}>
     * }
     */
    public function buildProduct(
        $staging_import_id,
        $total_items,
        $entities_per_portion,
        $max_parallel_processes,
        $source_type,
        $actualize
    ) {
        $total_items = max(0, (int) $total_items);
        $apply_group = $actualize ? 0 : 1;
        $ranges = $actualize ? [] : [$this->createStage(0, self::STAGE_PREPARE)];
        $ranges = array_merge(
            $ranges,
            $this->buildRanges($total_items, $entities_per_portion, $apply_group)
        );
        $ranges[] = $this->createStage($apply_group + 1, self::STAGE_FINALIZE);

        return $this->createPlan(
            $staging_import_id,
            $total_items,
            $max_parallel_processes,
            $source_type,
            $ranges
        );
    }

    /**
     * Builds an application plan for category tree levels.
     *
     * @param int                    $staging_import_id      Staging import identifier
     * @param array<int, int|string> $level_counts           Category counts indexed by tree level
     * @param int|string|null        $entities_per_portion   Categories per process
     * @param int|string             $max_parallel_processes Maximum parallel processes
     *
     * @return array{
     *     staging_import_id: int,
     *     total_items: int,
     *     total_pages: int,
     *     page_limit: int,
     *     max_parallel_processes: int,
     *     source_type: string,
     *     ranges: array<array-key, array{page_from: int, page_to: int, process_group: int, process_stage: string}>
     * }
     */
    public function buildCategories(
        $staging_import_id,
        array $level_counts,
        $entities_per_portion,
        $max_parallel_processes
    ) {
        ksort($level_counts, SORT_NUMERIC);
        $ranges = [];
        $total_items = 0;
        $last_group = -1;

        foreach ($level_counts as $level => $count) {
            $level = max(0, (int) $level);
            $count = max(0, (int) $count);
            $total_items += $count;
            $last_group = max($last_group, $level);
            $ranges = array_merge(
                $ranges,
                $this->buildRanges($count, $entities_per_portion, $level)
            );
        }

        $ranges[] = $this->createStage($last_group + 1, self::STAGE_FINALIZE);

        return $this->createPlan(
            $staging_import_id,
            $total_items,
            $max_parallel_processes,
            ImportEntityRepository::SOURCE_TYPE_FULL,
            $ranges
        );
    }

    /**
     * Builds 1-based inclusive ranges for one process group.
     *
     * @param int             $total_items          Number of entities
     * @param int|string|null $entities_per_portion Entities per process
     * @param int             $process_group        Process group
     *
     * @return array<array-key, array<string, int|string>>
     */
    private function buildRanges($total_items, $entities_per_portion, $process_group)
    {
        $entities_per_portion = $entities_per_portion === null
            ? self::DEFAULT_ENTITIES_PER_PORTION
            : max(1, (int) $entities_per_portion);
        $ranges = [];

        for ($from = 1; $from <= $total_items; $from += $entities_per_portion) {
            $ranges[] = [
                'page_from'     => $from,
                'page_to'       => min($from + $entities_per_portion - 1, $total_items),
                'process_group' => $process_group,
                'process_stage' => self::STAGE_APPLY,
            ];
        }

        return $ranges;
    }

    /**
     * @param int    $process_group Process group
     * @param string $process_stage Process stage
     *
     * @return array<string, int|string>
     */
    private function createStage($process_group, $process_stage)
    {
        return [
            'page_from'     => 0,
            'page_to'       => 0,
            'process_group' => $process_group,
            'process_stage' => $process_stage,
        ];
    }

    /**
     * @param int               $staging_import_id      Staging import identifier
     * @param int               $total_items            Total entity count
     * @param int               $max_parallel_processes Maximum parallel processes
     * @param string            $source_type            Staged snapshot type
     * @param array<int, array> $ranges                 Process ranges
     *
     * @psalm-param array<array-key, array{
     *     page_from: int,
     *     page_to: int,
     *     process_group: int,
     *     process_stage: string
     * }> $ranges
     *
     * @return array{
     *     staging_import_id: int,
     *     total_items: int,
     *     total_pages: int,
     *     page_limit: int,
     *     max_parallel_processes: int,
     *     source_type: string,
     *     ranges: array<array-key, array{page_from: int, page_to: int, process_group: int, process_stage: string}>
     * }
     */
    private function createPlan(
        $staging_import_id,
        $total_items,
        $max_parallel_processes,
        $source_type,
        array $ranges
    ) {
        return [
            'staging_import_id'      => max(0, (int) $staging_import_id),
            'total_items'            => max(0, (int) $total_items),
            'total_pages'            => 0,
            'page_limit'             => 0,
            'max_parallel_processes' => max(1, (int) $max_parallel_processes),
            'source_type'            => $source_type === ImportEntityRepository::SOURCE_TYPE_TEST
                ? ImportEntityRepository::SOURCE_TYPE_TEST
                : ImportEntityRepository::SOURCE_TYPE_FULL,
            'ranges'                 => $ranges,
        ];
    }
}
