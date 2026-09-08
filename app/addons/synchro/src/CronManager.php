<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Database\Connection;
use Tygh\Lock\Factory;
use Tygh\Navigation\LastView;

/**
 * Manages cron scripts and provides cron runner information.
 */
class CronManager
{
    const RUN_MODE_PERIODIC = 'periodic';

    const RUN_MODE_ONCE = 'once';

    const MINUTES_IN_HOUR = 60;

    const HOURS_IN_DAY = 24;

    const MIN_SECONDS_BETWEEN_RUNS = 60;

    const LOCK_PREFIX = 'synchro.cron.';

    const POST_PROCESS_APPLY_PRODUCTS = 'synchro_import.apply_products';

    const POST_PROCESS_APPLY_CATEGORIES = 'synchro_import.apply_categories';

    const POST_PROCESS_ACTUALIZE_PRODUCTS = 'synchro_import.actualize_products';

    /**
     * @var \Tygh\Database\Connection
     */
    protected $database;

    /**
     * @var \Tygh\Lock\Factory
     */
    protected $lock_factory;

    /**
     * @var string
     */
    protected $root_directory;

    /**
     * @var string
     */
    protected $admin_index;

    /**
     * @var string
     */
    protected $cron_password;

    /**
     * @var array<string, array{name: string, hidden?: bool}>
     */
    protected $available_scripts;

    /**
     * @var string
     */
    protected $php_binary;

    /**
     * @var array<string, array<string>>
     */
    protected $set_elements = [];

    /**
     * @param \Tygh\Database\Connection                         $database          Database connection
     * @param \Tygh\Lock\Factory                                $lock_factory      Lock factory
     * @param string                                            $root_directory    Store root directory
     * @param string                                            $admin_index       Administration entry point
     * @param string                                            $cron_password     Cron access password
     * @param array<string, array{name: string, hidden?: bool}> $available_scripts Available controller modes
     * @param string                                            $php_binary        PHP CLI binary
     */
    public function __construct(
        Connection $database,
        Factory $lock_factory,
        $root_directory,
        $admin_index,
        $cron_password,
        array $available_scripts,
        $php_binary
    ) {
        $this->database = $database;
        $this->lock_factory = $lock_factory;
        $this->root_directory = rtrim($root_directory, '/');
        $this->admin_index = $admin_index;
        $this->cron_password = $cron_password;
        $this->available_scripts = $available_scripts;
        $this->php_binary = $php_binary;
    }

    /**
     * Gets controller modes available to the cron manager.
     *
     * @return array<string, array{name: string, hidden?: bool}>
     */
    public function getAvailableScripts()
    {
        return $this->available_scripts;
    }

    /**
     * Checks whether a controller mode is available to the cron manager.
     *
     * @param string $script Controller and mode
     *
     * @return bool
     */
    public function isScriptAllowed($script)
    {
        return isset($this->available_scripts[$script]);
    }

    /**
     * Checks whether maintenance mode is enabled.
     *
     * @return bool
     */
    public function isMaintenanceMode()
    {
        return file_exists($this->root_directory . '/503-error.html');
    }

    /**
     * Checks a password supplied to the cron launcher.
     *
     * @param string $password Supplied password
     *
     * @return bool
     */
    public function isValidPassword($password)
    {
        return hash_equals($this->cron_password, $password);
    }

