<?php

namespace Tygh\Addons\Synchro;

if (!function_exists(__NAMESPACE__ . '\\__')) {
    function __($language_variable, array $params = [])
    {
        return $language_variable . ($params ? json_encode($params) : '');
    }
}

namespace Tygh\Addons\Synchro\Tests\Unit;

defined('DESCR_SL') or define('DESCR_SL', 'en');
defined('SECONDS_IN_DAY') or define('SECONDS_IN_DAY', 86400);
defined('TIME') or define('TIME', time());

use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\Application\EntityApplicationPlanBuilder;
use Tygh\Addons\Synchro\Application\ProductApplicationManager;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\ImportProcessManager;
use Tygh\Addons\Synchro\ProductImportRangeBuilder;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository;
use Tygh\Lock\Factory;
use Tygh\Lock\Lock;
use Tygh\Tests\Unit\ATestCase;

class ImportProcessManagerTest extends ATestCase
{
    public function testCreatesParentWithCalculatedChildren()
    {
        $repository = new InMemoryImportEntityRepository();
        $manager = $this->createManager($repository);

        $parent_id = $manager->createProductImport([
            'script_id'                => 15,
            'use_portions'             => 'Y',
            'pages_per_portion'        => 2,
            'page_limit'               => 100,
            'max_parallel_processes'   => 2,
            'is_test_import'           => 'N',
            'test_page'                => 1,
        ], 4, 450);

        $this->assertSame(1, $parent_id);
        $this->assertSame(450, $repository->imports[1]['total_items']);
        $this->assertSame(5, $repository->imports[1]['total_pages']);
        $this->assertSame(ImportEntityRepository::SOURCE_TYPE_FULL, $repository->imports[1]['source_type']);
        $this->assertSame(2, $repository->imports[1]['max_parallel_processes']);
        $this->assertSame([1, 3, 5], array_column($repository->findChildren(1), 'page_from'));
        $this->assertSame([2, 4, 5], array_column($repository->findChildren(1), 'page_to'));
    }

    public function testMarksTestProductImportSource()
    {
        $repository = new InMemoryImportEntityRepository();
        $manager = $this->createManager($repository);

        $parent_id = $manager->createProductImport([
            'script_id'      => 15,
            'is_test_import' => 'Y',
            'test_page'      => 37,
        ], 4, 10);

        $this->assertSame(ImportEntityRepository::SOURCE_TYPE_TEST, $repository->imports[$parent_id]['source_type']);
        $children = $repository->findChildren($parent_id);
        $this->assertSame(ImportEntityRepository::SOURCE_TYPE_TEST, $children[0]['source_type']);
    }

    public function testCollectsFeaturesForFullAndTestProductImports()
    {
        $repository = new InMemoryImportEntityRepository();
        $manager = $this->createManager($repository);

        $full_import_id = $manager->createProductImport([
            'script_id' => 15,
        ], 4, 10);
        $test_import_id = $manager->createProductImport([
            'script_id'      => 16,
            'is_test_import' => 'Y',
        ], 4, 10);

        $this->assertSame('Y', $repository->imports[$full_import_id]['collect_product_features']);
        $this->assertSame(
            'Y',
            $repository->findChildren($full_import_id)[0]['collect_product_features']
        );
        $this->assertSame('Y', $repository->imports[$test_import_id]['collect_product_features']);
        $this->assertSame(
            'Y',
            $repository->findChildren($test_import_id)[0]['collect_product_features']
        );
    }

    public function testSkipsFeaturesOnlyForProductActualization()
    {
        $repository = new InMemoryImportEntityRepository();
        $manager = $this->createManager($repository);

        $import_id = $manager->createProductImport([
            'script_id'    => 15,
            'post_process' => CronManager::POST_PROCESS_ACTUALIZE_PRODUCTS,
        ], 4, 10);

        $this->assertSame('N', $repository->imports[$import_id]['collect_product_features']);
        $this->assertSame(
            'N',
            $repository->findChildren($import_id)[0]['collect_product_features']
        );
    }

