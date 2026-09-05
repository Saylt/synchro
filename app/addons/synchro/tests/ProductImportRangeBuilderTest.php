<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

use Tygh\Addons\Synchro\ProductImportRangeBuilder;
use Tygh\Tests\Unit\ATestCase;

class ProductImportRangeBuilderTest extends ATestCase
{
    public function testBuildsPortionsWithIncompleteLastRange()
    {
        $plan = (new ProductImportRangeBuilder())->build(378304, [
            'use_portions'           => 'Y',
            'pages_per_portion'      => 100,
            'page_limit'             => 200,
            'max_parallel_processes' => 3,
            'is_test_import'         => 'N',
            'test_page'              => 1,
        ]);

        $this->assertSame(378304, $plan['total_items']);
        $this->assertSame(1892, $plan['total_pages']);
        $this->assertSame(200, $plan['page_limit']);
        $this->assertSame(3, $plan['max_parallel_processes']);
        $this->assertCount(19, $plan['ranges']);
        $this->assertSame(['page_from' => 1, 'page_to' => 100], $plan['ranges'][0]);
        $this->assertSame(['page_from' => 1801, 'page_to' => 1892], $plan['ranges'][18]);
    }

    public function testBuildsOneRangeWhenPortionsAreDisabled()
    {
        $plan = (new ProductImportRangeBuilder())->build(401, [
            'use_portions'           => 'N',
            'pages_per_portion'      => 1,
            'page_limit'             => 200,
            'max_parallel_processes' => 9,
            'is_test_import'         => 'N',
            'test_page'              => 1,
        ]);

        $this->assertSame(3, $plan['total_pages']);
        $this->assertSame(1, $plan['max_parallel_processes']);
        $this->assertSame([['page_from' => 1, 'page_to' => 3]], $plan['ranges']);
    }

    public function testBuildsSingleTestPageWithFixedLimit()
    {
        $plan = (new ProductImportRangeBuilder())->build(0, [
            'use_portions'           => 'Y',
            'pages_per_portion'      => 100,
            'page_limit'             => 200,
            'max_parallel_processes' => 3,
            'is_test_import'         => 'Y',
            'test_page'              => 417,
        ]);

        $this->assertSame(10, $plan['total_items']);
        $this->assertSame(1, $plan['total_pages']);
        $this->assertSame(10, $plan['page_limit']);
        $this->assertSame(1, $plan['max_parallel_processes']);
        $this->assertSame([['page_from' => 417, 'page_to' => 417]], $plan['ranges']);
    }

    public function testEmptyCatalogHasNoRanges()
    {
        $plan = (new ProductImportRangeBuilder())->build(0, [
            'use_portions'           => 'Y',
            'pages_per_portion'      => 100,
            'page_limit'             => 200,
            'max_parallel_processes' => 3,
            'is_test_import'         => 'N',
            'test_page'              => 1,
        ]);

        $this->assertSame(0, $plan['total_pages']);
        $this->assertSame([], $plan['ranges']);
    }
}
