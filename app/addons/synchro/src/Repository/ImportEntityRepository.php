<?php

namespace Tygh\Addons\Synchro\Repository;

use UnexpectedValueException;
use Throwable;
use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Dto\RepresentEntityDto;
use Tygh\Database\Connection;

/**
 * Stores normalized external API entities before they are imported into CS-Cart.
 */
class ImportEntityRepository
{
    const IMPORTS_TABLE_NAME = 'synchro_imports';

    const TABLE_NAME = 'synchro_import_entities';

    const STATUS_QUEUED = 'queued';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_PARTIAL_SUCCESS = 'partial_success';

    const STATUS_FAILED = 'failed';

    const STATUS_STOPPING = 'stopping';

    const STATUS_CANCELLED = 'cancelled';

    const SOURCE_TYPE_FULL = 'full';

    const SOURCE_TYPE_TEST = 'test';

    /**
     * @var \Tygh\Database\Connection
     */
    private $database;

    /**
     * @param \Tygh\Database\Connection $database Database connection
     */
    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * Creates a root import run.
     *
     * @param int    $company_id     Company identifier
     * @param string $entity_type    Root entity type
     * @param int    $cron_script_id Cron script identifier
     * @param array  $plan           Product import plan
     *
     * @psalm-param array{
     *     page_limit: int,
     *     total_items: int,
     *     total_pages: int,
     *     max_parallel_processes: int,
     *     source_type?: string
     * } $plan
     *
     * @return int
     */
    public function createParentImport($company_id, $entity_type, $cron_script_id, array $plan)
    {
        $source_type = isset($plan['source_type']) && $plan['source_type'] === self::SOURCE_TYPE_TEST
            ? self::SOURCE_TYPE_TEST
            : self::SOURCE_TYPE_FULL;

        return $this->database->replaceInto(self::IMPORTS_TABLE_NAME, [
            'parent_import_id'       => 0,
            'cron_script_id'         => $cron_script_id,
            'company_id'             => $company_id,
            'entity_type'            => $entity_type,
            'source_type'            => $source_type,
            'status'                 => self::STATUS_PROCESSING,
            'page_limit'             => $plan['page_limit'],
            'total_items'            => $plan['total_items'],
            'total_pages'            => $plan['total_pages'],
            'max_parallel_processes' => $plan['max_parallel_processes'],
            'error_message'          => '',
            'created_at'             => TIME,
            'started_at'             => TIME,
            'updated_at'             => TIME,
            'completed_at'           => 0,
        ]);
    }