    /**
     * Gets cron scripts by the specified search parameters.
     *
     * @param array<string, array<int|string, int|string>|bool|int|string> $params         Search parameters
     * @param int                                                          $items_per_page Number of scripts per page
     *
     * @return array{
     *     array<int|string, array<string, array<int, string>|int|string|null>>,
     *     array<string, array<int|string, int|string>|bool|int|string>
     * }
     */
    public function getCronScripts(array $params = [], $items_per_page = 0)
    {
        $params = LastView::instance()->update('cron_script_manager', $params);
        $params['page'] = empty($params['page']) ? 1 : $params['page'];

        $sortings = [
            'script'       => 's.script',
            'week_days'    => 's.period_week_days',
            'month_days'   => 's.period_month_days',
            'hours'        => ['s.period_hours_begin', 's.period_hours_end'],
            'refresh_time' => ['s.refresh_hours', 's.refresh_minutes'],
            'rate'         => [
                's.period_hours_begin',
                's.period_hours_end',
                's.refresh_hours',
                's.refresh_minutes',
            ],
            'created'      => 's.created',
            'last_launch'  => ['s.last_launch', 's.inner_status'],
            'status'       => 's.status',
            'inner_status' => 's.inner_status',
        ];
        $directions = ['asc' => 'asc', 'desc' => 'desc'];
        $available_scripts = array_keys($this->available_scripts);
        if (!$available_scripts) {
            return [[], $params];
        }

        $condition = $this->database->quote(' AND s.script IN (?a)', $available_scripts);

        if (isset($params['script']) && fn_string_not_empty($params['script'])) {
            $condition .= $this->database->quote(
                ' AND s.script LIKE ?l',
                '%' . trim((string) $params['script']) . '%'
            );
        }
        if (!empty($params['status'])) {
            $condition .= $this->database->quote(' AND s.status LIKE ?l', $params['status']);
        }
        if (!empty($params['inner_status'])) {
            $condition .= $this->database->quote(' AND s.inner_status LIKE ?l', $params['inner_status']);
        }
        if (!empty($params['run_mode'])) {
            $condition .= $this->database->quote(' AND s.run_mode = ?s', $params['run_mode']);
        }

        if (!empty($params['period']) && $params['period'] !== 'A') {
            list($params['time_from'], $params['time_to']) = fn_create_periods($params);
            $condition .= $this->database->quote(
                ' AND (s.created >= ?i AND s.created <= ?i)',
                $params['time_from'],
                $params['time_to']
            );
        }
        if (!empty($params['launch_period']) && $params['launch_period'] !== 'A') {
            list($params['launch_time_from'], $params['launch_time_to']) = fn_create_periods([
                'period'    => $params['launch_period'],
                'time_from' => $params['launch_time_from'],
                'time_to'   => $params['launch_time_to'],
            ]);
            $condition .= $this->database->quote(
                ' AND (s.last_launch >= ?i AND s.last_launch <= ?i)',
                $params['launch_time_from'],
                $params['launch_time_to']
            );
        }

        if (!empty($params['period_week_days']) && is_array($params['period_week_days'])) {
            $condition .= ' AND (' . $this->findArrayInSet(
                $params['period_week_days'],
                's.period_week_days'
            ) . ')';
        }
        if (isset($params['period_hours_begin']) && $params['period_hours_begin'] !== '') {
            $condition .= $this->database->quote(
                ' AND FIND_IN_SET(?s, s.period_hours_begin)',
                $params['period_hours_begin']
            );
        }
        if (isset($params['period_hours_end']) && $params['period_hours_end'] !== '') {
            $condition .= $this->database->quote(
                ' AND FIND_IN_SET(?s, s.period_hours_end)',
                $params['period_hours_end']
            );
        }
        if (isset($params['refresh_hours']) && $params['refresh_hours'] !== '') {
            $condition .= $this->database->quote(
                ' AND FIND_IN_SET(?s, s.refresh_hours)',
                $params['refresh_hours']
            );
        }
        if (isset($params['refresh_minutes']) && $params['refresh_minutes'] !== '') {
            $condition .= $this->database->quote(
                ' AND FIND_IN_SET(?s, s.refresh_minutes)',
                $params['refresh_minutes']
            );
        }

        if (!empty($params['period_by_timestamp'])) {
            $time_vars = $this->getTimeVars($params['period_by_timestamp']);
            $condition .= $this->database->quote(
                ' AND (s.period_month_days IS NULL OR FIND_IN_SET(?s, s.period_month_days))',
                $time_vars['month_day']
            );
            $condition .= $this->database->quote(
                ' AND FIND_IN_SET(?s, s.period_week_days)',
                $time_vars['week_day']
            );
            $period_conditions = [
                's.period_hours_begin = s.period_hours_end',
                $this->database->quote(
                    's.period_hours_begin < s.period_hours_end'
                    . ' AND ?s >= s.period_hours_begin AND ?s < s.period_hours_end',
                    $time_vars['hours'],
                    $time_vars['hours']
                ),
                $this->database->quote(
                    's.period_hours_begin > s.period_hours_end'
                    . ' AND (?s >= s.period_hours_begin OR ?s < s.period_hours_end)',
                    $time_vars['hours'],
                    $time_vars['hours']
                ),
            ];
            $condition .= ' AND ((' . implode(') OR (', $period_conditions) . '))';
            $condition .= $this->database->quote(
                ' AND s.last_launch <= ?i',
                TIME - self::MIN_SECONDS_BETWEEN_RUNS
            );
        }

        if (
            empty($params['sort_order'])
            || !is_string($params['sort_order'])
            || empty($directions[$params['sort_order']])
        ) {
            $params['sort_order'] = 'desc';
        }
        if (
            empty($params['sort_by'])
            || !is_string($params['sort_by'])
            || empty($sortings[$params['sort_by']])
        ) {
            $params['sort_by'] = 'created';
        }

        $sorting_fields = $sortings[$params['sort_by']];
        $sort_order = $directions[$params['sort_order']];
        $sorting = is_array($sorting_fields)
            ? implode(' ' . $sort_order . ', ', $sorting_fields)
            : $sorting_fields;
        $sorting .= ' ' . $sort_order;
        $params['sort_order'] = $params['sort_order'] === 'asc' ? 'desc' : 'asc';

        $limit = '';
        if (!empty($items_per_page)) {
            $total = $this->database->getField(
                'SELECT COUNT(DISTINCT(s.script_id)) FROM ?:cron_scripts AS s WHERE 1 ' . $condition
            );
            $limit = db_paginate($params['page'], $items_per_page, $total);
        }
        $items = $this->database->getHash(
            'SELECT s.* FROM ?:cron_scripts AS s WHERE 1 ' . $condition
            . ' ORDER BY ' . $sorting . ' ' . $limit,
            'script_id'
        );

        foreach ($items as &$item) {
            $item['period_week_days'] = explode(',', (string) $item['period_week_days']);
            $item['period_month_days'] = explode(',', (string) $item['period_month_days']);
        }
        unset($item);

        return [$items, $params];
    }

