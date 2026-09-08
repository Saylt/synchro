<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

defined('TIME') or define('TIME', time());

use Tygh\Addons\Synchro\Dto\CategoryDto;
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

    public function testRemovesStagedEntitiesWithStringAffectedRows()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'DELETE FROM ?:?p WHERE import_id IN (?n)',
                ImportEntityRepository::TABLE_NAME,
                [41, 43]
            )
            ->willReturn('2');

        $this->assertSame(2, (new ImportEntityRepository($database))->removeByImportIds([41, 43]));
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

    public function testRejectsEntityWithUnexpectedTypeFromBoundedBatch()
    {
        $category = new CategoryDto();
        $category->id = 7;
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getColumn')
            ->willReturn([serialize($category)]);

        $this->expectException(\UnexpectedValueException::class);
        (new ImportEntityRepository($database))->findEntityBatch([41], ProductDto::ENTITY_TYPE, '', 100);
    }

    public function testFindsCategoryBatchByPersistedApplicationPosition()
    {
        $first_category = new CategoryDto();
        $first_category->id = 10;
        $second_category = new CategoryDto();
        $second_category->id = 20;
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getArray')
            ->with(
                'SELECT application_position, entity FROM ?:?p'
                . ' WHERE import_id = ?i AND entity_type = ?s AND application_position > ?i'
                . ' ORDER BY application_position LIMIT ?i',
                ImportEntityRepository::TABLE_NAME,
                41,
                CategoryDto::ENTITY_TYPE,
                100,
                100
            )
            ->willReturn([
                ['application_position' => '101', 'entity' => serialize($first_category)],
                ['application_position' => '102', 'entity' => serialize($second_category)],
            ]);

        $batch = (new ImportEntityRepository($database))->findCategoryApplicationBatch(41, 100, 100);

        $this->assertSame([101, 102], array_keys($batch));
        $this->assertSame(10, $batch[101]->id);
        $this->assertSame(20, $batch[102]->id);
    }

    public function testStoresCategoryApplicationCheckpoint()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('query')
            ->with(
                'UPDATE ?:?p SET application_cursor = ?i, updated_at = ?i WHERE import_id = ?i',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                200,
                TIME,
                41
            )
            ->willReturn('1');

        $this->assertTrue((new ImportEntityRepository($database))->updateApplicationCursor(41, 200));
    }

    public function testPersistsCategoryApplicationOrder()
    {
        $parent = new CategoryDto();
        $parent->id = 10;
        $child = new CategoryDto();
        $child->id = 20;
        $database = $this->createDatabase();
        $database->expects($this->once())->method('replaceInto')
            ->with(
                ImportEntityRepository::TABLE_NAME,
                $this->callback(static function (array $records) {
                    return $records[0]['entity_id'] === '10'
                        && $records[0]['application_position'] === 1
                        && $records[1]['entity_id'] === '20'
                        && $records[1]['application_position'] === 2;
                }),
                true,
                ['entity', 'application_position', 'updated_at']
            )
            ->willReturn(2);

        $this->assertSame(
            2,
            (new ImportEntityRepository($database))->batchSaveCategories(41, 1, [$parent, $child])
        );
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