    /**
     * Creates a parent import and every child range atomically.
     *
     * @param int    $company_id     Company identifier
     * @param string $entity_type    Root entity type
     * @param int    $cron_script_id Cron script identifier
     * @param array  $plan           Product import plan
     *
     * @psalm-param array{
     *     page_limit: int,
     *     total_items: int,
     *     total_pages: int,
     *     max_parallel_processes: int,
     *     source_type?: string,
     *     ranges: array<array-key, array{page_from: int, page_to: int}>
     * } $plan
     *
     * @return int Parent import identifier
     *
     * @throws \Throwable When the hierarchy cannot be created.
     */
    public function createImportHierarchy($company_id, $entity_type, $cron_script_id, array $plan)
    {
        $source_type = isset($plan['source_type']) && $plan['source_type'] === self::SOURCE_TYPE_TEST
            ? self::SOURCE_TYPE_TEST
            : self::SOURCE_TYPE_FULL;
        $this->database->beginTransaction();

        try {
            $parent_import_id = $this->createParentImport(
                $company_id,
                $entity_type,
                $cron_script_id,
                $plan
            );
            $this->createChildImports(
                $parent_import_id,
                $company_id,
                $entity_type,
                $cron_script_id,
                $plan['ranges'],
                $plan['page_limit'],
                $source_type
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }

        return $parent_import_id;
    }

    /**
     * Creates child import runs for page ranges.
     *
     * @param int    $parent_import_id Parent import identifier
     * @param int    $company_id       Company identifier
     * @param string $entity_type      Root entity type
     * @param int    $cron_script_id   Cron script identifier
     * @param array  $ranges           Page ranges
     * @param int    $page_limit       API page limit
     * @param string $source_type      Staged snapshot type
     *
     * @psalm-param array<array-key, array{page_from: int, page_to: int}> $ranges
     *
     * @return array<int>
     */
    public function createChildImports(
        $parent_import_id,
        $company_id,
        $entity_type,
        $cron_script_id,
        array $ranges,
        $page_limit,
        $source_type = self::SOURCE_TYPE_FULL
    ) {
        $source_type = $source_type === self::SOURCE_TYPE_TEST
            ? self::SOURCE_TYPE_TEST
            : self::SOURCE_TYPE_FULL;
        $import_ids = [];

        foreach ($ranges as $range) {
            $import_ids[] = $this->database->replaceInto(self::IMPORTS_TABLE_NAME, [
                'parent_import_id' => $parent_import_id,
                'cron_script_id'   => $cron_script_id,
                'company_id'       => $company_id,
                'entity_type'      => $entity_type,
                'source_type'      => $source_type,
                'status'           => self::STATUS_QUEUED,
                'page_from'        => $range['page_from'],
                'page_to'          => $range['page_to'],
                'current_page'     => 0,
                'page_limit'       => $page_limit,
                'error_message'    => '',
                'created_at'       => TIME,
                'started_at'       => 0,
                'updated_at'       => TIME,
                'completed_at'     => 0,
            ]);
        }

        return $import_ids;
    }

    /**
     * Finds an import run.
     *
     * @param int $import_id Import identifier
     *
     * @return array<string, int|string>
     */
    public function findImport($import_id)
    {
        return $this->database->getRow(
            'SELECT * FROM ?:?p WHERE import_id = ?i',
            self::IMPORTS_TABLE_NAME,
            $import_id
        );
    }

    /**
     * Finds child import runs.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return array<array-key, array<string, int|string>>
     */
    public function findChildren($parent_import_id)
    {
        return $this->database->getArray(
            'SELECT * FROM ?:?p WHERE parent_import_id = ?i ORDER BY page_from, import_id',
            self::IMPORTS_TABLE_NAME,
            $parent_import_id
        );
    }

    /**
     * Finds the latest parent import for a cron task.
     *
     * @param int $cron_script_id Cron script identifier
     *
     * @return array<string, int|string>
     */
    public function findLatestParentByCronScriptId($cron_script_id)
    {
        return $this->database->getRow(
            'SELECT * FROM ?:?p WHERE cron_script_id = ?i AND parent_import_id = ?i'
            . ' ORDER BY import_id DESC LIMIT 1',
            self::IMPORTS_TABLE_NAME,
            $cron_script_id,
            0
        );
    }

    /**
     * Finds active parent imports.
     *
     * @return array<array-key, array<string, int|string>>
     */
    public function findActiveParents()
    {
        return $this->database->getArray(
            'SELECT * FROM ?:?p WHERE parent_import_id = ?i AND status IN (?a) ORDER BY import_id',
            self::IMPORTS_TABLE_NAME,
            0,
            [self::STATUS_PROCESSING, self::STATUS_STOPPING]
        );
    }

    /**
     * Counts currently running child imports.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return int
     */
    public function countRunningChildren($parent_import_id)
    {
        return (int) $this->database->getField(
            'SELECT COUNT(*) FROM ?:?p WHERE parent_import_id = ?i AND status IN (?a)',
            self::IMPORTS_TABLE_NAME,
            $parent_import_id,
            [self::STATUS_PROCESSING, self::STATUS_STOPPING]
        );
    }

    /**
     * Finds queued child imports.
     *
     * @param int $parent_import_id Parent import identifier
     * @param int $limit            Maximum number of children
     *
     * @return array<array-key, array<string, int|string>>
     */
    public function findQueuedChildren($parent_import_id, $limit)
    {
        return $this->database->getArray(
            'SELECT * FROM ?:?p WHERE parent_import_id = ?i AND status = ?s'
            . ' ORDER BY page_from, import_id LIMIT ?i',
            self::IMPORTS_TABLE_NAME,
            $parent_import_id,
            self::STATUS_QUEUED,
            $limit
        );
    }

    /**
     * Finds child processes that stopped reporting progress.
     *
     * @param int $updated_before Maximum activity timestamp
     *
     * @return array<array-key, array<string, int|string>>
     */
    public function findStaleChildren($updated_before)
    {
        return $this->database->getArray(
            'SELECT * FROM ?:?p WHERE parent_import_id != ?i AND status IN (?a) AND updated_at < ?i'
            . ' ORDER BY parent_import_id, import_id',
            self::IMPORTS_TABLE_NAME,
            0,
            [self::STATUS_PROCESSING, self::STATUS_STOPPING],
            $updated_before
        );
    }

    /**
     * Atomically claims a queued child import.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     */
    public function claimChild($import_id)
    {
        return (bool) $this->database->query(
            'UPDATE ?:?p SET status = ?s, started_at = ?i, updated_at = ?i'
            . ' WHERE import_id = ?i AND parent_import_id != ?i AND status = ?s',
            self::IMPORTS_TABLE_NAME,
            self::STATUS_PROCESSING,
            TIME,
            TIME,
            $import_id,
            0,
            self::STATUS_QUEUED
        );
    }

    /**
     * Updates current page and activity time of a child import.
     *
     * @param int $import_id Import identifier
     * @param int $page      Current page
     *
     * @return bool
     */
    public function updateChildProgress($import_id, $page)
    {
        return (bool) $this->database->query(
            'UPDATE ?:?p SET current_page = ?i, updated_at = ?i'
            . ' WHERE import_id = ?i AND status IN (?a)',
            self::IMPORTS_TABLE_NAME,
            $page,
            TIME,
            $import_id,
            [self::STATUS_PROCESSING, self::STATUS_STOPPING]
        );
    }

    /**
     * Completes a child import.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     */
    public function completeChild($import_id)
    {
        return (bool) $this->database->query(
            'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i'
            . ' WHERE import_id = ?i AND status = ?s',
            self::IMPORTS_TABLE_NAME,
            self::STATUS_COMPLETED,
            TIME,
            TIME,
            $import_id,
            self::STATUS_PROCESSING
        );
    }

    /**
     * Cancels an interrupted child import and removes its incomplete entities.
     *
     * @param int $import_id Import identifier
     *
     * @return void
     *
     * @throws \Throwable When child import cannot be cancelled.
     */
    public function cancelChild($import_id)
    {
        $this->database->beginTransaction();

        try {
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id = ?i',
                self::TABLE_NAME,
                $import_id
            );
            $this->database->query(
                'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i'
                . ' WHERE import_id = ?i AND parent_import_id != ?i AND status IN (?a)',
                self::IMPORTS_TABLE_NAME,
                self::STATUS_CANCELLED,
                TIME,
                TIME,
                $import_id,
                0,
                [self::STATUS_PROCESSING, self::STATUS_STOPPING]
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }
    }

    /**
     * Requests cooperative interruption of one child import.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     */
    public function requestChildInterruption($import_id)
    {
        $cancelled = (bool) $this->database->query(
            'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i'
            . ' WHERE import_id = ?i AND parent_import_id != ?i AND status = ?s',
            self::IMPORTS_TABLE_NAME,
            self::STATUS_CANCELLED,
            TIME,
            TIME,
            $import_id,
            0,
            self::STATUS_QUEUED
        );
        $stopping = (bool) $this->database->query(
            'UPDATE ?:?p SET status = ?s, updated_at = ?i'
            . ' WHERE import_id = ?i AND parent_import_id != ?i AND status = ?s',
            self::IMPORTS_TABLE_NAME,
            self::STATUS_STOPPING,
            TIME,
            $import_id,
            0,
            self::STATUS_PROCESSING
        );

        return $cancelled || $stopping;
    }

    /**
     * Requests interruption of a parent and every unfinished child.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return bool
     */
    public function requestParentInterruption($parent_import_id)
    {
        $updated = (bool) $this->database->query(
            'UPDATE ?:?p SET status = ?s, updated_at = ?i'
            . ' WHERE import_id = ?i AND parent_import_id = ?i AND status = ?s',
            self::IMPORTS_TABLE_NAME,
            self::STATUS_STOPPING,
            TIME,
            $parent_import_id,
            0,
            self::STATUS_PROCESSING
        );
        if (!$updated) {
            return false;
        }

        $this->database->query(
            'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i'
            . ' WHERE parent_import_id = ?i AND status = ?s',
            self::IMPORTS_TABLE_NAME,
            self::STATUS_CANCELLED,
            TIME,
            TIME,
            $parent_import_id,
            self::STATUS_QUEUED
        );
        $this->database->query(
            'UPDATE ?:?p SET status = ?s, updated_at = ?i'
            . ' WHERE parent_import_id = ?i AND status = ?s',
            self::IMPORTS_TABLE_NAME,
            self::STATUS_STOPPING,
            TIME,
            $parent_import_id,
            self::STATUS_PROCESSING
        );

        return true;
    }

    /**
     * Requeues a failed or cancelled child and reopens its parent.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     */
    public function retryChild($import_id)
    {
        $child = $this->findImport($import_id);
        if (
            !$child
            || !(int) $child['parent_import_id']
            || !in_array($child['status'], [self::STATUS_FAILED, self::STATUS_CANCELLED], true)
        ) {
            return false;
        }

        $this->database->query(
            'DELETE FROM ?:?p WHERE import_id = ?i',
            self::TABLE_NAME,
            $import_id
        );
        $updated = (bool) $this->database->query(
            'UPDATE ?:?p SET ?u WHERE import_id = ?i AND status IN (?a)',
            self::IMPORTS_TABLE_NAME,
            [
                'status'         => self::STATUS_QUEUED,
                'current_page'   => 0,
                'error_message'  => '',
                'started_at'     => 0,
                'updated_at'     => TIME,
                'completed_at'   => 0,
            ],
            $import_id,
            [self::STATUS_FAILED, self::STATUS_CANCELLED]
        );
        if (!$updated) {
            return false;
        }

        return true;
    }

    /**
     * Requeues all unsuccessful children of a parent import.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return int Number of requeued child imports
     *
     * @throws \Throwable When child imports cannot be requeued.
     */
    public function retryChildren($parent_import_id)
    {
        $import_ids = array_map('intval', $this->database->getColumn(
            'SELECT import_id FROM ?:?p WHERE parent_import_id = ?i AND status IN (?a)',
            self::IMPORTS_TABLE_NAME,
            $parent_import_id,
            [self::STATUS_FAILED, self::STATUS_CANCELLED]
        ));
        if (!$import_ids) {
            return 0;
        }

        $this->database->beginTransaction();

        try {
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id IN (?n)',
                self::TABLE_NAME,
                $import_ids
            );
            $this->database->query(
                'UPDATE ?:?p SET ?u WHERE import_id IN (?n) AND status IN (?a)',
                self::IMPORTS_TABLE_NAME,
                [
                    'status'        => self::STATUS_QUEUED,
                    'current_page'  => 0,
                    'error_message' => '',
                    'started_at'    => 0,
                    'updated_at'    => TIME,
                    'completed_at'  => 0,
                ],
                $import_ids,
                [self::STATUS_FAILED, self::STATUS_CANCELLED]
            );
            $this->updateImportStatus($parent_import_id, self::STATUS_PROCESSING);
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }

        return count($import_ids);
    }

