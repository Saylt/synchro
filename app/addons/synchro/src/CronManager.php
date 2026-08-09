<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Database\Connection;
use Tygh\Navigation\LastView;

/**
 * Manages cron scripts and provides cron runner information.
 */
class CronManager
{
    const MINUTES_IN_HOUR = 60;

    const HOURS_IN_DAY = 24;

    const MIN_SECONDS_BETWEEN_RUNS = 50;

    /**
     * @var \Tygh\Database\Connection
     */
    protected $database;

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
    protected $customer_index;

    /**
     * @var string
     */
    protected $cron_password;

    /**
     * @var array<string, array<string>>
     */
    protected $set_elements = [];

    /**
     * @param \Tygh\Database\Connection $database       Database connection
     * @param string                    $root_directory Store root directory
     * @param string                    $admin_index    Administration entry point
     * @param string                    $customer_index Storefront entry point
     * @param string                    $cron_password  Cron access password
     */
    public function __construct(
        Connection $database,
        $root_directory,
        $admin_index,
        $customer_index,
        $cron_password
    ) {
        $this->database = $database;
        $this->root_directory = rtrim($root_directory, '/');
        $this->admin_index = $admin_index;
        $this->customer_index = $customer_index;
        $this->cron_password = $cron_password;
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
            'type'         => 's.script_type',
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
        $condition = '';

        if (isset($params['script']) && fn_string_no_empty($params['script'])) {
            $condition .= $this->database->quote(
                ' AND s.script LIKE ?l',
                '%' . trim((string) $params['script']) . '%'
            );
        }
        if (!empty($params['script_type'])) {
            $condition .= $this->database->quote(' AND s.script_type LIKE ?l', $params['script_type']);
        }
        if (!empty($params['status'])) {
            $condition .= $this->database->quote(' AND s.status LIKE ?l', $params['status']);
        }
        if (!empty($params['inner_status'])) {
            $condition .= $this->database->quote(' AND s.inner_status LIKE ?l', $params['inner_status']);
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
        $script_data = $this->database->getRow(
            'SELECT * FROM ?:cron_scripts WHERE script_id = ?i',
            $script_id
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
        return $this->database->query('DELETE FROM ?:cron_scripts WHERE script_id = ?i', $script_id);
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
        $this->database->query(
            'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i',
            [
                'inner_status' => 'in_progress',
                'last_launch'  => time(),
            ],
            $script['script_id']
        );

        $start_time = time();
        $command = $this->prepareScript($script['script'], $script['script_type']);
        $output = [];
        exec($command, $output);
        $execution_time = time() - $start_time;

        fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_LAUNCH, [
            'script'         => $script['script'],
            'type'           => $script['script_type'],
            'execution_time' => $execution_time,
            'output'         => $output,
        ]);

        $this->database->query(
            'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i',
            ['inner_status' => 'scheduled'],
            $script['script_id']
        );

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
     * Checks whether a running cron script can be relaunched.
     *
     * @param array<string, array<int, string>|int|string|null> $script Cron script data
     *
     * @return bool
     */
    public function checkCronScriptState(array $script)
    {
        if (TIME - $script['last_launch'] > SECONDS_IN_DAY) {
            $this->database->query(
                'UPDATE ?:cron_scripts SET ?u WHERE script_id = ?i',
                ['inner_status' => 'scheduled'],
                $script['script_id']
            );
            fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
                'script' => $script['script'],
                'type'   => $script['script_type'],
                'error'  => __('synchro.script_is_running_more_than_one_day')
                    . ' ' . __('synchro.inner_status_has_been_updated'),
            ]);

            return true;
        }

        fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
            'script' => $script['script'],
            'type'   => $script['script_type'],
            'error'  => __('synchro.script_is_already_running'),
        ]);

        return false;
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
     * @param string $script Script dispatch or custom command
     * @param string $type   Script type
     *
     * @return string
     */
    public function prepareScript($script, $type)
    {
        $index_script = '';
        if ($type === 'from_admin_area') {
            $index_script = $this->admin_index;
        } elseif ($type === 'from_customer_area') {
            $index_script = $this->customer_index;
        }

        if ($index_script) {
            $script = 'php ./' . $index_script . ' --dispatch=' . $script;
        }

        return $script;
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
            $script_id = $this->database->query('INSERT INTO ?:cron_scripts ?e', $script_data);
        }

        return $script_id;
    }
}
