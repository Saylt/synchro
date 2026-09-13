<?php

namespace Tygh\Addons\Synchro;

if (!function_exists(__NAMESPACE__ . '\\__')) {
    function __($language_variable, array $params = [])
    {
        return $language_variable . ($params ? json_encode($params) : '');
    }
}

namespace Tygh\Addons\Synchro\Tests\Unit;

defined('TIME') or define('TIME', time());
defined('SECONDS_IN_DAY') or define('SECONDS_IN_DAY', 86400);
defined('DESCR_SL') or define('DESCR_SL', 'en');

use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\Logging;
use Tygh\Database\Connection;
use Tygh\Lock\Factory;
use Tygh\Lock\Lock;
use Tygh\Tests\Unit\ATestCase;

class CronManagerTest extends ATestCase
{
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

    public function testGetsSetElementsFromCronScriptsTable()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->with(
                'SHOW COLUMNS FROM ?:?p WHERE Field = ?s',
                CronManager::TABLE_NAME,
                'inner_status'
            )
            ->willReturn(['Type' => "enum('scheduled','completed')"]);

        $this->assertSame([
            'scheduled' => 'synchro.scheduled',
            'completed' => 'synchro.completed',
        ], $this->createManager($database)->getSetElements('inner_status', true));
    }

    public function testGetsScheduleNumberRangesWithoutColumnInspection()
    {
        $database = $this->createDatabase();
        $database->expects($this->never())->method('getRow');
        $manager = $this->createManager($database);

        $this->assertSame(array_map('strval', range(0, 23)), $manager->getSetElements('period_hours_begin'));
        $this->assertSame(array_map('strval', range(0, 24)), $manager->getSetElements('period_hours_end'));
        $this->assertSame(array_map('strval', range(0, 23)), $manager->getSetElements('refresh_hours'));
        $this->assertSame(array_map('strval', range(0, 59)), $manager->getSetElements('refresh_minutes'));
    }

    public function testOrdersPostProcessTaskImmediatelyAfterSourceTask()
    {
        $scripts = [
            15 => [
                'script_id'    => 15,
                'script'       => 'synchro_import.categories',
                'post_process' => 'synchro_import.apply_categories',
            ],
            30 => [
                'script_id'    => 30,
                'script'       => 'synchro_import.products',
                'post_process' => '',
            ],
            20 => [
                'script_id'    => 20,
                'script'       => 'synchro_import.apply_categories',
                'post_process' => '',
            ],
        ];

        $ordered_scripts = $this->createManager($this->createDatabase())
            ->orderCronScriptsByDependencies($scripts);

        $this->assertSame([15, 20, 30], array_keys($ordered_scripts));
        $this->assertSame(0, $ordered_scripts[15]['dependency_level']);
        $this->assertSame(1, $ordered_scripts[20]['dependency_level']);
        $this->assertSame('synchro_import.categories', $ordered_scripts[20]['dependency_source']);
    }

    public function testOrdersTestProductApplicationTaskAfterTestSourceTask()
    {
        $scripts = [
            15 => [
                'script_id'      => 15,
                'script'         => 'synchro_import.products',
                'is_test_import' => 'Y',
                'post_process'   => 'synchro_import.apply_products',
            ],
            20 => [
                'script_id'    => 20,
                'script'       => 'synchro_import.apply_test_products',
                'post_process' => '',
            ],
            30 => [
                'script_id'    => 30,
                'script'       => 'synchro_import.apply_products',
                'post_process' => '',
            ],
        ];

        $ordered_scripts = $this->createManager($this->createDatabase())
            ->orderCronScriptsByDependencies($scripts);

        $this->assertSame([15, 20, 30], array_keys($ordered_scripts));
        $this->assertSame(1, $ordered_scripts[20]['dependency_level']);
        $this->assertSame('synchro_import.products', $ordered_scripts[20]['dependency_source']);
    }

    public function testGetsCurrentStatusForRequestedCronTasks()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getHash')
            ->with(
                'SELECT script_id, last_launch, inner_status, progress_status FROM ?:?p'
                . ' WHERE script_id IN (?n) AND script IN (?a)',
                'script_id',
                CronManager::TABLE_NAME,
                [15, 20],
                ['synchro_import.products']
            )
            ->willReturn([
                15 => [
                    'script_id'       => '15',
                    'last_launch'     => '1757588400',
                    'inner_status'    => 'in_progress',
                    'progress_status' => 'Importing 30 of 100 products',
                ],
            ]);

        $this->assertSame([
            15 => [
                'script_id'       => 15,
                'last_launch'     => 1757588400,
                'inner_status'    => 'in_progress',
                'progress_status' => 'Importing 30 of 100 products',
            ],
        ], $this->createManager($database)->getCronScriptStatuses([15, 20]));
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
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                [
                    'script'                 => 'synchro_import.products',
                    'period_hours_begin'     => '0',
                    'period_hours_end'       => '0',
                    'refresh_hours'          => '0',
                    'refresh_minutes'        => '0',
                    'run_mode'               => 'once',
                    'use_portions'            => 'N',
                    'pages_per_portion'       => 1,
                    'page_limit'              => 10,
                    'entities_per_portion'    => 17,
                    'max_parallel_processes'  => 1,
                    'is_test_import'          => 'Y',
                    'test_page'               => 25,
                    'post_process'             => '',
                    'created'                 => TIME,
                ]
            )
            ->willReturn('15');

        $result = $this->createManager($database)->updateScriptData([
            'script'                  => 'synchro_import.products',
            'period_week_days'        => ['monday'],
            'run_mode'                => 'once',
            'use_portions'            => 'Y',
            'pages_per_portion'       => 0,
            'page_limit'              => 500,
            'entities_per_portion'    => 17,
            'max_parallel_processes'  => 8,
            'is_test_import'          => 'Y',
            'test_page'               => 25,
        ]);

        $this->assertSame(15, $result);
    }

    public function testCategoryApplicationPostProcessIsAllowed()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->once())->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                $this->callback(static function (array $script_data) {
                    return $script_data['script'] === 'synchro_import.categories'
                        && $script_data['entities_per_portion'] === 30
                        && $script_data['max_parallel_processes'] === 3
                        && $script_data['post_process'] === CronManager::POST_PROCESS_APPLY_CATEGORIES;
                })
            )
            ->willReturn(15);

        $this->assertSame(15, $this->createManager(
            $database,
            null,
            '/usr/bin/php',
            ['synchro_import.categories' => ['name' => 'synchro.import_categories']]
        )->updateScriptData([
            'script'       => 'synchro_import.categories',
            'period_week_days' => ['monday'],
            'run_mode'     => 'once',
            'post_process' => CronManager::POST_PROCESS_APPLY_CATEGORIES,
        ]));
    }

    /**
     * @dataProvider productPostProcessesProvider
     *
     * @param string $post_process Product post-process dispatch
     *
     * @return void
     */
    public function testCategoryImportRejectsProductPostProcess($post_process)
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->once())->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                $this->callback(static function (array $script_data) {
                    return $script_data['post_process'] === '';
                })
            )
            ->willReturn(15);

        $this->assertSame(15, $this->createManager(
            $database,
            null,
            '/usr/bin/php',
            ['synchro_import.categories' => ['name' => 'synchro.import_categories']]
        )->updateScriptData([
            'script'       => 'synchro_import.categories',
            'period_week_days' => ['monday'],
            'run_mode'     => 'once',
            'post_process' => $post_process,
        ]));
    }

    /**
     * Provides product-only post-process dispatches.
     *
     * @return array<array{string}>
     */
    public function productPostProcessesProvider()
    {
        return [
            [CronManager::POST_PROCESS_APPLY_PRODUCTS],
            [CronManager::POST_PROCESS_ACTUALIZE_PRODUCTS],
        ];
    }

    public function testProductImportRejectsCategoryPostProcess()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->once())->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                $this->callback(static function (array $script_data) {
                    return $script_data['post_process'] === '';
                })
            )
            ->willReturn(15);

        $this->assertSame(15, $this->createManager($database)->updateScriptData([
            'script'           => 'synchro_import.products',
            'period_week_days' => ['monday'],
            'run_mode'         => 'once',
            'post_process'     => CronManager::POST_PROCESS_APPLY_CATEGORIES,
        ]));
    }

    /**
     * @dataProvider entityApplicationSettingsProvider
     *
     * @param string $script                         Cron dispatch
     * @param mixed  $entities_per_portion           Submitted application portion size
     * @param int    $expected_entities_per_portion  Expected application portion size
     * @param mixed  $max_parallel_processes         Submitted process limit
     * @param int    $expected_max_parallel_processes Expected process limit
     *
     * @return void
     */
    public function testEntityApplicationSettingsAreNormalized(
        $script,
        $entities_per_portion,
        $expected_entities_per_portion,
        $max_parallel_processes,
        $expected_max_parallel_processes
    ) {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->once())->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                $this->callback(static function (array $script_data) use (
                    $expected_entities_per_portion,
                    $expected_max_parallel_processes
                ) {
                    return $script_data['entities_per_portion'] === $expected_entities_per_portion
                        && $script_data['max_parallel_processes'] === $expected_max_parallel_processes;
                })
            )
            ->willReturn(15);

        $this->assertSame(15, $this->createManager(
            $database,
            null,
            '/usr/bin/php',
            [$script => ['name' => $script]]
        )->updateScriptData([
            'script'                  => $script,
            'period_week_days'        => ['monday'],
            'run_mode'                => 'once',
            'entities_per_portion'    => $entities_per_portion,
            'max_parallel_processes'  => $max_parallel_processes,
        ]));
    }

    /**
     * Provides invalid and valid application settings for product and category imports.
     *
     * @return array<array{string, mixed, int, mixed, int}>
     */
    public function entityApplicationSettingsProvider()
    {
        return [
            ['synchro_import.products', 17, 17, 4, 4],
            ['synchro_import.products', 0, 1, 0, 1],
            ['synchro_import.categories', -10, 1, -4, 1],
            ['synchro_import.categories', 'invalid', 1, 'invalid', 1],
        ];
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
                [
                    'SELECT post_process, entities_per_portion, max_parallel_processes'
                    . ' FROM ?:?p WHERE script_id = ?i',
                    CronManager::TABLE_NAME,
                    15,
                ],
                ['SELECT * FROM ?:?p WHERE script = ?s LIMIT 1', CronManager::TABLE_NAME, 'synchro_import.apply_products']
            )
            ->willReturnOnConsecutiveCalls(
                [
                    'post_process'            => 'synchro_import.apply_products',
                    'entities_per_portion'    => '17',
                    'max_parallel_processes'  => '4',
                ],
                ['script_id' => 16, 'inner_status' => 'completed']
            );
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
                CronManager::TABLE_NAME,
                [
                    'status'            => 'A',
                    'run_mode'          => 'once',
                    'inner_status'      => 'queued',
                    'runtime_import_id' => 10,
                    'entities_per_portion'   => 17,
                    'max_parallel_processes' => 4,
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

    public function testCompletedImportQueuesPostProcessWithStringTaskId()
    {
        $database = $this->createDatabase();
        $database->expects($this->exactly(2))
            ->method('getRow')
            ->withConsecutive(
                [
                    'SELECT post_process, entities_per_portion, max_parallel_processes'
                    . ' FROM ?:?p WHERE script_id = ?i',
                    CronManager::TABLE_NAME,
                    15,
                ],
                ['SELECT * FROM ?:?p WHERE script = ?s LIMIT 1', CronManager::TABLE_NAME, 'synchro_import.apply_products']
            )
            ->willReturnOnConsecutiveCalls(
                ['post_process' => 'synchro_import.apply_products'],
                []
            );
        $database->expects($this->once())
            ->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                [
                    'status'            => 'A',
                    'run_mode'          => 'once',
                    'inner_status'      => 'queued',
                    'runtime_import_id' => 10,
                    'entities_per_portion'   => 30,
                    'max_parallel_processes' => 3,
                    'last_launch'       => TIME,
                    'script'            => 'synchro_import.apply_products',
                    'created'           => TIME,
                ]
            )
            ->willReturn('16');

        $this->assertTrue($this->createManager(
            $database,
            null,
            '/usr/bin/true',
            [
                'synchro_import.products' => ['name' => 'synchro.import_products'],
                'synchro_import.apply_products' => ['name' => 'synchro.apply_products'],
            ]
        )->queuePostProcess(15, 10, 'full', 'completed'));
    }

    public function testCompletedLeafTaskDoesNotQueuePostProcess()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->with(
                'SELECT post_process, entities_per_portion, max_parallel_processes'
                . ' FROM ?:?p WHERE script_id = ?i',
                CronManager::TABLE_NAME,
                15
            )
            ->willReturn(['post_process' => '']);
        $database->expects($this->never())->method('query');
        $logging = $this->createMock(Logging::class);
        $logging->expects($this->never())->method('error');

        $this->assertTrue($this->createManager(
            $database,
            null,
            '/usr/bin/php',
            null,
            $logging
        )->queuePostProcess(15, 10, 'test', 'completed'));
    }

    public function testLogsSourceStatusWhenFullPostProcessCannotRunAfterPartialImport()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->with(
                'SELECT post_process, entities_per_portion, max_parallel_processes'
                . ' FROM ?:?p WHERE script_id = ?i',
                CronManager::TABLE_NAME,
                15
            )
            ->willReturn(['post_process' => 'synchro_import.apply_products']);
        $logging = $this->createMock(Logging::class);
        $logging->expects($this->once())
            ->method('error')
            ->with(
                'synchro_import.apply_products',
                $this->stringContains('partial_success')
            );

        $this->assertFalse($this->createManager(
            $database,
            null,
            '/usr/bin/php',
            null,
            $logging
        )->queuePostProcess(15, 10, 'full', 'partial_success'));
    }

    public function testProgressStatusCanBeUpdated()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET progress_status = ?s WHERE script_id = ?i',
                CronManager::TABLE_NAME,
                'Page 4 of 10',
                15
            )
            ->willReturn('1');
        $manager = $this->createManager($database);

        $this->assertTrue(is_callable([$manager, 'updateProgressStatus']));
        $this->assertSame(1, $manager->updateProgressStatus(15, 'Page 4 of 10'));
    }

    public function testDailyScheduleIsDueAtConfiguredTime()
    {
        $manager = $this->createManager($this->createDatabase());
        $timestamp = mktime(8, 0, 0, 9, 14, 2026);

        $this->assertTrue($manager->isCronScriptDue($this->createPeriodicScript(), $timestamp));
        $this->assertFalse($manager->isCronScriptDue($this->createPeriodicScript(), $timestamp + 60));
    }

    public function testWeeklyScheduleRunsOnlyOnSelectedWeekdays()
    {
        $manager = $this->createManager($this->createDatabase());
        $script = $this->createPeriodicScript([
            'period_week_days' => ['monday'],
        ]);

        $this->assertTrue($manager->isCronScriptDue($script, mktime(8, 0, 0, 9, 14, 2026)));
        $this->assertFalse($manager->isCronScriptDue($script, mktime(8, 0, 0, 9, 15, 2026)));
    }

    public function testMonthlyScheduleRunsOnlyOnSelectedMonthDays()
    {
        $manager = $this->createManager($this->createDatabase());
        $script = $this->createPeriodicScript([
            'period_month_days' => ['15'],
        ]);

        $this->assertTrue($manager->isCronScriptDue($script, mktime(8, 0, 0, 9, 15, 2026)));
        $this->assertFalse($manager->isCronScriptDue($script, mktime(8, 0, 0, 9, 16, 2026)));
    }

    public function testIntervalScheduleRunsOnlyAtIntervalWithinWindow()
    {
        $manager = $this->createManager($this->createDatabase());
        $script = $this->createPeriodicScript([
            'period_hours_end' => '18',
            'refresh_hours'    => '3',
            'refresh_minutes'  => '15',
        ]);

        $this->assertTrue($manager->isCronScriptDue($script, mktime(11, 15, 0, 9, 14, 2026)));
        $this->assertFalse($manager->isCronScriptDue($script, mktime(11, 16, 0, 9, 14, 2026)));
        $this->assertFalse($manager->isCronScriptDue($script, mktime(18, 0, 0, 9, 14, 2026)));
    }

    public function testScheduleIsNotDueAgainWithinMinimumLaunchInterval()
    {
        $manager = $this->createManager($this->createDatabase());
        $timestamp = mktime(8, 0, 0, 9, 14, 2026);
        $script = $this->createPeriodicScript([
            'last_launch' => $timestamp - 59,
        ]);

        $this->assertFalse($manager->isCronScriptDue($script, $timestamp));
    }

    public function testPeriodicMonthScheduleIsNormalizedBeforeInsert()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                $this->callback(static function (array $script_data) {
                    return $script_data === [
                        'script'                  => 'synchro_import.categories',
                        'run_mode'                => 'periodic',
                        'period_month_days'       => '1,15',
                        'period_hours_begin'      => 8,
                        'period_hours_end'        => 18,
                        'refresh_hours'           => 3,
                        'refresh_minutes'         => 15,
                        'entities_per_portion'    => 30,
                        'max_parallel_processes'  => 3,
                        'post_process'            => '',
                        'created'                 => TIME,
                    ];
                })
            )
            ->willReturn(15);

        $manager = $this->createManager(
            $database,
            null,
            '/usr/bin/php',
            ['synchro_import.categories' => ['name' => 'synchro.import_categories']]
        );
        $result = $manager->updateScriptData([
            'script'             => 'synchro_import.categories',
            'run_mode'           => 'periodic',
            'period_day_mode'    => 'month_days',
            'period_month_days'  => ['1', '15'],
            'period_week_days'   => ['monday'],
            'period_time_mode'   => 'interval',
            'period_hours_begin' => '8',
            'period_hours_end'   => '18',
            'refresh_hours'      => '3',
            'refresh_minutes'    => '15',
        ]);

        $this->assertSame(15, $result);
    }

    public function testPeriodicDailyScheduleIsNormalizedBeforeInsert()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                [
                    'script'             => 'synchro_import.categories',
                    'period_hours_begin' => '8',
                    'period_hours_end'   => '8',
                    'refresh_hours'      => '0',
                    'refresh_minutes'    => '0',
                    'run_mode'           => 'periodic',
                    'entities_per_portion'   => 30,
                    'max_parallel_processes' => 3,
                    'post_process'       => '',
                    'created'            => TIME,
                ]
            )
            ->willReturn(15);

        $manager = $this->createManager(
            $database,
            null,
            '/usr/bin/php',
            ['synchro_import.categories' => ['name' => 'synchro.import_categories']]
        );
        $result = $manager->updateScriptData([
            'script'             => 'synchro_import.categories',
            'run_mode'           => 'periodic',
            'period_day_mode'    => 'daily',
            'period_month_days'  => ['1'],
            'period_week_days'   => ['monday'],
            'period_time_mode'   => 'once',
            'period_hours_begin' => '8',
            'period_hours_end'   => '18',
            'refresh_hours'      => '3',
            'refresh_minutes'    => '15',
        ]);

        $this->assertSame(15, $result);
    }

    public function testPeriodicWeeklyScheduleRetainsDaysForOneTimeRun()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'INSERT INTO ?:?p ?e',
                CronManager::TABLE_NAME,
                [
                    'script'             => 'synchro_import.categories',
                    'period_week_days'   => 'monday',
                    'period_hours_begin' => '8',
                    'period_hours_end'   => '8',
                    'refresh_hours'      => '0',
                    'refresh_minutes'    => '0',
                    'run_mode'           => 'periodic',
                    'entities_per_portion'   => 30,
                    'max_parallel_processes' => 3,
                    'post_process'       => '',
                    'created'            => TIME,
                ]
            )
            ->willReturn(15);

        $manager = $this->createManager(
            $database,
            null,
            '/usr/bin/php',
            ['synchro_import.categories' => ['name' => 'synchro.import_categories']]
        );
        $result = $manager->updateScriptData([
            'script'             => 'synchro_import.categories',
            'run_mode'           => 'periodic',
            'period_day_mode'    => 'week_days',
            'period_week_days'   => ['monday'],
            'period_time_mode'   => 'once',
            'period_hours_begin' => '8',
            'period_hours_end'   => '18',
            'refresh_hours'      => '3',
            'refresh_minutes'    => '15',
        ]);

        $this->assertSame(15, $result);
    }

    /**
     * @dataProvider invalidPeriodicScheduleProvider
     *
     * @param array<string, array<int, string>|int|string> $schedule_data Invalid schedule data
     *
     * @return void
     */
    public function testInvalidPeriodicScheduleCannotBeSaved(array $schedule_data)
    {
        $database = $this->createDatabase();
        $database->expects($this->once())->method('getField')->willReturn(false);
        $database->expects($this->never())->method('query');

        $this->assertFalse($this->createManager($database)->updateScriptData(array_merge([
            'script'             => 'synchro_import.products',
            'run_mode'           => 'periodic',
            'period_day_mode'    => 'daily',
            'period_month_days'  => [],
            'period_week_days'   => [],
            'period_time_mode'   => 'once',
            'period_hours_begin' => '8',
            'period_hours_end'   => '8',
            'refresh_hours'      => '0',
            'refresh_minutes'    => '0',
        ], $schedule_data)));
    }

    /**
     * @return array<string, array{array<string, array<int, string>|int|string>}>
     */
    public function invalidPeriodicScheduleProvider()
    {
        return [
            'weekdays without a day' => [[
                'period_day_mode'  => 'week_days',
                'period_week_days' => [],
            ]],
            'month days without a day' => [[
                'period_day_mode'   => 'month_days',
                'period_month_days' => [],
            ]],
            'zero repeat interval' => [[
                'period_time_mode' => 'interval',
                'period_hours_end' => '18',
            ]],
            'interval ends at start' => [[
                'period_time_mode' => 'interval',
                'period_hours_end' => '8',
                'refresh_minutes'  => '1',
            ]],
            'interval crosses midnight' => [[
                'period_time_mode' => 'interval',
                'period_hours_end' => '7',
                'refresh_minutes'  => '1',
            ]],
        ];
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
                'UPDATE ?:?p SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
                CronManager::TABLE_NAME,
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
                'UPDATE ?:?p SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
                CronManager::TABLE_NAME,
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
                    'UPDATE ?:?p SET inner_status = IF(run_mode = ?s, ?s, ?s)'
                    . ' WHERE script_id = ?i AND script IN (?a) AND inner_status = ?s',
                    CronManager::TABLE_NAME,
                    'once',
                    'cancelled',
                    'scheduled',
                    15,
                    ['synchro_import.products'],
                    'queued',
                ],
                [
                    'UPDATE ?:?p SET inner_status = ?s'
                    . ' WHERE script_id = ?i AND script IN (?a) AND inner_status IN (?a)',
                    CronManager::TABLE_NAME,
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
                'UPDATE ?:?p SET inner_status = ?s'
                . ' WHERE script_id = ?i AND inner_status = ?s',
                CronManager::TABLE_NAME,
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
                'UPDATE ?:?p SET inner_status = ?s'
                . ' WHERE script_id = ?i AND inner_status IN (?a)',
                CronManager::TABLE_NAME,
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
                'SELECT script, run_mode, inner_status, last_launch FROM ?:?p WHERE script_id = ?i',
                CronManager::TABLE_NAME,
                15
            )
            ->willReturn([
                'run_mode'     => 'once',
                'inner_status' => 'waiting_children',
            ]);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET inner_status = ?s, progress_status = ?s'
                . ' WHERE script_id = ?i AND inner_status IN (?a)',
                CronManager::TABLE_NAME,
                'partial_success',
                'synchro.task_finished_at' . json_encode([
                    '[time]' => date('Y-m-d H:i:s', TIME),
                ]),
                15,
                ['waiting_children', 'stopping']
            )
            ->willReturn(1);

        $this->assertTrue($this->createManager($database)->finalizeDeferredTask(15, 'partial_success'));
    }

    public function testFinalizingDeferredTaskStoresCompletionTimeAndLogsFullDuration()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->willReturn([
                'script'       => 'synchro_import.apply_categories',
                'run_mode'     => 'once',
                'inner_status' => 'waiting_children',
                'last_launch'  => TIME - 15,
            ]);
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p SET inner_status = ?s, progress_status = ?s'
                . ' WHERE script_id = ?i AND inner_status IN (?a)',
                CronManager::TABLE_NAME,
                'completed',
                'synchro.task_finished_at' . json_encode([
                    '[time]' => date('Y-m-d H:i:s', TIME),
                ]),
                15,
                ['waiting_children', 'stopping']
            )
            ->willReturn(1);
        $logging = $this->createMock(Logging::class);
        $logging->expects($this->once())
            ->method('info')
            ->with(
                'synchro_import.apply_categories',
                'synchro.task_execution_finished',
                [
                    'execution_time' => 15,
                    'result_status'  => 'completed',
                ]
            );

        $this->assertTrue($this->createManager($database, null, '/usr/bin/php', null, $logging)
            ->finalizeDeferredTask(15, 'completed'));
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
                'UPDATE ?:?p SET inner_status = ?s, progress_status = ?s'
                . ' WHERE script_id = ?i AND inner_status IN (?a)',
                CronManager::TABLE_NAME,
                'scheduled',
                'synchro.task_finished_at' . json_encode([
                    '[time]' => date('Y-m-d H:i:s', TIME),
                ]),
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
                'UPDATE ?:?p SET inner_status = IF(run_mode = ?s, ?s, ?s)'
                . ' WHERE script_id = ?i AND script IN (?a) AND inner_status = ?s',
                CronManager::TABLE_NAME,
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
            ->with('SELECT inner_status FROM ?:?p WHERE script_id = ?i', CronManager::TABLE_NAME, 15)
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
                'UPDATE ?:?p SET ?u WHERE script_id = ?i',
                CronManager::TABLE_NAME,
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
                    'UPDATE ?:?p SET ?u WHERE script_id = ?i AND inner_status = ?s',
                    CronManager::TABLE_NAME,
                    [
                        'inner_status'    => 'in_progress',
                        'progress_status' => null,
                    ],
                    15,
                    'queued',
                ],
                [
                    'UPDATE ?:?p SET inner_status = IF(inner_status = ?s, ?s, ?s), progress_status = ?s'
                    . ' WHERE script_id = ?i AND inner_status IN (?a)',
                    CronManager::TABLE_NAME,
                    'stopping',
                    'cancelled',
                    'completed',
                    'synchro.task_finished_at' . json_encode([
                        '[time]' => date('Y-m-d H:i:s', TIME),
                    ]),
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

    public function testPostProcessDoesNotLogZeroDurationBeforeChildrenFinish()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getRow')
            ->willReturn([]);
        $database->expects($this->exactly(2))
            ->method('query')
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
        $logging = $this->createMock(Logging::class);
        $logging->expects($this->never())->method('info');

        $result = $this->createManager(
            $database,
            $lock_factory,
            '/usr/bin/true',
            ['synchro_import.apply_categories' => ['name' => 'synchro.apply_categories']],
            $logging
        )
            ->launchCronScript([
                'script_id'         => 15,
                'script'            => 'synchro_import.apply_categories',
                'run_mode'          => 'once',
                'inner_status'      => 'queued',
                'last_launch'       => TIME,
                'runtime_import_id' => 50,
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
                    'UPDATE ?:?p SET ?u WHERE script_id = ?i AND inner_status = ?s',
                    CronManager::TABLE_NAME,
                    [
                        'inner_status'    => 'in_progress',
                        'progress_status' => null,
                    ],
                    15,
                    'queued',
                ],
                [
                    'UPDATE ?:?p SET inner_status = IF(inner_status = ?s, ?s, ?s), progress_status = ?s'
                    . ' WHERE script_id = ?i AND inner_status IN (?a)',
                    CronManager::TABLE_NAME,
                    'stopping',
                    'cancelled',
                    'failed',
                    'synchro.task_finished_at' . json_encode([
                        '[time]' => date('Y-m-d H:i:s', TIME),
                    ]),
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
            ->setMethods(['getField', 'getHash', 'getRow', 'query'])
            ->getMock();
    }

    /**
     * @param array<string, array<int, string>|int|string> $data Schedule fields
     *
     * @return array<string, array<int, string>|int|string>
     */
    private function createPeriodicScript(array $data = [])
    {
        return array_merge([
            'last_launch'        => 0,
            'period_month_days'  => [],
            'period_week_days'   => [],
            'period_hours_begin' => '8',
            'period_hours_end'   => '8',
            'refresh_hours'      => '0',
            'refresh_minutes'    => '0',
        ], $data);
    }

    /**
     * @param \Tygh\Database\Connection $database     Database connection
     * @param \Tygh\Lock\Factory|null    $lock_factory Lock factory
     * @param string                      $php_binary   PHP CLI binary
     * @param \Tygh\Addons\Synchro\Logging|null $logging Logging service
     *
     * @return \Tygh\Addons\Synchro\CronManager
     */
    private function createManager(
        Connection $database,
        Factory $lock_factory = null,
        $php_binary = '/usr/bin/php',
        array $available_scripts = null,
        Logging $logging = null
    )
    {
        if ($lock_factory === null) {
            $lock_factory = $this->getMockBuilder(Factory::class)
                ->disableOriginalConstructor()
                ->getMock();
        }
        if ($logging === null) {
            $logging = $this->createMock(Logging::class);
        }

        $arguments = [
            $database,
            $logging,
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
        ];

        return new CronManager(...$arguments);
    }
}
