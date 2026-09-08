<?php

namespace Tygh\Addons\Synchro;

function fn_log_event($type, $action, array $data)
{
    if (isset($GLOBALS['synchro_cron_log_event'])) {
        call_user_func($GLOBALS['synchro_cron_log_event'], $type, $action, $data);
    }
}

function __($language_variable, array $params = [])
{
    return $language_variable . json_encode($params);
}

namespace Tygh\Addons\Synchro\Tests\Unit;

defined('TIME') or define('TIME', time());
defined('SECONDS_IN_DAY') or define('SECONDS_IN_DAY', 86400);

use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Database\Connection;
use Tygh\Lock\Factory;
use Tygh\Lock\Lock;
use Tygh\Tests\Unit\ATestCase;

class CronManagerTest extends ATestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        unset($GLOBALS['synchro_cron_log_event']);
    }

    public function testUnregisteredScriptCannotBeSaved()
    {
        $database = $this->createDatabase();
        $database->expects($this->never())->method('getField');
        $database->expects($this->never())->method('query');

        $result = $this->createManager($database)->updateScriptData([
            'script'           => 'orders.export',
            'script_type'      => 'custom_command',
            'period_week_days' => ['monday'],
        ]);

        $this->assertFalse($result);
    }

    public function testTestProductImportSettingsAreNormalized()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getField')
            ->willReturn(false);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'INSERT INTO ?:cron_scripts ?e',
                [
                    'script'                 => 'synchro_import.products',
                    'period_week_days'       => 'monday',
                    'run_mode'               => 'once',
                    'use_portions'            => 'N',
                    'pages_per_portion'       => 1,
                    'page_limit'              => 10,
                    'max_parallel_processes'  => 1,
                    'is_test_import'          => 'Y',
                    'test_page'               => 25,
                    'post_process'             => '',
                    'created'                 => TIME,
                ]
            )
            ->willReturn(15);

        $result = $this->createManager($database)->updateScriptData([
            'script'                  => 'synchro_import.products',
            'period_week_days'        => ['monday'],
            'run_mode'                => 'once',
            'use_portions'            => 'Y',
            'pages_per_portion'       => 0,
            'page_limit'              => 500,
            'max_parallel_processes'  => 8,
            'is_test_import'          => 'Y',
            'test_page'               => 25,
        ]);

        $this->assertSame(15, $result);
    }

    public function testControllerCommandReceivesCronScriptId()
    {
        $command = $this->createManager($this->createDatabase())->prepareScript(
            'synchro_import.products',
            15
        );

        $this->assertSame(
            "'/usr/bin/php' '/store/admin.php' '--dispatch=synchro_import.products' '--cron_script_id=15'",
            $command
        );
    }

    public function testPostProcessCommandReceivesSourceImportId()
    {
        $command = $this->createManager($this->createDatabase())->prepareScript(
            'synchro_import.apply_products',
            16,
            10
        );

        $this->assertSame(
            "'/usr/bin/php' '/store/admin.php' '--dispatch=synchro_import.apply_products'"
            . " '--cron_script_id=16' '--import_id=10'",
            $command
        );
    }

    public function testCompletedImportQueuesConfiguredPostProcess()
    {
        $database = $this->createDatabase();
        $database->expects($this->exactly(2))
            ->method('getRow')
            ->withConsecutive(
                ['SELECT post_process FROM ?:cron_scripts WHERE script_id = ?i', 15],
                ['SELECT * FROM ?:cron_scripts WHERE script = ?s LIMIT 1', 'synchro_import.apply_products']
            )
            ->willReturnOnConsecutiveCalls(
                ['post_process' => 'synchro_import.apply_products'],
                ['script_id' => 16, 'inner_status' => 'completed']
            );
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
                [
                    'status'            => 'A',
                    'run_mode'          => 'once',
                    'inner_status'      => 'queued',
                    'runtime_import_id' => 10,
                    'last_launch'       => TIME,
                ],
                16,
                ['scheduled', 'completed', 'partial_success', 'failed', 'cancelled']
            )
            ->willReturn(1);
        $scripts = [
            'synchro_import.products' => ['name' => 'synchro.import_products'],
            'synchro_import.apply_products' => ['name' => 'synchro.apply_products'],
        ];

        $this->assertTrue($this->createManager(
            $database,
            null,
            '/usr/bin/true',
            $scripts
        )->queuePostProcess(15, 10, 'full', 'completed'));
    }

    public function testLogsSourceStatusWhenFullPostProcessCannotRunAfterPartialImport()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->with('SELECT post_process FROM ?:cron_scripts WHERE script_id = ?i', 15)
            ->willReturn(['post_process' => 'synchro_import.apply_products']);
        $logged_error = '';
        $GLOBALS['synchro_cron_log_event'] = static function ($type, $action, array $data) use (&$logged_error) {
            $logged_error = $data['error'];
        };

        $this->assertFalse($this->createManager($database)->queuePostProcess(15, 10, 'full', 'partial_success'));
        $this->assertStringContainsString('partial_success', $logged_error);
    }

    public function testProgressStatusCanBeUpdated()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET progress_status = ?s WHERE script_id = ?i',
                'Page 4 of 10',
                15
            )
            ->willReturn(1);
        $manager = $this->createManager($database);

        $this->assertTrue(is_callable([$manager, 'updateProgressStatus']));
        $this->assertSame(1, $manager->updateProgressStatus(15, 'Page 4 of 10'));
    }

    public function testBackgroundCommandUsesDedicatedRunner()
    {
        $manager = $this->createManager($this->createDatabase());

        $this->assertTrue(is_callable([$manager, 'prepareBackgroundCommand']));
        $this->assertSame(
            "'/usr/bin/php' '/store/admin.php' '--dispatch=cron_script_manager.run'"
            . " '--cron_password=secret' '--script_id=15' > /dev/null 2>&1 &",
            $manager->prepareBackgroundCommand(15)
        );
    }

    public function testScriptIsNotAddedToQueueWhenAtomicClaimFails()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
                [
                    'inner_status' => 'queued',
                    'last_launch'  => TIME,
                ],
                15,
                ['scheduled']
            )
            ->willReturn(0);

        $result = $this->createManager($database)->launchCronScriptInBackground([
            'script_id'   => 15,
            'script'      => 'synchro_import.products',
            'inner_status' => 'scheduled',
            'last_launch' => 0,
        ]);

        $this->assertFalse($result);
    }

    public function testCompletedOnceScriptCanBeManuallyQueuedAgain()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
                [
                    'inner_status' => 'queued',
                    'last_launch'  => TIME,
                ],
                15,
                ['scheduled', 'completed', 'partial_success', 'failed', 'cancelled']
            )
            ->willReturn(1);

        $result = $this->createManager($database, null, '/usr/bin/true')->launchCronScriptInBackground([
            'script_id'   => 15,
            'script'      => 'synchro_import.products',
            'inner_status' => 'completed',
            'last_launch' => TIME,
        ], true);

        $this->assertTrue($result);
    }

    public function testRunningScriptCanBeMarkedForInterruption()
    {
        $database = $this->createDatabase();
        $database->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                [
                    'UPDATE ?:cron_scripts SET inner_status = IF(run_mode = ?s, ?s, ?s)'
                    . ' WHERE script_id = ?i AND script IN (?a) AND inner_status = ?s',
                    'once',
                    'cancelled',
                    'scheduled',
                    15,
                    ['synchro_import.products'],
                    'queued',
                ],
                [
                    'UPDATE ?:cron_scripts SET inner_status = ?s'
                    . ' WHERE script_id = ?i AND script IN (?a) AND inner_status IN (?a)',
                    'stopping',
                    15,
                    ['synchro_import.products'],
                    ['in_progress', 'waiting_children'],
                ]
            )
            ->willReturnOnConsecutiveCalls(0, 1);
        $manager = $this->createManager($database);

        $this->assertTrue(is_callable([$manager, 'requestInterruption']));
        $this->assertTrue($manager->requestInterruption(15));
    }

    public function testTaskCanWaitForChildProcesses()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET inner_status = ?s'
                . ' WHERE script_id = ?i AND inner_status = ?s',
                'waiting_children',
                15,
                'in_progress'
            )
            ->willReturn(1);

        $this->assertTrue($this->createManager($database)->markTaskWaitingForChildren(15));
    }

    public function testTaskCanBeReopenedForFailedChildRetry()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET inner_status = ?s'
                . ' WHERE script_id = ?i AND inner_status IN (?a)',
                'waiting_children',
                15,
                ['scheduled', 'completed', 'partial_success', 'failed', 'cancelled']
            )
            ->willReturn(1);

        $this->assertTrue($this->createManager($database)->reopenTaskForChildren(15));
    }

    public function testWaitingTaskRemainsRunningUntilChildrenFinish()
    {
        $database = $this->createDatabase();
        $database->expects($this->never())->method('query');

        $this->assertTrue($this->createManager($database)->isCronScriptRunning([
            'script_id'    => 15,
            'script'       => 'synchro_import.products',
            'run_mode'     => 'once',
            'inner_status' => 'waiting_children',
            'last_launch'  => TIME - SECONDS_IN_DAY - 1,
        ]));
    }

    public function testFinalizesDeferredOnceTaskWithImportResult()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->with(
                'SELECT run_mode, inner_status FROM ?:cron_scripts WHERE script_id = ?i',
                15
            )
            ->willReturn([
                'run_mode'     => 'once',
                'inner_status' => 'waiting_children',
            ]);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET inner_status = ?s WHERE script_id = ?i AND inner_status IN (?a)',
                'partial_success',
                15,
                ['waiting_children', 'stopping']
            )
            ->willReturn(1);

        $this->assertTrue($this->createManager($database)->finalizeDeferredTask(15, 'partial_success'));
    }

    public function testFinalizesDeferredPeriodicTaskBackToSchedule()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->willReturn([
                'run_mode'     => 'periodic',
                'inner_status' => 'waiting_children',
            ]);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET inner_status = ?s WHERE script_id = ?i AND inner_status IN (?a)',
                'scheduled',
                15,
                ['waiting_children', 'stopping']
            )
            ->willReturn(1);

        $this->assertTrue($this->createManager($database)->finalizeDeferredTask(15, 'failed'));
    }

    public function testQueuedOnceScriptIsCancelledImmediately()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET inner_status = IF(run_mode = ?s, ?s, ?s)'
                . ' WHERE script_id = ?i AND script IN (?a) AND inner_status = ?s',
                'once',
                'cancelled',
                'scheduled',
                15,
                ['synchro_import.products'],
                'queued'
            )
            ->willReturn(1);
        $manager = $this->createManager($database);

        $this->assertTrue(is_callable([$manager, 'requestInterruption']));
        $this->assertTrue($manager->requestInterruption(15));
    }

    public function testStoppingScriptCannotContinueProcessingEntities()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getField')
            ->with('SELECT inner_status FROM ?:cron_scripts WHERE script_id = ?i', 15)
            ->willReturn('stopping');
        $manager = $this->createManager($database);

        $this->assertTrue(is_callable([$manager, 'ensureTaskCanContinue']));
        $this->expectException(TaskInterruptedException::class);
        $manager->ensureTaskCanContinue(15);
    }

    public function testStaleStoppingOnceScriptBecomesCancelled()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i',
                ['inner_status' => 'cancelled'],
                15
            )
            ->willReturn(1);
        $manager = $this->createManager($database);

        $this->assertFalse($manager->isCronScriptRunning([
            'script_id'    => 15,
            'script'       => 'synchro_import.products',
            'run_mode'     => 'once',
            'inner_status' => 'stopping',
            'last_launch'  => TIME - SECONDS_IN_DAY - 1,
        ]));
    }

    public function testOnceScriptBecomesCompletedAfterLaunch()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->willReturn([]);
        $database->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                [
                    'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status = ?s',
                    [
                        'inner_status'    => 'in_progress',
                        'progress_status' => null,
                    ],
                    15,
                    'queued',
                ],
                [
                    'UPDATE ?:cron_scripts SET inner_status = IF(inner_status = ?s, ?s, ?s)'
                    . ' WHERE script_id = ?i AND inner_status IN (?a)',
                    'stopping',
                    'cancelled',
                    'completed',
                    15,
                    ['in_progress', 'stopping'],
                ]
            )
            ->willReturn(1);
        $lock = $this->getMockBuilder(Lock::class)
            ->disableOriginalConstructor()
            ->setMethods(['acquire', 'release'])
            ->getMock();
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        $lock_factory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->setMethods(['createLock'])
            ->getMock();
        $lock_factory->expects($this->once())->method('createLock')->willReturn($lock);

        $result = $this->createManager($database, $lock_factory, '/usr/bin/true')->launchCronScript([
            'script_id'    => 15,
            'script'       => 'synchro_import.products',
            'run_mode'     => 'once',
            'inner_status' => 'queued',
            'last_launch'  => TIME,
            'runtime_import_id' => 0,
        ]);

        $this->assertTrue($result);
    }

    public function testOnceScriptBecomesFailedWhenControllerExitsWithError()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->willReturn([]);
        $database->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                [
                    'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status = ?s',
                    [
                        'inner_status'    => 'in_progress',
                        'progress_status' => null,
                    ],
                    15,
                    'queued',
                ],
                [
                    'UPDATE ?:cron_scripts SET inner_status = IF(inner_status = ?s, ?s, ?s)'
                    . ' WHERE script_id = ?i AND inner_status IN (?a)',
                    'stopping',
                    'cancelled',
                    'failed',
                    15,
                    ['in_progress', 'stopping'],
                ]
            )
            ->willReturn(1);
        $lock = $this->getMockBuilder(Lock::class)
            ->disableOriginalConstructor()
            ->setMethods(['acquire', 'release'])
            ->getMock();
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        $lock_factory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->setMethods(['createLock'])
            ->getMock();
        $lock_factory->expects($this->once())->method('createLock')->willReturn($lock);

        $result = $this->createManager($database, $lock_factory, '/usr/bin/false')->launchCronScript([
            'script_id'    => 15,
            'script'       => 'synchro_import.products',
            'run_mode'     => 'once',
            'inner_status' => 'queued',
            'last_launch'  => TIME,
            'runtime_import_id' => 0,
        ]);

        $this->assertFalse($result);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Database\Connection
     */
    private function createDatabase()
    {
        return $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->setMethods(['getField', 'getRow', 'query'])
            ->getMock();
    }

    /**
     * @param \Tygh\Database\Connection $database     Database connection
     * @param \Tygh\Lock\Factory|null    $lock_factory Lock factory
     * @param string                      $php_binary   PHP CLI binary
     *
     * @return \Tygh\Addons\Synchro\CronManager
     */
    private function createManager(
        Connection $database,
        Factory $lock_factory = null,
        $php_binary = '/usr/bin/php',
        array $available_scripts = null
    )
    {
        if ($lock_factory === null) {
            $lock_factory = $this->getMockBuilder(Factory::class)
                ->disableOriginalConstructor()
                ->getMock();
        }

        return new CronManager(
            $database,
            $lock_factory,
            '/store',
            'admin.php',
            'secret',
            $available_scripts ?: [
                'synchro_import.products' => [
                    'name' => 'synchro.import_products',
                ],
            ],
            $php_binary
        );
    }
}