    /**
     * Fails a child import and removes only its staged entities.
     *
     * @param int    $import_id Import identifier
     * @param string $error     Error message
     *
     * @return void
     *
     * @throws \Throwable When child import cannot be failed.
     */
    public function failChild($import_id, $error)
    {
        $this->database->beginTransaction();

        try {
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id = ?i',
                self::TABLE_NAME,
                $import_id
            );
            $this->database->query(
                'UPDATE ?:?p SET status = ?s, error_message = ?s, updated_at = ?i, completed_at = ?i'
                . ' WHERE import_id = ?i AND parent_import_id != ?i',
                self::IMPORTS_TABLE_NAME,
                self::STATUS_FAILED,
                $error,
                TIME,
                TIME,
                $import_id,
                0
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }
    }

    /**
     * Updates an import status.
     *
     * @param int    $import_id Import identifier
     * @param string $status    Import status
     * @param string $error     Error message
     *
     * @return bool
     */
    public function updateImportStatus($import_id, $status, $error = '')
    {
        $data = [
            'status'        => $status,
            'error_message' => $error,
            'updated_at'    => TIME,
        ];

        if (
            in_array($status, [
                self::STATUS_COMPLETED,
                self::STATUS_PARTIAL_SUCCESS,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED,
            ], true)
        ) {
            $data['completed_at'] = TIME;
        }

        return (bool) $this->database->query(
            'UPDATE ?:?p SET ?u WHERE import_id = ?i',
            self::IMPORTS_TABLE_NAME,
            $data,
            $import_id
        );
    }

