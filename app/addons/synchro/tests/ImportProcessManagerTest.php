<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

defined('DESCR_SL') or define('DESCR_SL', 'en');

use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\ImportProcessManager;
use Tygh\Addons\Synchro\ProductImportRangeBuilder;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
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
            ->setMethods(['finalizeDeferredTask'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('finalizeDeferredTask')
            ->with(15, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
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
            ->setMethods(['finalizeDeferredTask'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('finalizeDeferredTask')
            ->with(15, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
            ->willReturn(true);
        $manager = $this->createManager($repository, null, $cron_manager);

        $manager->cancelProcess(11);

        $this->assertSame(ImportEntityRepository::STATUS_CANCELLED, $repository->imports[11]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[12]['status']);
        $this->assertSame(ImportEntityRepository::STATUS_PARTIAL_SUCCESS, $repository->imports[10]['status']);
    }

    public function testRetryReopensParentAndQueuesOnlySelectedChild()
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
        $this->assertSame(ImportEntityRepository::STATUS_COMPLETED, $repository->imports[12]['status']);
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
            ->setMethods(['finalizeDeferredTask'])
            ->getMock();
        $cron_manager->expects($this->once())
            ->method('finalizeDeferredTask')
            ->with(15, ImportEntityRepository::STATUS_PARTIAL_SUCCESS)
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
                'page_from'        => 2,
            ],
            13 => [
                'import_id'        => 13,
                'parent_import_id' => 10,
                'status'           => ImportEntityRepository::STATUS_CANCELLED,
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
        $this->assertSame(ImportEntityRepository::STATUS_PROCESSING, $repository->imports[13]['status']);
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
        CronManager $cron_manager = null
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

        return new ImportProcessManager(
            $repository,
            new ProductImportRangeBuilder(),
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
        $this->createChildImports(
            $parent_import_id,
            $company_id,
            $entity_type,
            $cron_script_id,
            $plan['ranges'],
            $plan['page_limit'],
            $plan['source_type']
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
        $source_type = self::SOURCE_TYPE_FULL
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
                'status'           => self::STATUS_QUEUED,
                'page_limit'       => $page_limit,
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
            return $left['page_from'] <=> $right['page_from'];
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
        $this->imports[$import_id]['status'] = self::STATUS_COMPLETED;

        return true;
    }

    public function cancelChild($import_id)
    {
        $this->imports[$import_id]['status'] = self::STATUS_CANCELLED;

        return true;
    }
}