    /**
     * Gets cron script data.
     *
     * @param int $script_id Cron script identifier
     *
     * @return array<string, array<int, string>|int|string|null>
     */
    public function getCronScriptData($script_id)
    {
        if (!$this->available_scripts) {
            return [];
        }

        $script_data = $this->database->getRow(
            'SELECT * FROM ?:cron_scripts WHERE script_id = ?i AND script IN (?a)',
            $script_id,
            array_keys($this->available_scripts)
        );
        if (!$script_data) {
            return [];
        }

        $script_data['period_week_days'] = explode(',', (string) $script_data['period_week_days']);
        $script_data['period_month_days'] = explode(',', (string) $script_data['period_month_days']);

        return $script_data;
    }

    /**
     * Deletes a cron script.
     *
     * @param int $script_id Cron script identifier
     *
     * @return bool|int
     */
    public function deleteCronScript($script_id)
    {
        if (!$this->available_scripts) {
            return false;
        }

        return $this->database->query(
            'DELETE FROM ?:cron_scripts WHERE script_id = ?i AND script IN (?a)',
            $script_id,
            array_keys($this->available_scripts)
        );
    }

    /**
     * Launches a cron script.
     *
     * @param array<string, array<int, string>|int|string|null> $script Cron script data
     *
     * @return bool
     */
    public function launchCronScript(array $script)
    {
        if (!$this->isScriptAllowed((string) $script['script'])) {
            return false;
        }

        $lock = $this->lock_factory->createLock(
            self::LOCK_PREFIX . $script['script'],
            SECONDS_IN_DAY,
            false
        );
        if (!$lock->acquire()) {
            $this->logAlreadyRunningError($script);

            return false;
        }

        $is_started = false;
        $exit_code = 1;
        try {
            if ($this->hasRunningScript((string) $script['script'])) {
                $this->logAlreadyRunningError($script);

                return false;
            }

            $is_started = (bool) $this->database->query(
                'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status = ?s',
                [
                    'inner_status'    => 'in_progress',
                    'progress_status' => null,
                ],
                $script['script_id'],
                'queued'
            );
            if (!$is_started) {
                return false;
            }

            $start_time = time();
            $command = $this->prepareScript(
                (string) $script['script'],
                (int) $script['script_id'],
                (int) $script['runtime_import_id']
            );
            $output = [];
            exec($command, $output, $exit_code);
            $execution_time = time() - $start_time;

            fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_LAUNCH, [
                'script'         => $script['script'],
                'execution_time' => $execution_time,
                'output'         => $output,
                'exit_code'      => $exit_code,
            ]);

            return $exit_code === 0;
        } finally {
            if ($is_started) {
                $result_status = $script['run_mode'] === self::RUN_MODE_PERIODIC
                    ? 'scheduled'
                    : ($exit_code === 0 ? 'completed' : 'failed');
                $this->database->query(
                    'UPDATE ?:cron_scripts SET inner_status = IF(inner_status = ?s, ?s, ?s)'
                    . ' WHERE script_id = ?i AND inner_status IN (?a)',
                    'stopping',
                    $script['run_mode'] === self::RUN_MODE_ONCE ? 'cancelled' : 'scheduled',
                    $result_status,
                    $script['script_id'],
                    ['in_progress', 'stopping']
                );
            }
            $lock->release();
        }
    }

    /**
     * Starts a cron task in a detached CLI process.
     *
     * @param array<string, array<int, string>|int|string|null> $script          Cron script data
     * @param bool                                              $allow_completed Whether a completed task can be queued
     *
     * @return bool
     */
    public function launchCronScriptInBackground(array $script, $allow_completed = false)
    {
        if (
            !$this->isScriptAllowed((string) $script['script'])
            || $this->isCronScriptRunning($script)
        ) {
            return false;
        }

        $allowed_statuses = ['scheduled'];
        if ($allow_completed) {
            $allowed_statuses = array_merge(
                $allowed_statuses,
                ['completed', 'partial_success', 'failed', 'cancelled']
            );
        }
        $previous_status = in_array($script['inner_status'], $allowed_statuses, true)
            ? $script['inner_status']
            : 'scheduled';

        $is_queued = (bool) $this->database->query(
            'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
            [
                'inner_status' => 'queued',
                'last_launch'  => TIME,
            ],
            $script['script_id'],
            $allowed_statuses
        );
        if (!$is_queued) {
            return false;
        }

        $output = [];
        $exit_code = 0;
        exec($this->prepareBackgroundCommand((int) $script['script_id']), $output, $exit_code);
        if ($exit_code !== 0) {
            $this->database->query(
                'UPDATE ?:cron_scripts SET inner_status = ?s WHERE script_id = ?i AND inner_status = ?s',
                $previous_status,
                $script['script_id'],
                'queued'
            );

            return false;
        }

        return true;
    }

    /**
     * Checks whether a cron script must be launched at the specified time.
     *
     * @param array<string, array<int, string>|int|string|null> $script    Cron script data
     * @param int|null                                          $timestamp Unix timestamp
     *
     * @return bool
     */
    public function checkCronRefreshTime(array $script, $timestamp = null)
    {
        $time_vars = $this->getTimeVars($timestamp);
        if (empty($script['refresh_hours']) && empty($script['refresh_minutes'])) {
            return $time_vars['hours'] === (int) $script['period_hours_begin']
                && $time_vars['minutes'] === 0;
        }

        $refresh_minutes = (int) $script['refresh_hours'] * self::MINUTES_IN_HOUR
            + (int) $script['refresh_minutes'];
        if ($time_vars['hours'] >= $script['period_hours_begin']) {
            $hours_difference = $time_vars['hours'] - $script['period_hours_begin'];
        } else {
            $hours_difference = self::HOURS_IN_DAY - $script['period_hours_begin'] + $time_vars['hours'];
        }
        $minutes_difference = $hours_difference * self::MINUTES_IN_HOUR + $time_vars['minutes'];

        return $minutes_difference % $refresh_minutes === 0;
    }

    /**
     * Checks whether a cron script is currently running.
     *
     * Resets a stale state when a task has been marked as running for more than a day.
     *
     * @param array<string, array<int, string>|int|string|null> $script Cron script data
     *
     * @return bool
     */
    public function isCronScriptRunning(array $script)
    {
        if ($script['inner_status'] === 'waiting_children') {
            return true;
        }
        if (!in_array($script['inner_status'], ['queued', 'in_progress', 'stopping'], true)) {
            return false;
        }
        if (TIME - (int) $script['last_launch'] <= SECONDS_IN_DAY) {
            return true;
        }

        $inner_status = $script['inner_status'] === 'stopping'
            && $script['run_mode'] === self::RUN_MODE_ONCE
            ? 'cancelled'
            : 'scheduled';
        $this->database->query(
            'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i',
            ['inner_status' => $inner_status],
            $script['script_id']
        );
        fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
            'script' => $script['script'],
            'error'  => __('synchro.script_is_running_more_than_one_day')
                . ' ' . __('synchro.inner_status_has_been_updated'),
        ]);

        return false;
    }

    /**
     * Requests interruption of a queued or running task.
     *
     * @param int $script_id Cron script identifier
     *
     * @return bool
     */
    public function requestInterruption($script_id)
    {
        if (!$this->available_scripts) {
            return false;
        }

        $available_scripts = array_keys($this->available_scripts);
        $is_queued_task_cancelled = (bool) $this->database->query(
            'UPDATE ?:cron_scripts SET inner_status = IF(run_mode = ?s, ?s, ?s)'
            . ' WHERE script_id = ?i AND script IN (?a) AND inner_status = ?s',
            self::RUN_MODE_ONCE,
            'cancelled',
            'scheduled',
            $script_id,
            $available_scripts,
            'queued'
        );
        if ($is_queued_task_cancelled) {
            return true;
        }

        return (bool) $this->database->query(
            'UPDATE ?:cron_scripts SET inner_status = ?s'
            . ' WHERE script_id = ?i AND script IN (?a) AND inner_status IN (?a)',
            'stopping',
            $script_id,
            $available_scripts,
            ['in_progress', 'waiting_children']
        );
    }

    /**
     * Keeps a cron task active while its child import processes are running.
     *
     * @param int $script_id Cron script identifier
     *
     * @return bool
     */
    public function markTaskWaitingForChildren($script_id)
    {
        return (bool) $this->database->query(
            'UPDATE ?:cron_scripts SET inner_status = ?s'
            . ' WHERE script_id = ?i AND inner_status = ?s',
            'waiting_children',
            $script_id,
            'in_progress'
        );
    }

    /**
     * Reopens a finalized cron task while a child import is retried.
     *
     * @param int $script_id Cron script identifier
     *
     * @return bool
     */
    public function reopenTaskForChildren($script_id)
    {
        return (bool) $this->database->query(
            'UPDATE ?:cron_scripts SET inner_status = ?s'
            . ' WHERE script_id = ?i AND inner_status IN (?a)',
            'waiting_children',
            $script_id,
            ['scheduled', 'completed', 'partial_success', 'failed', 'cancelled']
        );
    }

    /**
     * Finalizes a cron task after all child import processes have stopped.
     *
     * @param int    $script_id     Cron script identifier
     * @param string $result_status Import result status
     *
     * @return bool
     */
    public function finalizeDeferredTask($script_id, $result_status)
    {
        $allowed_results = ['completed', 'partial_success', 'failed', 'cancelled'];
        if (!in_array($result_status, $allowed_results, true)) {
            return false;
        }

        $script = $this->database->getRow(
            'SELECT run_mode, inner_status FROM ?:cron_scripts WHERE script_id = ?i',
            $script_id
        );
        if (
            !$script
            || !in_array($script['inner_status'], ['waiting_children', 'stopping'], true)
        ) {
            return false;
        }

        $inner_status = $script['run_mode'] === self::RUN_MODE_PERIODIC
            ? 'scheduled'
            : $result_status;

        return (bool) $this->database->query(
            'UPDATE ?:cron_scripts SET inner_status = ?s WHERE script_id = ?i AND inner_status IN (?a)',
            $inner_status,
            $script_id,
            ['waiting_children', 'stopping']
        );
    }

    /**
     * Queues the post-process configured for a completed product import.
     *
     * @param int    $script_id        Source cron task identifier
     * @param int    $parent_import_id Parent import identifier
     * @param string $source_type      Import source type
     * @param string $result_status    Import result status
     *
     * @return bool
     */
    public function queuePostProcess($script_id, $parent_import_id, $source_type, $result_status)
    {
        $source_script = $this->database->getRow(
            'SELECT post_process FROM ?:cron_scripts WHERE script_id = ?i',
            $script_id
        );
        if (!$source_script) {
            $this->logPostProcessError(
                '',
                $parent_import_id,
                __('synchro.post_process_error.source_task_not_found', ['[script_id]' => $script_id])
            );

            return false;
        }
        $target_dispatch = isset($source_script['post_process'])
            ? (string) $source_script['post_process']
            : '';
        if ($target_dispatch === '') {
            return true;
        }

        if (!in_array($result_status, ['completed', 'partial_success'], true)) {
            $this->logPostProcessError(
                $target_dispatch,
                $parent_import_id,
                __('synchro.post_process_error.unsupported_source_status', ['[status]' => $result_status])
            );

            return false;
        }

        if (
            $target_dispatch === self::POST_PROCESS_APPLY_PRODUCTS
            && $source_type === 'test'
        ) {
            $target_dispatch = 'synchro_import.apply_test_products';
        }

        if (
            $target_dispatch === self::POST_PROCESS_APPLY_PRODUCTS
            && $result_status !== 'completed'
        ) {
            $this->logPostProcessError(
                $target_dispatch,
                $parent_import_id,
                __('synchro.post_process_error.full_application_requires_completed', [
                    '[status]' => $result_status,
                ])
            );

            return false;
        }

        if (!$this->isScriptAllowed($target_dispatch)) {
            $this->logPostProcessError(
                $target_dispatch,
                $parent_import_id,
                __('synchro.post_process_error.dispatch_not_allowed')
            );

            return false;
        }

        $target_script_id = $this->claimPostProcessTask($target_dispatch, $parent_import_id);
        if (!$target_script_id) {
            return false;
        }

        if (!$this->startPostProcessTask($target_script_id, $target_dispatch, $parent_import_id)) {
            return false;
        }

        return true;
    }

    /**
     * Atomically reserves a post-process task for an import.
     *
     * @param string $dispatch  Target controller dispatch
     * @param int    $import_id Source import identifier
     *
     * @return int Queued cron task identifier
     */
    private function claimPostProcessTask($dispatch, $import_id)
    {
        $target_script = $this->database->getRow(
            'SELECT * FROM ?:cron_scripts WHERE script = ?s LIMIT 1',
            $dispatch
        );
        $task_data = [
            'status'            => 'A',
            'run_mode'          => self::RUN_MODE_ONCE,
            'inner_status'      => 'queued',
            'runtime_import_id' => $import_id,
            'last_launch'       => TIME,
        ];

        if ($target_script) {
            $script_id = (int) $target_script['script_id'];
            $is_queued = (bool) $this->database->query(
                'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i AND inner_status IN (?a)',
                $task_data,
                $script_id,
                ['scheduled', 'completed', 'partial_success', 'failed', 'cancelled']
            );
            if (!$is_queued) {
                $this->logPostProcessError(
                    $dispatch,
                    $import_id,
                    __('synchro.post_process_error.task_not_available', [
                        '[script_id]'    => $script_id,
                        '[inner_status]' => isset($target_script['inner_status'])
                            ? (string) $target_script['inner_status']
                            : '',
                    ])
                );

                return 0;
            }

            return $script_id;
        }

        $task_data += [
            'script'           => $dispatch,
            'period_week_days' => 'monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'created'          => TIME,
        ];
        $insert_result = (int) $this->database->query('INSERT INTO ?:cron_scripts ?e', $task_data);
        if (!$insert_result) {
            $this->logPostProcessError(
                $dispatch,
                $import_id,
                __('synchro.post_process_error.task_creation_failed')
            );

            return 0;
        }

        return $insert_result;
    }

    /**
     * Starts a previously queued post-process task in background.
     *
     * @param int    $script_id Cron task identifier
     * @param string $dispatch  Target controller dispatch
     * @param int    $import_id Source import identifier
     *
     * @return bool
     */
    private function startPostProcessTask($script_id, $dispatch, $import_id)
    {
        $output = [];
        $exit_code = 0;
        exec($this->prepareBackgroundCommand($script_id), $output, $exit_code);
        if ($exit_code === 0) {
            return true;
        }

        $this->database->query(
            'UPDATE ?:cron_scripts SET inner_status = ?s WHERE script_id = ?i AND inner_status = ?s',
            'failed',
            $script_id,
            'queued'
        );
        $this->logPostProcessError(
            $dispatch,
            $import_id,
            __('synchro.post_process_error.task_start_failed', [
                '[script_id]' => $script_id,
                '[exit_code]' => $exit_code,
                '[output]'    => implode(PHP_EOL, $output),
            ])
        );

        return false;
    }

    /**
     * Logs why a post-process task could not be queued.
     *
     * @param string $dispatch  Target controller dispatch
     * @param int    $import_id Source import identifier
     * @param string $reason    Failure details
     *
     * @return void
     */
    private function logPostProcessError($dispatch, $import_id, $reason)
    {
        fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
            'script' => $dispatch,
            'error'  => __('synchro.post_process_not_queued', [
                '[dispatch]'  => $dispatch,
                '[import_id]' => $import_id,
                '[reason]'    => $reason,
            ]),
        ]);
    }

    /**
     * Stops task processing when an interruption has been requested.
     *
     * @param int $script_id Cron script identifier
     *
     * @return void
     *
     * @throws \Tygh\Addons\Synchro\Exceptions\TaskInterruptedException When task interruption is requested.
     */
    public function ensureTaskCanContinue($script_id)
    {
        if (!$script_id) {
            return;
        }

        $inner_status = $this->database->getField(
            'SELECT inner_status FROM ?:cron_scripts WHERE script_id = ?i',
            $script_id
        );
        if ($inner_status === 'stopping') {
            throw new TaskInterruptedException('Cron task interruption requested');
        }
    }

    /**
     * Logs an attempt to launch an already running script.
     *
     * @param array<array-key, array<array-key, scalar>|scalar|null> $script Cron script data
     *
     * @return void
     */
    protected function logAlreadyRunningError(array $script)
    {
        fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
            'script' => $script['script'],
            'error'  => __('synchro.script_is_already_running'),
        ]);
    }

    /**
     * Gets calendar time values for a timestamp.
     *
     * @param int|null $timestamp Unix timestamp
     *
     * @return array{month_day: string, week_day: string, hours: int, minutes: int}
     */
    public function getTimeVars($timestamp = null)
    {
        $timestamp = $timestamp === null ? TIME : $timestamp;

        return [
            'month_day' => strtolower(date('j', $timestamp)),
            'week_day'  => strtolower(date('l', $timestamp)),
            'hours'     => (int) date('G', $timestamp),
            'minutes'   => (int) date('i', $timestamp),
        ];
    }

    /**
     * Prepares a cron script command for launching.
     *
     * @param string $script    Controller dispatch
     * @param int    $script_id Cron script identifier
     * @param int    $import_id Source import identifier
     *
     * @return string
     */
    public function prepareScript($script, $script_id = 0, $import_id = 0)
    {
        $arguments = ['--dispatch=' . $script];
        if ($script_id) {
            $arguments[] = '--cron_script_id=' . $script_id;
        }
        if ($import_id) {
            $arguments[] = '--import_id=' . $import_id;
        }

        return $this->prepareCommand($arguments);
    }

    /**
     * Prepares a command for starting the dedicated task runner in background.
     *
     * @param int $script_id Cron script identifier
     *
     * @return string
     */
    public function prepareBackgroundCommand($script_id)
    {
        return $this->prepareCommand([
            '--dispatch=cron_script_manager.run',
            '--cron_password=' . $this->cron_password,
            '--script_id=' . $script_id,
        ]) . ' > /dev/null 2>&1 &';
    }

    /**
     * Prepares a CLI command.
     *
     * @param array<string> $arguments Command arguments
     *
     * @return string
     */
    protected function prepareCommand(array $arguments)
    {
        $command = [
            $this->php_binary,
            $this->root_directory . '/' . $this->admin_index,
        ];

        return implode(' ', array_map('escapeshellarg', array_merge($command, $arguments)));
    }

    /**
     * Checks whether another task has the same controller and mode.
     *
     * @param string $script    Controller dispatch
     * @param int    $script_id Cron script identifier to exclude
     *
     * @return bool
     */
    protected function hasScriptDuplicate($script, $script_id)
    {
        return (bool) $this->database->getField(
            'SELECT script_id FROM ?:cron_scripts'
            . ' WHERE script = ?s AND script_id != ?i LIMIT 1',
            $script,
            $script_id
        );
    }

    /**
     * Checks whether a task with the same controller and mode is running.
     *
     * @param string $script Controller dispatch
     *
     * @return bool
     */
    protected function hasRunningScript($script)
    {
        $running_script = $this->database->getRow(
            'SELECT * FROM ?:cron_scripts WHERE script = ?s AND inner_status = ?s LIMIT 1',
            $script,
            'in_progress'
        );

        return $running_script ? $this->isCronScriptRunning($running_script) : false;
    }

    /**
     * Updates an intermediate task status.
     *
     * @param int    $script_id Cron script identifier
     * @param string $status    Progress status
     *
     * @return bool|int
     */
    public function updateProgressStatus($script_id, $status)
    {
        $result = $this->database->query(
            'UPDATE ?:cron_scripts SET progress_status = ?s WHERE script_id = ?i',
            $status,
            $script_id
        );

        return $result === false ? false : (int) $result;
    }

    /**
     * Gets allowed SET or ENUM field values.
     *
     * @param string $field_name         Field name
     * @param string $table_name         Table name without a prefix
     * @param bool   $get_with_lang_vars Whether translated values must be returned
     * @param string $lang_code          Two-letter language code
     *
     * @return array<string>|array<string, string>|false
     */
    public function getSetElements(
        $field_name,
        $table_name,
        $get_with_lang_vars = false,
        $lang_code = DESCR_SL
    ) {
        $cache_key = $table_name . '.' . $field_name;
        if (empty($this->set_elements[$cache_key])) {
            $column_info = $this->database->getRow(
                'SHOW COLUMNS FROM ?:?p WHERE Field = ?s',
                $table_name,
                $field_name
            );
            if (
                empty($column_info)
                || !preg_match('/^(?P<type>\w{3,4})/s', (string) $column_info['Type'], $matches)
                || ($matches['type'] !== 'set' && $matches['type'] !== 'enum')
            ) {
                return false;
            }

            $set_elements = explode("','", (string) $column_info['Type']);
            $set_elements[0] = str_replace($matches['type'] . "('", '', $set_elements[0]);
            $last_element = count($set_elements) - 1;
            $set_elements[$last_element] = str_replace("')", '', $set_elements[$last_element]);
            $this->set_elements[$cache_key] = $set_elements;
        }

        $set_elements = $this->set_elements[$cache_key];
        if (!$get_with_lang_vars) {
            return $set_elements;
        }

        $translated_elements = [];
        foreach ($set_elements as $element) {
            $translated_elements[$element] = __('synchro.' . $element, [], $lang_code);
        }

        return $translated_elements;
    }

    /**
     * Builds a SQL condition for finding any array value in a SET field.
     *
     * @param array<int|string, int|string> $values     Values to find
     * @param string                        $field_name Qualified field name
     * @param bool                          $find_empty Whether an empty value must match
     *
     * @return string
     */
    public function findArrayInSet(array $values, $field_name, $find_empty = false)
    {
        $conditions = [];
        if ($find_empty) {
            $conditions[] = $field_name . " = ''";
        }
        foreach ($values as $value) {
            $conditions[] = $this->database->quote('FIND_IN_SET(?s, ' . $field_name . ')', $value);
        }

        return implode(' OR ', $conditions);
    }

    /**
     * Gets translated short names of the specified weekdays.
     *
     * @param array<int, string> $weekdays Weekday identifiers
     *
     * @return string
     */
    public function showShortWeekdays(array $weekdays)
    {
        $period_week_days = $this->getSetElements('period_week_days', 'cron_scripts');
        if ($period_week_days === false) {
            return '';
        }

        $all_days = true;
        $translated_weekdays = [];
        foreach ($period_week_days as $weekday) {
            if (in_array($weekday, $weekdays, true)) {
                $translated_weekdays[] = __('synchro.' . $weekday);
            } else {
                $all_days = false;
            }
        }

        return $all_days ? __('all') : implode(', ', $translated_weekdays);
    }

    /**
     * Creates or updates a cron script.
     *
     * @param array<string, array<string>|int|string|null> $script_data Cron script data
     * @param int                                          $script_id   Cron script identifier
     *
     * @return bool|int
     */
    public function updateScriptData(array $script_data, $script_id = 0)
    {
        if (
            empty($script_data['script'])
            || !$this->isScriptAllowed((string) $script_data['script'])
            || $this->hasScriptDuplicate((string) $script_data['script'], $script_id)
        ) {
            return false;
        }

        $script_data['run_mode'] = isset($script_data['run_mode'])
            && in_array($script_data['run_mode'], [self::RUN_MODE_PERIODIC, self::RUN_MODE_ONCE], true)
            ? $script_data['run_mode']
            : self::RUN_MODE_PERIODIC;

        if ($script_data['script'] === 'synchro_import.products') {
            $script_data['use_portions'] = isset($script_data['use_portions'])
                && $script_data['use_portions'] === 'Y' ? 'Y' : 'N';
            $script_data['pages_per_portion'] = max(
                1,
                isset($script_data['pages_per_portion'])
                    ? (int) $script_data['pages_per_portion']
                    : ProductImportRangeBuilder::DEFAULT_PAGES_PER_PORTION
            );
            $script_data['page_limit'] = max(
                1,
                isset($script_data['page_limit'])
                    ? (int) $script_data['page_limit']
                    : ProductImportRangeBuilder::DEFAULT_PAGE_LIMIT
            );
            $script_data['max_parallel_processes'] = max(
                1,
                isset($script_data['max_parallel_processes'])
                    ? (int) $script_data['max_parallel_processes']
                    : ProductImportRangeBuilder::DEFAULT_MAX_PARALLEL_PROCESSES
            );
            $script_data['is_test_import'] = isset($script_data['is_test_import'])
                && $script_data['is_test_import'] === 'Y' ? 'Y' : 'N';
            $script_data['test_page'] = max(
                1,
                isset($script_data['test_page']) ? (int) $script_data['test_page'] : 1
            );

            if ($script_data['is_test_import'] === 'Y') {
                $script_data['use_portions'] = 'N';
                $script_data['pages_per_portion'] = 1;
                $script_data['page_limit'] = ProductImportRangeBuilder::TEST_PAGE_LIMIT;
                $script_data['max_parallel_processes'] = 1;
            }
        }

        $allowed_post_processes = [
            'synchro_import.products' => [
                '',
                self::POST_PROCESS_APPLY_PRODUCTS,
                self::POST_PROCESS_ACTUALIZE_PRODUCTS,
            ],
            'synchro_import.categories' => [
                '',
                self::POST_PROCESS_APPLY_CATEGORIES,
            ],
        ];
        $script_data['post_process'] = isset($script_data['post_process'])
            && in_array(
                $script_data['post_process'],
                isset($allowed_post_processes[$script_data['script']])
                    ? $allowed_post_processes[$script_data['script']]
                    : [''],
                true
            )
            ? $script_data['post_process']
            : '';

        $current_script = $script_id
            ? $this->database->getRow(
                'SELECT run_mode, inner_status FROM ?:cron_scripts WHERE script_id = ?i',
                $script_id
            )
            : [];
        $reset_inner_status = $current_script
            && $current_script['run_mode'] !== $script_data['run_mode'];
        if (
            $reset_inner_status
            && in_array($current_script['inner_status'], ['queued', 'in_progress'], true)
        ) {
            $script_data['run_mode'] = $current_script['run_mode'];
            $reset_inner_status = false;
        }

        $script_data = array_intersect_key($script_data, array_flip([
            'script',
            'description',
            'status',
            'period_month_days',
            'period_week_days',
            'period_hours_begin',
            'period_hours_end',
            'refresh_hours',
            'refresh_minutes',
            'run_mode',
            'use_portions',
            'pages_per_portion',
            'page_limit',
            'max_parallel_processes',
            'is_test_import',
            'test_page',
            'post_process',
        ]));
        if ($reset_inner_status) {
            $script_data['inner_status'] = 'scheduled';
        }

        $month_days_clause = '';
        $script_data['period_week_days'] = is_array($script_data['period_week_days'])
            ? implode(',', $script_data['period_week_days'])
            : $script_data['period_week_days'];

        if (array_key_exists('period_month_days', $script_data)) {
            $script_data['period_month_days'] = !empty($script_data['period_month_days'])
                && is_array($script_data['period_month_days'])
                ? implode(',', $script_data['period_month_days'])
                : null;

            if ($script_data['period_month_days'] === null) {
                unset($script_data['period_month_days']);
                $month_days_clause = ', period_month_days = NULL';
            }
        }

        if ($script_id) {
            $this->database->query(
                'UPDATE ?:cron_scripts SET ?u ?p WHERE script_id = ?i',
                $script_data,
                $month_days_clause,
                $script_id
            );
        } else {
            $script_data['created'] = TIME;
            $script_id = (int) $this->database->query('INSERT INTO ?:cron_scripts ?e', $script_data);
        }

        return $script_id ? (int) $script_id : false;
    }
}
