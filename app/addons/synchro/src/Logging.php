<?php

namespace Tygh\Addons\Synchro;

use Tygh\Database\Connection;

/**
 * Stores Synchro events in the add-on journal.
 */
class Logging
{
    const TABLE_NAME = 'synchro_logs';

    const LEVEL_INFO = 'info';

    const LEVEL_WARNING = 'warning';

    const LEVEL_ERROR = 'error';

    /** @var \Tygh\Database\Connection */
    private $database;

    /**
     * @param \Tygh\Database\Connection $database Database connection
     */
    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * Stores an informational event.
     *
     * @param string                    $source  Event source
     * @param string                    $message Event message
     * @param array<string, int|string> $context Additional event data
     *
     * @return void
     */
    public function info($source, $message, array $context = [])
    {
        $this->log(self::LEVEL_INFO, $source, $message, $context);
    }

    /**
     * Stores a warning event.
     *
     * @param string                    $source  Event source
     * @param string                    $message Event message
     * @param array<string, int|string> $context Additional event data
     *
     * @return void
     */
    public function warning($source, $message, array $context = [])
    {
        $this->log(self::LEVEL_WARNING, $source, $message, $context);
    }

    /**
     * Stores an error event.
     *
     * @param string                    $source  Event source
     * @param string                    $message Event message
     * @param array<string, int|string> $context Additional event data
     *
     * @return void
     */
    public function error($source, $message, array $context = [])
    {
        $this->log(self::LEVEL_ERROR, $source, $message, $context);
    }

    /**
     * Stores an event without affecting the importing process on failure.
     *
     * @param string                    $level   Event level
     * @param string                    $source  Event source
     * @param string                    $message Event message
     * @param array<string, int|string> $context Additional event data
     *
     * @return void
     */
    public function log($level, $source, $message, array $context = [])
    {
        $serialized_context = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        try {
            $this->database->query('INSERT INTO ?:?p ?e', self::TABLE_NAME, [
                'timestamp' => TIME,
                'level'     => $level,
                'source'    => $source,
                'message'   => $message,
                'context'   => is_string($serialized_context) ? $serialized_context : '',
            ]);
        } catch (\Throwable $exception) {
        }
    }

    /**
     * Removes entries older than the configured retention period.
     *
     * @param int $days Number of days to retain
     *
     * @return int
     */
    public function removeOlderThan($days)
    {
        $days = (int) $days;
        if ($days < 1) {
            return 0;
        }

        return (int) $this->database->query(
            'DELETE FROM ?:?p WHERE timestamp < ?i',
            self::TABLE_NAME,
            TIME - $days * SECONDS_IN_DAY
        );
    }

    /**
     * Gets journal entries by the specified search parameters.
     *
     * @param array<string, array<int, string>|int|string|null> $params         Search parameters
     * @param int                                               $items_per_page Number of entries per page
     *
     * @return array{array<int, array<string, int|string>>, array<string, int|string>}
     */
    public function getLogs(array $params = [], $items_per_page = 0)
    {
        $params['page'] = empty($params['page']) ? 1 : (int) $params['page'];
        $condition = '';

        if (isset($params['level']) && in_array($params['level'], $this->getLevels(), true)) {
            $condition .= $this->database->quote(' AND l.level = ?s', $params['level']);
        }
        $params['source'] = array_values(array_filter(
            isset($params['source']) ? (array) $params['source'] : [],
            static function ($source) {
                return is_string($source) && trim($source) !== '';
            }
        ));
        if ($params['source']) {
            $condition .= $this->database->quote(' AND l.source IN (?a)', $params['source']);
        }
        if (!empty($params['period']) && $params['period'] !== 'A') {
            list($params['time_from'], $params['time_to']) = fn_create_periods($params);
            $condition .= $this->database->quote(
                ' AND (l.timestamp >= ?i AND l.timestamp <= ?i)',
                $params['time_from'],
                $params['time_to']
            );
        }

        $sortings = [
            'timestamp' => 'l.timestamp',
            'level'     => 'l.level',
            'source'    => 'l.source',
        ];
        $directions = ['asc' => 'asc', 'desc' => 'desc'];
        if (!isset($params['sort_by']) || !isset($sortings[$params['sort_by']])) {
            $params['sort_by'] = 'timestamp';
        }
        if (!isset($params['sort_order']) || !isset($directions[$params['sort_order']])) {
            $params['sort_order'] = 'desc';
        }

        $limit = '';
        if ($items_per_page) {
            $total = $this->database->getField(
                'SELECT COUNT(*) FROM ?:?p AS l WHERE 1 ?p',
                self::TABLE_NAME,
                $condition
            );
            $params['items_per_page'] = $items_per_page;
            $params['total_items'] = (int) $total;
            $limit = db_paginate($params['page'], $items_per_page, $total);
        }
        $logs = $this->database->getHash(
            'SELECT l.* FROM ?:?p AS l WHERE 1 ?p ORDER BY ?p ?p ?p',
            'log_id',
            self::TABLE_NAME,
            $condition,
            $sortings[$params['sort_by']],
            $directions[$params['sort_order']],
            $limit
        );
        $params['sort_order'] = $params['sort_order'] === 'asc' ? 'desc' : 'asc';

        return [$logs, $params];
    }

    /**
     * Gets log sources available for filtering.
     *
     * @return array<string>
     */
    public function getSources()
    {
        return $this->database->getColumn(
            'SELECT DISTINCT source FROM ?:?p WHERE 1 ORDER BY source',
            self::TABLE_NAME
        );
    }

    /**
     * Gets supported journal levels.
     *
     * @return array<string>
     */
    public function getLevels()
    {
        return [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR];
    }
}