    /**
     * Starts a new isolated import run.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Root entity type
     *
     * @return int Import identifier
     */
    public function startImport($company_id, $entity_type)
    {
        $unfinished_import_ids = $this->database->getColumn(
            'SELECT import_id FROM ?:?p'
            . ' WHERE company_id = ?i AND entity_type = ?s AND parent_import_id = ?i'
            . ' AND cron_script_id = ?i AND status != ?s',
            self::IMPORTS_TABLE_NAME,
            $company_id,
            $entity_type,
            0,
            0,
            self::STATUS_COMPLETED
        );

        if ($unfinished_import_ids) {
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id IN (?n)',
                self::TABLE_NAME,
                $unfinished_import_ids
            );
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id IN (?n)',
                self::IMPORTS_TABLE_NAME,
                $unfinished_import_ids
            );
        }

        return $this->database->replaceInto(self::IMPORTS_TABLE_NAME, [
            'parent_import_id'       => 0,
            'cron_script_id'         => 0,
            'company_id'             => $company_id,
            'entity_type'            => $entity_type,
            'source_type'            => self::SOURCE_TYPE_FULL,
            'status'                 => self::STATUS_PROCESSING,
            'page_limit'             => 0,
            'total_items'            => 0,
            'total_pages'            => 0,
            'max_parallel_processes' => 1,
            'error_message'          => '',
            'created_at'             => TIME,
            'started_at'             => TIME,
            'updated_at'             => TIME,
            'completed_at'           => 0,
        ]);
    }

    /**
     * Publishes a completely processed import run and removes superseded runs.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     *
     * @throws \Throwable When the import cannot be completed.
     */
    public function completeImport($import_id)
    {
        $import = $this->database->getRow(
            'SELECT company_id, entity_type, parent_import_id, cron_script_id'
            . ' FROM ?:?p WHERE import_id = ?i',
            self::IMPORTS_TABLE_NAME,
            $import_id
        );

        if (!$import) {
            return false;
        }

        $this->database->beginTransaction();

        try {
            $superseded_import_ids = $this->database->getColumn(
                'SELECT import_id FROM ?:?p'
                . ' WHERE company_id = ?i AND entity_type = ?s AND parent_import_id = ?i'
                . ' AND cron_script_id = ?i AND import_id != ?i',
                self::IMPORTS_TABLE_NAME,
                $import['company_id'],
                $import['entity_type'],
                0,
                0,
                $import_id
            );

            if ($superseded_import_ids) {
                $this->database->query(
                    'DELETE FROM ?:?p WHERE import_id IN (?n)',
                    self::TABLE_NAME,
                    $superseded_import_ids
                );
                $this->database->query(
                    'DELETE FROM ?:?p WHERE import_id IN (?n)',
                    self::IMPORTS_TABLE_NAME,
                    $superseded_import_ids
                );
            }

            $this->database->query(
                'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i WHERE import_id = ?i',
                self::IMPORTS_TABLE_NAME,
                self::STATUS_COMPLETED,
                TIME,
                TIME,
                $import_id
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }

        return true;
    }

    /**
     * Marks an import run as failed and removes its staged entities.
     *
     * @param int $import_id Import identifier
     *
     * @return void
     *
     * @throws \Throwable When the failed import cannot be cleaned up.
     */
    public function failImport($import_id)
    {
        $this->database->beginTransaction();

        try {
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id = ?i',
                self::TABLE_NAME,
                $import_id
            );
            $this->database->query(
                'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i WHERE import_id = ?i',
                self::IMPORTS_TABLE_NAME,
                self::STATUS_FAILED,
                TIME,
                TIME,
                $import_id
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }
    }

    /**
     * Finds the latest completed import run.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Root entity type
     *
     * @return int
     */
    public function findLatestCompletedImportId($company_id, $entity_type)
    {
        return (int) $this->database->getField(
            'SELECT import_id FROM ?:?p'
            . ' WHERE company_id = ?i AND entity_type = ?s AND parent_import_id = ?i AND status = ?s'
            . ' ORDER BY import_id DESC LIMIT 1',
            self::IMPORTS_TABLE_NAME,
            $company_id,
            $entity_type,
            0,
            self::STATUS_COMPLETED
        );
    }

    /**
     * Finds the latest parent import with at least one completed child.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Root entity type
     *
     * @return int
     */
    public function findLatestMappableParentId($company_id, $entity_type)
    {
        return (int) $this->database->getField(
            'SELECT parent.import_id FROM ?:?p AS parent'
            . ' WHERE parent.company_id = ?i AND parent.entity_type = ?s AND parent.parent_import_id = ?i'
            . ' AND EXISTS (SELECT child.import_id FROM ?:?p AS child'
            . ' WHERE child.parent_import_id = parent.import_id AND child.status = ?s)'
            . ' ORDER BY parent.import_id DESC LIMIT 1',
            self::IMPORTS_TABLE_NAME,
            $company_id,
            $entity_type,
            0,
            self::IMPORTS_TABLE_NAME,
            self::STATUS_COMPLETED
        );
    }

    /**
     * Finds completed child import identifiers in page order.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return array<int>
     */
    public function findCompletedChildIds($parent_import_id)
    {
        return array_map('intval', $this->database->getColumn(
            'SELECT import_id FROM ?:?p'
            . ' WHERE parent_import_id = ?i AND status = ?s ORDER BY page_from, import_id',
            self::IMPORTS_TABLE_NAME,
            $parent_import_id,
            self::STATUS_COMPLETED
        ));
    }

    /**
     * Finds all DTOs of the requested type from an import run.
     *
     * @param int    $import_id   Import identifier
     * @param string $entity_type Entity type
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto>
     */
    public function findAllByEntityType($import_id, $entity_type)
    {
        if (!$import_id) {
            return [];
        }

        $serialized_entities = $this->database->getColumn(
            'SELECT entity FROM ?:?p WHERE import_id = ?i AND entity_type = ?s ORDER BY entity_id',
            self::TABLE_NAME,
            $import_id,
            $entity_type
        );
        $entities = [];

        foreach ($serialized_entities as $serialized_entity) {
            /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
            $entity = unserialize($serialized_entity);
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Counts staged DTOs of the requested type.
     *
     * @param int    $import_id   Import identifier
     * @param string $entity_type Entity type
     *
     * @return int
     */
    public function countByEntityType($import_id, $entity_type)
    {
        return (int) $this->database->getField(
            'SELECT COUNT(*) FROM ?:?p WHERE import_id = ?i AND entity_type = ?s',
            self::TABLE_NAME,
            $import_id,
            $entity_type
        );
    }

    /**
     * Finds DTOs from several import runs.
     *
     * @param array<int> $import_ids  Import identifiers in precedence order
     * @param string     $entity_type Entity type
     * @param bool       $deduplicate Whether a later entity must replace an earlier one
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto>
     */
    public function findAllByEntityTypeFromImports(array $import_ids, $entity_type, $deduplicate = true)
    {
        if (!$import_ids) {
            return [];
        }

        $rows = $this->database->getArray(
            'SELECT entities.entity_id, entities.entity FROM ?:?p AS entities'
            . ' INNER JOIN ?:?p AS imports ON imports.import_id = entities.import_id'
            . ' WHERE entities.import_id IN (?n) AND entities.entity_type = ?s'
            . ' ORDER BY imports.page_from, imports.import_id, entities.entity_id',
            self::TABLE_NAME,
            self::IMPORTS_TABLE_NAME,
            $import_ids,
            $entity_type
        );
        $entities = [];

        foreach ($rows as $row) {
            /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
            $entity = unserialize($row['entity']);
            if ($deduplicate) {
                $entities[(string) $row['entity_id']] = $entity;
            } else {
                $entities[] = $entity;
            }
        }

        return array_values($entities);
    }

    /**
     * Finds a bounded entity batch from several import runs.
     *
     * @param array<int> $import_ids      Import identifiers
     * @param string     $entity_type     Entity type
     * @param string     $after_entity_id Last processed entity identifier
     * @param int        $limit           Batch size
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto>
     */
    public function findEntityBatch(array $import_ids, $entity_type, $after_entity_id, $limit)
    {
        if (!$import_ids || $limit < 1) {
            return [];
        }

        $serialized_entities = $this->database->getColumn(
            'SELECT entities.entity FROM ?:?p AS entities'
            . ' INNER JOIN ('
            . ' SELECT entity_id, MAX(import_id) AS import_id FROM ?:?p'
            . ' WHERE import_id IN (?n) AND entity_type = ?s AND entity_id > ?s GROUP BY entity_id'
            . ' ) AS latest ON latest.import_id = entities.import_id AND latest.entity_id = entities.entity_id'
            . ' WHERE entities.entity_type = ?s ORDER BY entities.entity_id LIMIT ?i',
            self::TABLE_NAME,
            self::TABLE_NAME,
            $import_ids,
            $entity_type,
            $after_entity_id,
            $entity_type,
            $limit
        );
        $entities = [];

        foreach ($serialized_entities as $serialized_entity) {
            /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
            $entity = unserialize($serialized_entity);
            if (!$entity instanceof RepresentEntityDto || $entity->getEntityType() !== $entity_type) {
                throw new UnexpectedValueException('The stored entity type does not match the requested type');
            }
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Finds a parent-first category batch after the persisted application checkpoint.
     *
     * @param int $import_id                  Import identifier
     * @param int $after_application_position Last applied category position
     * @param int $limit                      Batch size
     *
     * @return array<int, \Tygh\Addons\Synchro\Dto\CategoryDto> Categories keyed by application position
     */
    public function findCategoryApplicationBatch($import_id, $after_application_position, $limit)
    {
        if (!$import_id || $limit < 1) {
            return [];
        }

        $rows = $this->database->getArray(
            'SELECT application_position, entity FROM ?:?p'
            . ' WHERE import_id = ?i AND entity_type = ?s AND application_position > ?i'
            . ' ORDER BY application_position LIMIT ?i',
            self::TABLE_NAME,
            $import_id,
            CategoryDto::ENTITY_TYPE,
            $after_application_position,
            $limit
        );
        $categories = [];

        foreach ($rows as $row) {
            /** @var \Tygh\Addons\Synchro\Dto\CategoryDto $category */
            $category = unserialize($row['entity']);
            if (!$category instanceof CategoryDto) {
                throw new UnexpectedValueException('The stored entity is not a category');
            }
            $categories[(int) $row['application_position']] = $category;
        }

        return $categories;
    }

    /**
     * Stores the last fully applied category position.
     *
     * @param int $import_id          Import identifier
     * @param int $application_cursor Last applied category position
     *
     * @return bool
     */
    public function updateApplicationCursor($import_id, $application_cursor)
    {
        return $this->database->query(
            'UPDATE ?:?p SET application_cursor = ?i, updated_at = ?i WHERE import_id = ?i',
            self::IMPORTS_TABLE_NAME,
            $application_cursor,
            TIME,
            $import_id
        ) !== false;
    }

    /**
     * Removes all staged entities of the specified import runs.
     *
     * @param array<int> $import_ids Import identifiers
     *
     * @return int
     */
    public function removeByImportIds(array $import_ids)
    {
        if (!$import_ids) {
            return 0;
        }

        $result = $this->database->query(
            'DELETE FROM ?:?p WHERE import_id IN (?n)',
            self::TABLE_NAME,
            $import_ids
        );

        return (int) $result;
    }

    /**
     * Finds stored DTOs by entity type and identifiers.
     *
     * @param int                      $import_id   Import identifier
     * @param string                   $entity_type Entity type
     * @param array<array-key, string> $entity_ids  Entity identifiers
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto>
     */
    public function findByEntityIds($import_id, $entity_type, array $entity_ids)
    {
        if (!$entity_ids) {
            return [];
        }

        $serialized_entities = $this->database->getColumn(
            'SELECT entity FROM ?:?p WHERE import_id = ?i AND entity_type = ?s AND entity_id IN (?a)',
            self::TABLE_NAME,
            $import_id,
            $entity_type,
            $entity_ids
        );
        $entities = [];

        foreach ($serialized_entities as $serialized_entity) {
            /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
            $entity = unserialize($serialized_entity);
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Saves normalized entities in a batch.
     *
     * @param int                                                           $import_id  Import identifier
     * @param int                                                           $company_id Company identifier
     * @param array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto> $entities   Entity DTO instances
     *
     * @return int
     */
    public function batchSave($import_id, $company_id, array $entities)
    {
        $timestamp = time();
        $records = [];

        /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
        foreach ($entities as $entity) {
            $records[] = [
                'import_id'   => $import_id,
                'company_id'  => $company_id,
                'entity_id'   => $entity->getEntityId(),
                'entity_type' => $entity->getEntityType(),
                'entity'      => serialize($entity),
                'created_at'  => $timestamp,
                'updated_at'  => $timestamp,
            ];
        }

        if (!$records) {
            return 0;
        }

        return $this->database->replaceInto(
            self::TABLE_NAME,
            $records,
            true,
            ['entity', 'updated_at']
        );
    }

    /**
     * Saves categories with the parent-first application order produced by the convertor.
     *
     * @param int                                                    $import_id  Import identifier
     * @param int                                                    $company_id Company identifier
     * @param array<array-key, \Tygh\Addons\Synchro\Dto\CategoryDto> $categories Category DTO instances
     *
     * @return int
     */
    public function batchSaveCategories($import_id, $company_id, array $categories)
    {
        $timestamp = time();
        $records = [];

        foreach ($categories as $application_position => $category) {
            $records[] = [
                'import_id'            => $import_id,
                'company_id'           => $company_id,
                'entity_id'            => $category->getEntityId(),
                'entity_type'          => CategoryDto::ENTITY_TYPE,
                'application_position' => $application_position + 1,
                'entity'               => serialize($category),
                'created_at'           => $timestamp,
                'updated_at'           => $timestamp,
            ];
        }

        return $records
            ? $this->database->replaceInto(
                self::TABLE_NAME,
                $records,
                true,
                ['entity', 'application_position', 'updated_at']
            )
            : 0;
    }
}
