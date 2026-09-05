<?php

namespace Tygh\Addons\Synchro;

/**
 * Builds page ranges for a product import run.
 */
class ProductImportRangeBuilder
{
    const DEFAULT_PAGES_PER_PORTION = 100;

    const DEFAULT_PAGE_LIMIT = 200;

    const DEFAULT_MAX_PARALLEL_PROCESSES = 3;

    const TEST_PAGE_LIMIT = 10;

    /**
     * @param int   $total    Total number of products reported by the API
     * @param array $settings Product import settings
     *
     * @psalm-param array{
     *     use_portions?: string,
     *     pages_per_portion?: int|string,
     *     page_limit?: int|string,
     *     max_parallel_processes?: int|string,
     *     is_test_import?: string,
     *     test_page?: int|string
     * } $settings
     *
     * @return array{
     *     total_items: int,
     *     total_pages: int,
     *     page_limit: int,
     *     max_parallel_processes: int,
     *     ranges: array<array-key, array{page_from: int, page_to: int}>
     * }
     */
    public function build($total, array $settings)
    {
        if (isset($settings['is_test_import']) && $settings['is_test_import'] === 'Y') {
            $test_page = max(1, (int) (isset($settings['test_page']) ? $settings['test_page'] : 1));

            return [
                'total_items'           => self::TEST_PAGE_LIMIT,
                'total_pages'           => 1,
                'page_limit'             => self::TEST_PAGE_LIMIT,
                'max_parallel_processes' => 1,
                'ranges'                 => [
                    [
                        'page_from' => $test_page,
                        'page_to'   => $test_page,
                    ],
                ],
            ];
        }

        $total_items = max(0, $total);
        $page_limit = max(
            1,
            (int) (isset($settings['page_limit'])
                ? $settings['page_limit']
                : self::DEFAULT_PAGE_LIMIT)
        );
        $total_pages = (int) ceil($total_items / $page_limit);
        $use_portions = isset($settings['use_portions']) && $settings['use_portions'] === 'Y';
        $pages_per_portion = $use_portions
            ? max(
                1,
                (int) (isset($settings['pages_per_portion'])
                    ? $settings['pages_per_portion']
                    : self::DEFAULT_PAGES_PER_PORTION)
            )
            : max(1, $total_pages);
        $max_parallel_processes = $use_portions
            ? max(
                1,
                (int) (isset($settings['max_parallel_processes'])
                    ? $settings['max_parallel_processes']
                    : self::DEFAULT_MAX_PARALLEL_PROCESSES)
            )
            : 1;
        $ranges = [];

        for ($page_from = 1; $page_from <= $total_pages; $page_from += $pages_per_portion) {
            $ranges[] = [
                'page_from' => $page_from,
                'page_to'   => min($page_from + $pages_per_portion - 1, $total_pages),
            ];
        }

        return [
            'total_items'           => $total_items,
            'total_pages'           => $total_pages,
            'page_limit'             => $page_limit,
            'max_parallel_processes' => $max_parallel_processes,
            'ranges'                 => $ranges,
        ];
    }
}
