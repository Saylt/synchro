<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Database\Connection;
use Tygh\Tests\Unit\ATestCase;

class ImportEntityRepositoryHierarchyTest extends ATestCase
{
    public function testCreatesParentAndChildImports()
    {
        $database = $this->createDatabase();
        $database->expects($this->exactly(3))
            ->method('replaceInto')
            ->withConsecutive(
                [
                    ImportEntityRepository::IMPORTS_TABLE_NAME,
                    [
                        'parent_import_id'        => 0,
                        'cron_script_id'          => 15,
                        'company_id'              => 2,
                        'entity_type'             => 'products',
                        'source_type'             => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'status'                  => ImportEntityRepository::STATUS_PROCESSING,
                        'page_limit'              => 200,
                        'total_items'             => 378304,
                        'total_pages'             => 1892,
                        'max_parallel_processes'  => 3,
                        'error_message'           => '',
                        'created_at'              => TIME,
                        'started_at'              => TIME,
                        'updated_at'              => TIME,
                        'completed_at'            => 0,
                    ],
                ],
                [
                    ImportEntityRepository::IMPORTS_TABLE_NAME,
                    [
                        'parent_import_id'        => 40,
                        'cron_script_id'          => 15,
                        'company_id'              => 2,
                        'entity_type'             => 'products',
                        'source_type'             => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'status'                  => ImportEntityRepository::STATUS_QUEUED,
                        'page_from'               => 1,
                        'page_to'                 => 100,
                        'current_page'            => 0,
                        'page_limit'              => 200,
                        'error_message'           => '',
                        'created_at'              => TIME,
                        'started_at'              => 0,
                        'updated_at'              => TIME,
                        'completed_at'            => 0,
                    ],
                ],
                [
                    ImportEntityRepository::IMPORTS_TABLE_NAME,
                    [
                        'parent_import_id'        => 40,
                        'cron_script_id'          => 15,
                        'company_id'              => 2,
                        'entity_type'             => 'products',
                        'source_type'             => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'status'                  => ImportEntityRepository::STATUS_QUEUED,
                        'page_from'               => 101,
                        'page_to'                 => 189,
                        'current_page'            => 0,
                        'page_limit'              => 200,
                        'error_message'           => '',
                        'created_at'              => TIME,
                        'started_at'              => 0,
                        'updated_at'              => TIME,
                        'completed_at'            => 0,
                    ],
                ]
            )
            ->willReturnOnConsecutiveCalls(40, 41, 42);
        $repository = new ImportEntityRepository($database);

        $parent_id = $repository->createParentImport(2, 'products', 15, [
            'page_limit'              => 200,
            'total_items'             => 378304,
            'total_pages'             => 1892,
            'max_parallel_processes'  => 3,
        ]);
        $child_ids = $repository->createChildImports(
            $parent_id,
            2,
            'products',
            15,
            [
                ['page_from' => 1, 'page_to' => 100],
                ['page_from' => 101, 'page_to' => 189],
            ],
            200
        );

        $this->assertSame(40, $parent_id);
        $this->assertSame([41, 42], $child_ids);
    }

    public function testClaimsOnlyQueuedChild()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET status = ?s, started_at = ?i, updated_at = ?i'
                . ' WHERE import_id = ?i AND parent_import_id != ?i AND status = ?s',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                ImportEntityRepository::STATUS_PROCESSING,
                TIME,
                TIME,
                55,
                0,
                ImportEntityRepository::STATUS_QUEUED
            )
            ->willReturn(1);

        $this->assertTrue((new ImportEntityRepository($database))->claimChild(55));
    }

    public function testFindsCompletedChildrenInPageOrder()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getColumn')
            ->with(
                'SELECT import_id FROM ?:?p'
                . ' WHERE parent_import_id = ?i AND status = ?s ORDER BY page_from, import_id',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                40,
                ImportEntityRepository::STATUS_COMPLETED
            )
            ->willReturn(['41', '43']);

        $this->assertSame(
            [41, 43],
            (new ImportEntityRepository($database))->findCompletedChildIds(40)
        );
    }

    public function testLoadsAndDeduplicatesEntitiesFromCompletedChildren()
    {
        $first_product = new ProductDto();
        $first_product->id = 77;
        $first_product->name = 'Old name';
        $updated_product = new ProductDto();
        $updated_product->id = 77;
        $updated_product->name = 'Updated name';
        $second_product = new ProductDto();
        $second_product->id = 88;
        $second_product->name = 'Second product';
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getArray')
            ->willReturn([
                ['entity_id' => '77', 'entity' => serialize($first_product)],
                ['entity_id' => '77', 'entity' => serialize($updated_product)],
                ['entity_id' => '88', 'entity' => serialize($second_product)],
            ]);

        $products = (new ImportEntityRepository($database))->findAllByEntityTypeFromImports(
            [41, 43],
            ProductDto::ENTITY_TYPE
        );

        $this->assertCount(2, $products);
        $this->assertSame('Updated name', $products[0]->name);
        $this->assertSame('Second product', $products[1]->name);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Database\Connection
     */
    private function createDatabase()
    {
        return $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'beginTransaction',
                'commit',
                'getArray',
                'getColumn',
                'getField',
                'getRow',
                'query',
                'replaceInto',
                'rollback',
            ])
            ->getMock();
    }
}