    public function testCreatesProductApplicationHierarchy()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            50 => [
                'import_id'        => 50,
                'parent_import_id' => 0,
                'company_id'       => 4,
                'entity_type'      => ImportDataCommand::ENTITY_PRODUCTS,
                'source_type'      => ImportEntityRepository::SOURCE_TYPE_FULL,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
            ],
            51 => [
                'import_id'        => 51,
                'parent_import_id' => 50,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 1,
            ],
            52 => [
                'import_id'        => 52,
                'parent_import_id' => 50,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 2,
            ],
        ];
        $repository->distinct_entity_count = 65;

        $parent_id = $this->createManager($repository)->createProductApplication([
            'script_id'              => 16,
            'entities_per_portion'   => 20,
            'max_parallel_processes' => 3,
        ], 50, ProductApplicationManager::MODE_FULL);

        $this->assertSame(1, $parent_id);
        $this->assertSame(50, $repository->imports[$parent_id]['staging_import_id']);
        $this->assertSame(65, $repository->imports[$parent_id]['total_items']);
        $this->assertSame(3, $repository->imports[$parent_id]['max_parallel_processes']);
        $children = $repository->findChildren($parent_id);
        $this->assertSame(
            ['prepare', 'apply', 'apply', 'apply', 'apply', 'finalize'],
            array_column($children, 'process_stage')
        );
        $this->assertSame([0, 1, 1, 1, 1, 2], array_column($children, 'process_group'));
        $this->assertSame([50, 50, 50, 50, 50, 50], array_column($children, 'staging_import_id'));
        $this->assertSame([0, 1, 21, 41, 61, 0], array_column($children, 'page_from'));
        $this->assertSame([0, 20, 40, 60, 65, 0], array_column($children, 'page_to'));
    }

    public function testCreatesCategoryApplicationHierarchyByTreeLevels()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports[50] = [
            'import_id'        => 50,
            'parent_import_id' => 0,
            'company_id'       => 4,
            'entity_type'      => ImportDataCommand::ENTITY_CATEGORIES,
            'source_type'      => ImportEntityRepository::SOURCE_TYPE_FULL,
            'status'           => ImportEntityRepository::STATUS_COMPLETED,
        ];
        $repository->category_level_counts = [0 => 10, 1 => 35];

        $parent_id = $this->createManager($repository)->createCategoryApplication([
            'script_id'              => 16,
            'entities_per_portion'   => 30,
            'max_parallel_processes' => 4,
        ], 50);

        $this->assertSame(1, $parent_id);
        $this->assertSame(45, $repository->imports[$parent_id]['total_items']);
        $this->assertSame(4, $repository->imports[$parent_id]['max_parallel_processes']);
        $children = $repository->findChildren($parent_id);
        $this->assertSame(['apply', 'apply', 'apply', 'finalize'], array_column($children, 'process_stage'));
        $this->assertSame([0, 1, 1, 2], array_column($children, 'process_group'));
        $this->assertSame([1, 1, 31, 0], array_column($children, 'page_from'));
        $this->assertSame([10, 30, 35, 0], array_column($children, 'page_to'));
    }

    public function testDispatchesOnlyAvailableSlots()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'               => 10,
                'parent_import_id'        => 0,
                'status'                  => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes'  => 3,
            ],
            54 => [
                'import_id'        => 54,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'        => 1,
            ],
            55 => [
                'import_id'        => 55,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 2,
            ],
            56 => [
                'import_id'        => 56,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 3,
            ],
            57 => [
                'import_id'        => 57,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 4,
            ],
        ];
        $commands = [];
        $manager = $this->createManager(
            $repository,
            static function ($command) use (&$commands) {
                $commands[] = $command;

                return true;
            }
        );

        $this->assertSame(2, $manager->dispatchPending(10));
        $this->assertCount(2, $commands);
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[55]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[56]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_QUEUED, $repository->imports[57]['status']);
    }

    public function testDoesNotCrossProcessGroupBarrier()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 2,
            ],
            71 => [
                'import_id'        => 71,
                'parent_import_id' => 10,
                'process_group'    => 0,
                'status'           => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'        => 1,
            ],
            72 => [
                'import_id'        => 72,
                'parent_import_id' => 10,
                'process_group'    => 1,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 1,
            ],
            73 => [
                'import_id'        => 73,
                'parent_import_id' => 10,
                'process_group'    => 1,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 2,
            ],
        ];
        $manager = $this->createManager($repository, static function () {
            return true;
        });

        $this->assertSame(0, $manager->dispatchPending(10));
        $repository->imports[71]['status'] = ImportEntityRepository::STATUS_COMPLETED;
        $this->assertSame(2, $manager->dispatchPending(10));
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[72]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[73]['status']);
    }

    public function testFailureCancelsOnlyLaterProcessGroups()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 2,
            ],
            71 => [
                'import_id'        => 71,
                'parent_import_id' => 10,
                'process_group'    => 1,
                'status'           => ImportEntityRepository::STATUS_FAILED,
                'page_from'        => 1,
            ],
            72 => [
                'import_id'        => 72,
                'parent_import_id' => 10,
                'process_group'    => 1,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 2,
            ],
            73 => [
                'import_id'        => 73,
                'parent_import_id' => 10,
                'process_group'    => 2,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 1,
            ],
        ];
        $manager = $this->createManager($repository, static function () {
            return true;
        });

        $this->assertSame(0, $manager->dispatchPending(10));
        $this->assertSame(ImportEntityRepository::STATUS_FAILED, $repository->imports[71]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[72]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_CANCELLED, $repository->imports[73]['status']);
    }

    public function testDoesNotLaunchChildWhenAtomicClaimFails()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'               => 10,
                'parent_import_id'        => 0,
                'status'                  => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes'  => 1,
            ],
            55 => [
                'import_id'        => 55,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 1,
            ],
        ];
        $repository->claim_result = false;
        $launch_count = 0;
        $manager = $this->createManager(
            $repository,
            static function () use (&$launch_count) {
                $launch_count++;

                return true;
            }
        );

        $this->assertSame(0, $manager->dispatchPending(10));
        $this->assertSame(0, $launch_count);
    }

    public function testPreparesChildBackgroundCommand()
    {
        $manager = $this->createManager(new InMemoryImportEntityRepository());

        $this->assertSame(
            "'/usr/bin/php' '/store/admin.php' '--dispatch=synchro_import.product_process'"
            . " '--import_id=55' '--cron_password=secret' > /dev/null 2>&1 &",
            $manager->prepareBackgroundCommand(55)
        );
    }

    public function testSelectsWorkerDispatchFromProcessData()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            55 => [
                'import_id'         => 55,
                'staging_import_id' => 0,
                'entity_type'       => ImportDataCommand::ENTITY_PRODUCTS,
            ],
            56 => [
                'import_id'         => 56,
                'staging_import_id' => 50,
                'entity_type'       => ImportDataCommand::ENTITY_PRODUCTS,
            ],
            57 => [
                'import_id'         => 57,
                'staging_import_id' => 50,
                'entity_type'       => ImportDataCommand::ENTITY_CATEGORIES,
            ],
        ];
        $manager = $this->createManager($repository);

        $this->assertStringContainsString('synchro_import.product_process', $manager->prepareBackgroundCommand(55));
        $this->assertStringContainsString(
            'synchro_import.product_application_process',
            $manager->prepareBackgroundCommand(56)
        );
        $this->assertStringContainsString(
            'synchro_import.category_application_process',
            $manager->prepareBackgroundCommand(57)
        );
    }

    public function testReconcilesPartialParentAfterAllChildrenFinish()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'               => 10,
                'parent_import_id'        => 0,
                'cron_script_id'          => 15,
                'status'                  => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes'  => 2,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 1,
            ],
            12 => [
                'import_id'        => 12,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_FAILED,
                'page_from'        => 2,
            ],
        ];
        $cron_manager = $this->getMockBuilder(CronManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['finalizeDeferredTask', 'queuePostProcess'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('finalizeDeferredTask')
            ->with(15, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
            ->willReturn(true);
        $cron_manager->expects($this->once())
            ->method('queuePostProcess')
            ->with(15, 10, ImportEntityRepository::SOURCE_TYPE_FULL, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
            ->willReturn(true);
        $manager = $this->createManager($repository, null, $cron_manager);

        $this->assertSame(
            ImportEntityRepository::STATUS_PARTIAL_SUCCESS,
            $manager->reconcileParent(10)
        );
        $this->assertSame(
            ImportEntityRepository::STATUS_PARTIAL_SUCCESS,
            $repository->imports[10]['status']
        );
    }

    public function testStoppingProcessCannotContinue()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports[55] = [
            'import_id'        => 55,
            'parent_import_id' => 10,
            'status'           => ImportEntityRepository::STATUS_STOPPING,
        ];
        $manager = $this->createManager($repository);

        $this->expectException(TaskInterruptedException::class);
        $manager->ensureProcessCanContinue(55);
    }

    public function testCompletesChildAndStartsNextQueuedChild()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 1,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'        => 1,
            ],
            12 => [
                'import_id'        => 12,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 2,
            ],
        ];
        $commands = [];
        $manager = $this->createManager(
            $repository,
            static function ($command) use (&$commands) {
                $commands[] = $command;

                return true;
            }
        );

        $manager->completeProcess(11);

        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[11]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[12]['status']);
        $this->assertCount(1, $commands);
    }

    public function testCompletingFeatureCollectionChildPublishesItsSnapshotBeforeContinuingParent()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 1,
            ],
            11 => [
                'import_id'                => 11,
                'parent_import_id'         => 10,
                'entity_type'              => ImportDataCommand::ENTITY_PRODUCTS,
                'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES,
                'status'                   => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'                => 1,
            ],
        ];
        $snapshot_repository = new InMemoryProductFeatureSnapshotRepository($repository);
        $manager = $this->createManager($repository, null, null, $snapshot_repository);

        $manager->completeProcess(11);

        $this->assertSame([[11, 10]], $snapshot_repository->merged_portions);
        $this->assertSame(0, $repository->complete_child_calls);
        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[11]['status']);
    }

    public function testCompletingActualizationChildDoesNotPublishAFeatureSnapshot()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 1,
            ],
            11 => [
                'import_id'                => 11,
                'parent_import_id'         => 10,
                'entity_type'              => ImportDataCommand::ENTITY_PRODUCTS,
                'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_NO,
                'status'                   => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'                => 1,
            ],
        ];
        $snapshot_repository = new InMemoryProductFeatureSnapshotRepository($repository);
        $manager = $this->createManager($repository, null, null, $snapshot_repository);

        $manager->completeProcess(11);

        $this->assertSame([], $snapshot_repository->merged_portions);
        $this->assertSame(1, $repository->complete_child_calls);
    }

    public function testFailingFeatureCollectionProcessRemovesChildAndUnpublishedRootSnapshots()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'                => 10,
                'parent_import_id'         => 0,
                'cron_script_id'           => 15,
                'entity_type'              => ImportDataCommand::ENTITY_PRODUCTS,
                'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES,
                'status'                   => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes'   => 1,
            ],
            11 => [
                'import_id'                => 11,
                'parent_import_id'         => 10,
                'entity_type'              => ImportDataCommand::ENTITY_PRODUCTS,
                'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES,
                'status'                   => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'                => 1,
            ],
        ];
        $snapshot_repository = new InMemoryProductFeatureSnapshotRepository($repository);

        $this->createManager($repository, null, null, $snapshot_repository)->failProcess(11, 'API failed');

        $this->assertSame([[11], [10, 11]], $snapshot_repository->deleted_import_ids);
    }

    public function testCompletedApplicationRemovesSnapshotSupersededWhileItWasActive()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'         => 10,
                'parent_import_id'  => 0,
                'staging_import_id' => 50,
                'cron_script_id'    => 15,
                'company_id'        => 4,
                'entity_type'       => ImportDataCommand::ENTITY_PRODUCTS,
                'source_type'       => ImportEntityRepository::SOURCE_TYPE_FULL,
                'status'            => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 1,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 0,
            ],
            50 => [
                'import_id'                => 50,
                'parent_import_id'         => 0,
                'company_id'               => 4,
                'entity_type'              => ImportDataCommand::ENTITY_PRODUCTS,
                'source_type'              => ImportEntityRepository::SOURCE_TYPE_FULL,
                'collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES,
                'status'                   => ImportEntityRepository::STATUS_COMPLETED,
            ],
        ];
        $snapshot_repository = new InMemoryProductFeatureSnapshotRepository($repository);
        $snapshot_repository->latest_snapshot_id = 70;
        $snapshot_repository->superseded_snapshot_ids = [50];
        $cron_manager = $this->getMockBuilder(CronManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['finalizeDeferredTask', 'queuePostProcess'])
            ->getMock();
        $cron_manager->method('finalizeDeferredTask')->willReturn(false);

        $this->createManager($repository, null, $cron_manager, $snapshot_repository)->reconcileParent(10);

        $this->assertSame([[4, ImportEntityRepository::SOURCE_TYPE_FULL]], $snapshot_repository->latest_snapshot_calls);
        $this->assertSame([[4, ImportEntityRepository::SOURCE_TYPE_FULL, 70]], $snapshot_repository->superseded_snapshot_calls);
        $this->assertSame([[50]], $snapshot_repository->deleted_import_ids);
    }

    public function testCompletingApplicationChildUpdatesAggregateProgress()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 1,
                'total_items'            => 5,
            ],
            11 => [
                'import_id'         => 11,
                'parent_import_id'  => 10,
                'cron_script_id'    => 15,
                'staging_import_id' => 50,
                'entity_type'       => ImportDataCommand::ENTITY_PRODUCTS,
                'process_group'     => 0,
                'process_stage'     => EntityApplicationPlanBuilder::STAGE_APPLY,
                'processed_items'   => 2,
                'status'            => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'         => 1,
            ],
            12 => [
                'import_id'         => 12,
                'parent_import_id'  => 10,
                'cron_script_id'    => 15,
                'staging_import_id' => 50,
                'entity_type'       => ImportDataCommand::ENTITY_PRODUCTS,
                'process_group'     => 1,
                'process_stage'     => EntityApplicationPlanBuilder::STAGE_FINALIZE,
                'processed_items'   => 0,
                'status'            => ImportEntityRepository::STATUS_QUEUED,
                'page_from'         => 0,
            ],
        ];
        $cron_manager = $this->getMockBuilder(CronManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['updateProgressStatus'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('updateProgressStatus')
            ->with(15, $this->callback(static function ($status) {
                return strpos($status, '2') !== false && strpos($status, '5') !== false;
            }))
            ->willReturn(true);
        $manager = $this->createManager($repository, static function () {
            return true;
        }, $cron_manager);

        $manager->completeProcess(11);

        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[11]['status']);
    }

    public function testCancelsInterruptedChildWithoutRemovingOtherChildren()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_STOPPING,
                'max_parallel_processes' => 1,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_STOPPING,
                'page_from'        => 1,
            ],
            12 => [
                'import_id'        => 12,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 2,
            ],
        ];
        $cron_manager = $this->getMockBuilder(CronManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['finalizeDeferredTask', 'queuePostProcess'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('finalizeDeferredTask')
            ->with(15, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
            ->willReturn(true);
        $cron_manager->expects($this->once())
            ->method('queuePostProcess')
            ->with(15, 10, ImportEntityRepository::SOURCE_TYPE_FULL, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
            ->willReturn(true);
        $manager = $this->createManager($repository, null, $cron_manager);

        $manager->cancelProcess(11);

        $this->assertSame(ImportEntityRepository::STATUS_CANCELLED, $repository->imports[11]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[12]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PARTIAL_SUCCESS, $repository->imports[10]['status']);
    }

    public function testRetryReopensSelectedGroupAndBlockedLaterGroups()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PARTIAL_SUCCESS,
                'max_parallel_processes' => 1,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'cron_script_id'   => 15,
                'status'           => ImportEntityRepository::STATUS_FAILED,
                'process_group'    => 1,
                'page_from'        => 1,
            ],
            12 => [
                'import_id'        => 12,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_FAILED,
                'process_group'    => 1,
                'page_from'        => 2,
            ],
            13 => [
                'import_id'        => 13,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_CANCELLED,
                'process_group'    => 2,
                'page_from'        => 1,
            ],
        ];
        $cron_manager = $this->getMockBuilder(CronManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['reopenTaskForChildren'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('reopenTaskForChildren')
            ->with(15)
            ->willReturn(true);
        $manager = $this->createManager($repository, static function () {
            return true;
        }, $cron_manager);

        $this->assertTrue($manager->retryProcess(11));
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[10]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[11]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_FAILED, $repository->imports[12]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_QUEUED, $repository->imports[13]['status']);
    }

    public function testParentInterruptionCascadesToItsChildren()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 2,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'        => 1,
            ],
            12 => [
                'import_id'        => 12,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_QUEUED,
                'page_from'        => 2,
            ],
        ];
        $manager = $this->createManager($repository);

        $this->assertTrue($manager->requestParentInterruption(15));
        $this->assertSame(ImportEntityRepository::STATUS_STOPPING, $repository->imports[10]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_STOPPING, $repository->imports[11]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_CANCELLED, $repository->imports[12]['status']);
    }

    public function testRecoveryFailsStaleChildAndReconcilesParent()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PROCESSING,
                'max_parallel_processes' => 1,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_PROCESSING,
                'page_from'        => 1,
            ],
            12 => [
                'import_id'        => 12,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 2,
            ],
        ];
        $repository->stale_import_ids = [11];
        $cron_manager = $this->getMockBuilder(CronManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['finalizeDeferredTask', 'queuePostProcess'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('finalizeDeferredTask')
            ->with(15, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
            ->willReturn(true);
        $cron_manager->expects($this->once())
            ->method('queuePostProcess')
            ->with(15, 10, ImportEntityRepository::SOURCE_TYPE_FULL, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
            ->willReturn(true);
        $manager = $this->createManager($repository, null, $cron_manager);

        $this->assertSame(1, $manager->recoverStaleProcesses());
        $this->assertSame(ImportEntityRepository::STATUS_FAILED, $repository->imports[11]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PARTIAL_SUCCESS, $repository->imports[10]['status']);
    }

    public function testParentRetryQueuesOnlyUnsuccessfulChildren()
    {
        $repository = new InMemoryImportEntityRepository();
        $repository->imports = [
            10 => [
                'import_id'              => 10,
                'parent_import_id'       => 0,
                'cron_script_id'         => 15,
                'status'                 => ImportEntityRepository::STATUS_PARTIAL_SUCCESS,
                'max_parallel_processes' => 2,
            ],
            11 => [
                'import_id'        => 11,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_COMPLETED,
                'page_from'        => 1,
            ],
            12 => [
                'import_id'        => 12,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_FAILED,
                'process_group'    => 1,
                'page_from'        => 2,
            ],
            13 => [
                'import_id'        => 13,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_CANCELLED,
                'process_group'    => 2,
                'page_from'        => 3,
            ],
        ];
        $cron_manager = $this->getMockBuilder(CronManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['reopenTaskForChildren'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('reopenTaskForChildren')
            ->with(15)
            ->willReturn(true);
        $manager = $this->createManager($repository, static function () {
            return true;
        }, $cron_manager);

        $this->assertTrue($manager->retryFailedProcesses(15));
        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[11]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[12]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_QUEUED, $repository->imports[13]['status']);
    }

    /**
     * @param \Tygh\Addons\Synchro\Tests\Unit\InMemoryImportEntityRepository $repository      Repository
     * @param callable|null                                                    $process_launcher Process launcher
     * @param \Tygh\Addons\Synchro\CronManager|null                           $cron_manager     Cron manager
     *
     * @return \Tygh\Addons\Synchro\ImportProcessManager
     */
    private function createManager(
        InMemoryImportEntityRepository $repository,
        callable $process_launcher = null,
        CronManager $cron_manager = null,
        ProductFeatureSnapshotRepository $snapshot_repository = null
    )
    {
        $lock = $this->getMockBuilder(Lock::class)
            ->disableOriginalConstructor()
            ->setMethods(['acquire', 'release'])
            ->getMock();
        $lock->method('acquire')->willReturn(true);
        $lock_factory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->setMethods(['createLock'])
            ->getMock();
        $lock_factory->method('createLock')->willReturn($lock);
        if ($cron_manager === null) {
            $cron_manager = $this->getMockBuilder(CronManager::class)
                ->disableOriginalConstructor()
                ->getMock();
        }
        if ($snapshot_repository === null) {
            $snapshot_repository = new InMemoryProductFeatureSnapshotRepository($repository);
        }

        return new ImportProcessManager(
            $repository,
            $snapshot_repository,
            new ProductImportRangeBuilder(),
            new EntityApplicationPlanBuilder(),
            $cron_manager,
            $lock_factory,
            '/store',
            'admin.php',
            'secret',
            '/usr/bin/php',
            $process_launcher
        );
    }
}

class InMemoryImportEntityRepository extends ImportEntityRepository
{
    /** @var array<int, array<string, int|string>> */
    public $imports = [];

    /** @var bool */
    public $claim_result = true;

    /** @var array<int> */
    public $stale_import_ids = [];

    /** @var int */
    public $distinct_entity_count = 0;

    /** @var array<int, int> */
    public $category_level_counts = [];

    /** @var int */
    public $complete_child_calls = 0;

    /** @var int */
    private $next_id = 1;

    public function __construct()
    {
    }

    public function createParentImport($company_id, $entity_type, $cron_script_id, array $plan)
    {
        $import_id = $this->next_id++;
        $this->imports[$import_id] = array_merge($plan, [
            'import_id'        => $import_id,
            'parent_import_id' => 0,
            'cron_script_id'   => $cron_script_id,
            'company_id'       => $company_id,
            'entity_type'      => $entity_type,
            'status'           => self::STATUS_PROCESSING,
        ]);

        return $import_id;
    }

    public function createImportHierarchy($company_id, $entity_type, $cron_script_id, array $plan)
    {
        $parent_import_id = $this->createParentImport($company_id, $entity_type, $cron_script_id, $plan);
        $ranges = [];
        foreach ($plan['ranges'] as $range) {
            $range['staging_import_id'] = isset($plan['staging_import_id'])
                ? (int) $plan['staging_import_id']
                : 0;
            $ranges[] = $range;
        }
        $this->createChildImports(
            $parent_import_id,
            $company_id,
            $entity_type,
            $cron_script_id,
            $ranges,
            $plan['page_limit'],
            $plan['source_type'],
            isset($plan['collect_product_features'])
                ? $plan['collect_product_features']
                : self::COLLECT_PRODUCT_FEATURES_NO
        );

        return $parent_import_id;
    }

    public function createChildImports(
        $parent_import_id,
        $company_id,
        $entity_type,
        $cron_script_id,
        array $ranges,
        $page_limit,
        $source_type = self::SOURCE_TYPE_FULL,
        $collect_product_features = self::COLLECT_PRODUCT_FEATURES_NO
    ) {
        $import_ids = [];

        foreach ($ranges as $range) {
            $import_id = $this->next_id++;
            $this->imports[$import_id] = array_merge($range, [
                'import_id'        => $import_id,
                'parent_import_id' => $parent_import_id,
                'cron_script_id'   => $cron_script_id,
                'company_id'       => $company_id,
                'entity_type'      => $entity_type,
                'source_type'      => $source_type,
                'collect_product_features' => $collect_product_features,
                'status'           => self::STATUS_QUEUED,
                'page_limit'       => $page_limit,
                'processed_items'  => 0,
            ]);
            $import_ids[] = $import_id;
        }

        return $import_ids;
    }

    public function findImport($import_id)
    {
        return isset($this->imports[$import_id]) ? $this->imports[$import_id] : [];
    }

    public function findChildren($parent_import_id)
    {
        $children = array_filter($this->imports, static function (array $import) use ($parent_import_id) {
            return $import['parent_import_id'] === $parent_import_id;
        });
        usort($children, static function (array $left, array $right) {
            $group_comparison = (isset($left['process_group']) ? $left['process_group'] : 0)
                <=> (isset($right['process_group']) ? $right['process_group'] : 0);

            return $group_comparison ?: $left['page_from'] <=> $right['page_from'];
        });

        return $children;
    }

    public function countRunningChildren($parent_import_id)
    {
        return count(array_filter($this->findChildren($parent_import_id), static function (array $import) {
            return in_array($import['status'], [self::STATUS_PROCESSING, self::STATUS_STOPPING], true);
        }));
    }

    public function findQueuedChildren($parent_import_id, $limit)
    {
        $children = array_filter($this->findChildren($parent_import_id), static function (array $import) {
            return $import['status'] === self::STATUS_QUEUED;
        });

        return array_slice($children, 0, $limit);
    }

    public function claimChild($import_id)
    {
        if (!$this->claim_result || $this->imports[$import_id]['status'] !== self::STATUS_QUEUED) {
            return false;
        }

        $this->imports[$import_id]['status'] = self::STATUS_PROCESSING;

        return true;
    }

    public function updateImportStatus($import_id, $status, $error = '')
    {
        $this->imports[$import_id]['status'] = $status;
        $this->imports[$import_id]['error_message'] = $error;

        return true;
    }

    public function findLatestParentByCronScriptId($cron_script_id)
    {
        $parents = array_filter($this->imports, static function (array $import) use ($cron_script_id) {
            return $import['parent_import_id'] === 0 && $import['cron_script_id'] === $cron_script_id;
        });

        return $parents ? end($parents) : [];
    }

    public function requestParentInterruption($parent_import_id)
    {
        $this->imports[$parent_import_id]['status'] = self::STATUS_STOPPING;
        foreach ($this->imports as &$import) {
            if ($import['parent_import_id'] !== $parent_import_id) {
                continue;
            }
            if ($import['status'] === self::STATUS_QUEUED) {
                $import['status'] = self::STATUS_CANCELLED;
            } elseif ($import['status'] === self::STATUS_PROCESSING) {
                $import['status'] = self::STATUS_STOPPING;
            }
        }
        unset($import);

        return true;
    }

    public function retryChild($import_id)
    {
        $this->imports[$import_id]['status'] = self::STATUS_QUEUED;

        return true;
    }

    public function cancelChildrenAfterGroup($parent_import_id, $process_group)
    {
        $count = 0;
        foreach ($this->imports as &$import) {
            if (
                $import['parent_import_id'] === $parent_import_id
                && isset($import['process_group'])
                && $import['process_group'] > $process_group
                && $import['status'] === self::STATUS_QUEUED
            ) {
                $import['status'] = self::STATUS_CANCELLED;
                $count++;
            }
        }
        unset($import);

        return $count;
    }

    public function retryCancelledChildrenAfterGroup($parent_import_id, $process_group)
    {
        $count = 0;
        foreach ($this->imports as &$import) {
            if (
                $import['parent_import_id'] === $parent_import_id
                && isset($import['process_group'])
                && $import['process_group'] > $process_group
                && $import['status'] === self::STATUS_CANCELLED
            ) {
                $import['status'] = self::STATUS_QUEUED;
                $count++;
            }
        }
        unset($import);

        return $count;
    }

    public function findCompletedChildIds($parent_import_id)
    {
        return array_map(static function (array $import) {
            return (int) $import['import_id'];
        }, array_filter($this->findChildren($parent_import_id), static function (array $import) {
            return $import['status'] === self::STATUS_COMPLETED;
        }));
    }

    public function countDistinctEntities(array $import_ids, $entity_type)
    {
        return $this->distinct_entity_count;
    }

    public function findCategoryLevelCounts($import_id)
    {
        return $this->category_level_counts;
    }

    public function retryChildren($parent_import_id)
    {
        $count = 0;
        foreach ($this->imports as &$import) {
            if (
                $import['parent_import_id'] === $parent_import_id
                && in_array($import['status'], [self::STATUS_FAILED, self::STATUS_CANCELLED], true)
            ) {
                $import['status'] = self::STATUS_QUEUED;
                $count++;
            }
        }
        unset($import);

        if ($count) {
            $this->imports[$parent_import_id]['status'] = self::STATUS_PROCESSING;
        }

        return $count;
    }

    public function findStaleChildren($updated_before)
    {
        return array_map(function ($import_id) {
            return $this->imports[$import_id];
        }, $this->stale_import_ids);
    }

    public function failChild($import_id, $error)
    {
        $this->imports[$import_id]['status'] = self::STATUS_FAILED;
        $this->imports[$import_id]['error_message'] = $error;
    }

    public function completeChild($import_id)
    {
        $this->complete_child_calls++;
        $this->imports[$import_id]['status'] = self::STATUS_COMPLETED;

        return true;
    }

    public function cancelChild($import_id)
    {
        $this->imports[$import_id]['status'] = self::STATUS_CANCELLED;

        return true;
    }
}

class InMemoryProductFeatureSnapshotRepository extends ProductFeatureSnapshotRepository
{
    /** @var \Tygh\Addons\Synchro\Tests\Unit\InMemoryImportEntityRepository */
    private $import_repository;

    /** @var array<array{int, int}> */
    public $merged_portions = [];

    /** @var array<array<int>> */
    public $deleted_import_ids = [];

    /** @var int */
    public $latest_snapshot_id = 0;

    /** @var array<int> */
    public $superseded_snapshot_ids = [];

    /** @var array<array{int, string}> */
    public $latest_snapshot_calls = [];

    /** @var array<array{int, string, int}> */
    public $superseded_snapshot_calls = [];

    /**
     * @param \Tygh\Addons\Synchro\Tests\Unit\InMemoryImportEntityRepository $import_repository Import repository
     */
    public function __construct(InMemoryImportEntityRepository $import_repository)
    {
        $this->import_repository = $import_repository;
    }

    /**
     * @param int $child_import_id  Child import identifier
     * @param int $parent_import_id Parent import identifier
     *
     * @return bool
     */
    public function mergeAndCompletePortion($child_import_id, $parent_import_id)
    {
        $this->merged_portions[] = [(int) $child_import_id, (int) $parent_import_id];
        $this->import_repository->imports[$child_import_id]['status'] = ImportEntityRepository::STATUS_COMPLETED;

        return true;
    }

    /**
     * @param array<array-key, int> $import_ids Import identifiers
     *
     * @return void
     */
    public function deleteByImportIds(array $import_ids)
    {
        $this->deleted_import_ids[] = array_values($import_ids);
    }

    /**
     * @return array<int>
     */
    public function findLatestSnapshotId($company_id, $source_type)
    {
        $this->latest_snapshot_calls[] = [(int) $company_id, (string) $source_type];

        return $this->latest_snapshot_id;
    }

    /**
     * @return array<int>
     */
    public function findSupersededSnapshotIds($company_id, $source_type, $current_import_id)
    {
        $this->superseded_snapshot_calls[] = [
            (int) $company_id,
            (string) $source_type,
            (int) $current_import_id,
        ];

        return $this->superseded_snapshot_ids;
    }
}
