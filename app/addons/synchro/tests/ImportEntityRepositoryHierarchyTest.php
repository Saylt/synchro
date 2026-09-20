<?php

namespace Tygh\Addons\Synchro\Repository;

if (!function_exists(__NAMESPACE__ . '\\__')) {
    function __($language_variable, array $params = [])
    {
        return $language_variable . ($params ? json_encode($params) : '');
    }
}

namespace Tygh\Addons\Synchro\Tests\Unit;

defined('TIME') or define('TIME', time());

use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Application\EntityApplicationPlanBuilder;
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
                        'staging_import_id'       => 0,
                        'cron_script_id'          => 15,
                        'company_id'              => 2,
                        'entity_type'             => 'products',
                        'source_type'             => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_NO,
                        'process_group'           => 0,
                        'process_stage'           => EntityApplicationPlanBuilder::STAGE_FETCH,
                        'status'                  => ImportEntityRepository::STATUS_PROCESSING,
                        'page_limit'              => 200,
                        'total_items'             => 378304,
                        'total_pages'             => 1892,
                        'max_parallel_processes'  => 3,
                        'processed_items'         => 0,
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
                        'staging_import_id'       => 0,
                        'cron_script_id'          => 15,
                        'company_id'              => 2,
                        'entity_type'             => 'products',
                        'source_type'             => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_NO,
                        'process_group'           => 0,
                        'process_stage'           => EntityApplicationPlanBuilder::STAGE_FETCH,
                        'status'                  => ImportEntityRepository::STATUS_QUEUED,
                        'page_from'               => 1,
                        'page_to'                 => 100,
                        'current_page'            => 0,
                        'page_limit'              => 200,
                        'processed_items'         => 0,
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
                        'staging_import_id'       => 0,
                        'cron_script_id'          => 15,
                        'company_id'              => 2,
                        'entity_type'             => 'products',
                        'source_type'             => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_NO,
                        'process_group'           => 0,
                        'process_stage'           => EntityApplicationPlanBuilder::STAGE_FETCH,
                        'status'                  => ImportEntityRepository::STATUS_QUEUED,
                        'page_from'               => 101,
                        'page_to'                 => 189,
                        'current_page'            => 0,
                        'page_limit'              => 200,
                        'processed_items'         => 0,
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

    public function testCreatesApplicationHierarchyWithProcessMetadata()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('beginTransaction');
        $database->expects($this->once())->method('commit');
        $database->expects($this->exactly(2))
            ->method('replaceInto')
            ->withConsecutive(
                [
                    ImportEntityRepository::IMPORTS_TABLE_NAME,
                    [
                        'parent_import_id'       => 0,
                        'staging_import_id'      => 31,
                        'cron_script_id'         => 15,
                        'company_id'             => 2,
                        'entity_type'            => 'products',
                        'source_type'            => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_NO,
                        'process_group'          => 0,
                        'process_stage'          => EntityApplicationPlanBuilder::STAGE_FETCH,
                        'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                        'page_limit'             => 0,
                        'total_items'            => 35,
                        'total_pages'            => 0,
                        'max_parallel_processes' => 3,
                        'processed_items'        => 0,
                        'error_message'          => '',
                        'created_at'             => TIME,
                        'started_at'             => TIME,
                        'updated_at'             => TIME,
                        'completed_at'           => 0,
                    ],
                ],
                [
                    ImportEntityRepository::IMPORTS_TABLE_NAME,
                    [
                        'parent_import_id' => 40,
                        'staging_import_id' => 31,
                        'cron_script_id'   => 15,
                        'company_id'       => 2,
                        'entity_type'      => 'products',
                        'source_type'      => ImportEntityRepository::SOURCE_TYPE_FULL,
                        'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_NO,
                        'process_group'    => 2,
                        'process_stage'    => EntityApplicationPlanBuilder::STAGE_APPLY,
                        'status'           => ImportEntityRepository::STATUS_QUEUED,
                        'page_from'        => 31,
                        'page_to'          => 35,
                        'current_page'     => 0,
                        'page_limit'       => 0,
                        'processed_items'  => 0,
                        'error_message'    => '',
                        'created_at'       => TIME,
                        'started_at'       => 0,
                        'updated_at'       => TIME,
                        'completed_at'     => 0,
                    ],
                ]
            )
            ->willReturnOnConsecutiveCalls(40, 41);

        $parent_id = (new ImportEntityRepository($database))->createImportHierarchy(
            2,
            'products',
            15,
            [
                'staging_import_id'      => 31,
                'page_limit'             => 0,
                'total_items'            => 35,
                'total_pages'            => 0,
                'max_parallel_processes' => 3,
                'source_type'            => ImportEntityRepository::SOURCE_TYPE_FULL,
                'ranges'                 => [[
                    'page_from'     => 31,
                    'page_to'       => 35,
                    'process_group' => 2,
                    'process_stage' => EntityApplicationPlanBuilder::STAGE_APPLY,
                ]],
            ]
        );

        $this->assertSame(40, $parent_id);
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

    public function testFindImportNormalizesIntegerColumns()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->with(
                'SELECT * FROM ?:?p WHERE import_id = ?i',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                55
            )
            ->willReturn([
                'import_id'         => '55',
                'parent_import_id'  => '40',
                'staging_import_id' => '31',
                'company_id'        => '2',
                'process_group'     => '1',
                'processed_items'   => '30',
                'entity_type'       => 'products',
                'status'            => ImportEntityRepository::STATUS_PROCESSING,
            ]);

        $this->assertSame(
            [
                'import_id'         => 55,
                'parent_import_id'  => 40,
                'staging_import_id' => 31,
                'company_id'        => 2,
                'process_group'     => 1,
                'processed_items'   => 30,
                'entity_type'       => 'products',
                'status'            => ImportEntityRepository::STATUS_PROCESSING,
            ],
            (new ImportEntityRepository($database))->findImport(55)
        );
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

    public function testFindsChildrenInProcessOrder()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getArray')
            ->with(
                'SELECT * FROM ?:?p WHERE parent_import_id = ?i'
                . ' ORDER BY process_group, page_from, import_id',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                40
            )
            ->willReturn([]);

        $this->assertSame([], (new ImportEntityRepository($database))->findChildren(40));
    }

    public function testCountsDistinctEntitiesFromSeveralImports()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getField')
            ->with(
                'SELECT COUNT(DISTINCT entity_id) FROM ?:?p'
                . ' WHERE import_id IN (?n) AND entity_type = ?s',
                ImportEntityRepository::TABLE_NAME,
                [41, 42],
                ProductDto::ENTITY_TYPE
            )
            ->willReturn('2');

        $this->assertSame(
            2,
            (new ImportEntityRepository($database))->countDistinctEntities([41, 42], ProductDto::ENTITY_TYPE)
        );
    }

    public function testFindsEntityRangeByStableExternalIdOrder()
    {
        $first_product = new ProductDto();
        $first_product->id = 77;
        $second_product = new ProductDto();
        $second_product->id = 88;
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getColumn')
            ->with(
                'SELECT entities.entity FROM ?:?p AS entities'
                . ' INNER JOIN ('
                . ' SELECT entity_id, MAX(import_id) AS import_id FROM ?:?p'
                . ' WHERE import_id IN (?n) AND entity_type = ?s GROUP BY entity_id'
                . ' ) AS latest ON latest.import_id = entities.import_id'
                . ' AND latest.entity_id = entities.entity_id'
                . ' WHERE entities.entity_type = ?s ORDER BY entities.entity_id LIMIT ?i, ?i',
                ImportEntityRepository::TABLE_NAME,
                ImportEntityRepository::TABLE_NAME,
                [41, 42],
                ProductDto::ENTITY_TYPE,
                ProductDto::ENTITY_TYPE,
                30,
                30
            )
            ->willReturn([serialize($first_product), serialize($second_product)]);

        $products = (new ImportEntityRepository($database))->findEntityRange(
            [41, 42],
            ProductDto::ENTITY_TYPE,
            31,
            60
        );

        $this->assertSame([77, 88], [$products[0]->id, $products[1]->id]);
    }

    public function testFindsCategoryCountsByTreeLevel()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getArray')
            ->with(
                'SELECT application_level, COUNT(*) AS entity_count FROM ?:?p'
                . ' WHERE import_id = ?i AND entity_type = ?s'
                . ' GROUP BY application_level ORDER BY application_level',
                ImportEntityRepository::TABLE_NAME,
                50,
                CategoryDto::ENTITY_TYPE
            )
            ->willReturn([
                ['application_level' => '0', 'entity_count' => '10'],
                ['application_level' => '2', 'entity_count' => '35'],
            ]);

        $this->assertSame(
            [0 => 10, 2 => 35],
            (new ImportEntityRepository($database))->findCategoryLevelCounts(50)
        );
    }

    public function testFindsCategoryBatchInsideOneTreeLevel()
    {
        $first_category = new CategoryDto();
        $first_category->id = 10;
        $second_category = new CategoryDto();
        $second_category->id = 20;
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getColumn')
            ->with(
                'SELECT entity FROM ?:?p'
                . ' WHERE import_id = ?i AND entity_type = ?s AND application_level = ?i'
                . ' ORDER BY entity_id LIMIT ?i, ?i',
                ImportEntityRepository::TABLE_NAME,
                50,
                CategoryDto::ENTITY_TYPE,
                2,
                30,
                30
            )
            ->willReturn([serialize($first_category), serialize($second_category)]);

        $categories = (new ImportEntityRepository($database))->findCategoryLevelBatch(50, 2, 30, 30);

        $this->assertSame([10, 20], [$categories[0]->id, $categories[1]->id]);
    }

    public function testStoresProcessedEntityCount()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET processed_items = ?i, updated_at = ?i WHERE import_id = ?i',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                27,
                TIME,
                81
            )
            ->willReturn('1');

        $this->assertTrue((new ImportEntityRepository($database))->updateProcessedItems(81, 27));
    }

    public function testCancelsQueuedChildrenAfterFailedGroup()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i'
                . ' WHERE parent_import_id = ?i AND process_group > ?i AND status = ?s',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                ImportEntityRepository::STATUS_CANCELLED,
                TIME,
                TIME,
                70,
                1,
                ImportEntityRepository::STATUS_QUEUED
            )
            ->willReturn('3');

        $this->assertSame(
            3,
            (new ImportEntityRepository($database))->cancelChildrenAfterGroup(70, 1)
        );
    }

    public function testRetriesOnlyCancelledChildrenAfterRecoveredGroup()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET ?u'
                . ' WHERE parent_import_id = ?i AND process_group > ?i AND status = ?s',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                [
                    'status'          => ImportEntityRepository::STATUS_QUEUED,
                    'current_page'    => 0,
                    'processed_items' => 0,
                    'error_message'   => '',
                    'started_at'      => 0,
                    'updated_at'      => TIME,
                    'completed_at'    => 0,
                ],
                70,
                1,
                ImportEntityRepository::STATUS_CANCELLED
            )
            ->willReturn('2');

        $this->assertSame(
            2,
            (new ImportEntityRepository($database))->retryCancelledChildrenAfterGroup(70, 1)
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

    public function testPersistsCategoryTreeLevels()
    {
        $categories = [];
        foreach ([10 => 0, 20 => 1, 30 => 2, 40 => 0] as $id => $level) {
            $category = new CategoryDto();
            $category->id = $id;
            $category->level = $level;
            $categories[] = $category;
        }
        $database = $this->createDatabase();
        $database->expects($this->once())->method('replaceInto')
            ->with(
                ImportEntityRepository::TABLE_NAME,
                $this->callback(static function (array $records) {
                    return array_column($records, 'application_level') === [0, 1, 2, 0]
                        && !array_key_exists('application_position', $records[0]);
                }),
                true,
                ['entity', 'application_level', 'updated_at']
            )
            ->willReturn(4);

        $this->assertSame(
            4,
            (new ImportEntityRepository($database))->batchSaveCategories(41, 1, $categories)
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
