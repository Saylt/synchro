<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Lock\Factory;

/**
 * Orchestrates parent and child import processes.
 */
class ImportProcessManager
{
    const LOCK_PREFIX = 'synchro_import_parent_';

    const STALE_PROCESS_TIMEOUT = SECONDS_IN_DAY;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var \Tygh\Addons\Synchro\ProductImportRangeBuilder */
    private $range_builder;

    /** @var \Tygh\Addons\Synchro\CronManager */
    private $cron_manager;

    /** @var \Tygh\Lock\Factory */
    private $lock_factory;

    /** @var string */
    private $root_dir;

    /** @var string */
    private $admin_index;

    /** @var string */
    private $cron_password;

    /** @var string */
    private $php_binary;

    /** @var callable */
    private $process_launcher;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository       Import repository
     * @param \Tygh\Addons\Synchro\ProductImportRangeBuilder         $range_builder    Range builder
     * @param \Tygh\Addons\Synchro\CronManager                       $cron_manager     Cron manager
     * @param \Tygh\Lock\Factory                                     $lock_factory     Lock factory
     * @param string                                                 $root_dir         Store root directory
     * @param string                                                 $admin_index      Admin entry point
     * @param string                                                 $cron_password    Cron password
     * @param string                                                 $php_binary       PHP CLI binary
     * @param callable|null                                          $process_launcher Background process launcher
     */
    public function __construct(
        ImportEntityRepository $repository,
        ProductImportRangeBuilder $range_builder,
        CronManager $cron_manager,
        Factory $lock_factory,
        $root_dir,
        $admin_index,
        $cron_password,
        $php_binary,
        callable $process_launcher = null
    ) {
        $this->repository = $repository;
        $this->range_builder = $range_builder;
        $this->cron_manager = $cron_manager;
        $this->lock_factory = $lock_factory;
        $this->root_dir = rtrim($root_dir, '/');
        $this->admin_index = $admin_index;
        $this->cron_password = $cron_password;
        $this->php_binary = $php_binary;
        $this->process_launcher = $process_launcher ?: static function ($command) {
            $output = [];
            $exit_code = 0;
            exec($command, $output, $exit_code);

            return $exit_code === 0;
        };
    }

    /**
     * Creates a parent product import and its child page ranges.
     *
     * @param array<string, array<int, string>|int|string|null> $script      Cron script data
     * @param int                                               $company_id  Company identifier
     * @param int                                               $total_items Total products reported by the API
     *
     * @return int Parent import identifier
     *
     * @throws \Throwable When the import hierarchy cannot be created.
     */
    public function createProductImport(array $script, $company_id, $total_items)
    {
        $settings = [
            'use_portions' => isset($script['use_portions']) && is_scalar($script['use_portions'])
                ? (string) $script['use_portions']
                : 'N',
            'pages_per_portion' => isset($script['pages_per_portion']) && is_scalar($script['pages_per_portion'])
                ? (int) $script['pages_per_portion']
                : ProductImportRangeBuilder::DEFAULT_PAGES_PER_PORTION,
            'page_limit' => isset($script['page_limit']) && is_scalar($script['page_limit'])
                ? (int) $script['page_limit']
                : ProductImportRangeBuilder::DEFAULT_PAGE_LIMIT,
            'max_parallel_processes' => isset($script['max_parallel_processes'])
                && is_scalar($script['max_parallel_processes'])
                ? (int) $script['max_parallel_processes']
                : ProductImportRangeBuilder::DEFAULT_MAX_PARALLEL_PROCESSES,
            'is_test_import' => isset($script['is_test_import']) && is_scalar($script['is_test_import'])
                ? (string) $script['is_test_import']
                : 'N',
            'test_page' => isset($script['test_page']) && is_scalar($script['test_page'])
                ? (int) $script['test_page']
                : 1,
        ];
        $script_id = isset($script['script_id']) && is_scalar($script['script_id'])
            ? (int) $script['script_id']
            : 0;
        $plan = $this->range_builder->build($total_items, $settings);
        $plan['source_type'] = $settings['is_test_import'] === 'Y'
            ? ImportEntityRepository::SOURCE_TYPE_TEST
            : ImportEntityRepository::SOURCE_TYPE_FULL;

        return $this->repository->createImportHierarchy(
            $company_id,
            ImportDataCommand::ENTITY_PRODUCTS,
            $script_id,
            $plan
        );
    }

    /**
     * Starts queued child imports while their parents have free slots.
     *
     * @param int $parent_import_id Parent import identifier, zero to process every active parent
     *
     * @return int Number of launched child processes
     */
    public function dispatchPending($parent_import_id = 0)
    {
        $parents = $parent_import_id
            ? [$this->repository->findImport($parent_import_id)]
            : $this->repository->findActiveParents();
        $launched_count = 0;

        foreach ($parents as $parent) {
            if (!$parent || $parent['status'] !== ImportEntityRepository::STATUS_PROCESSING) {
                continue;
            }

            $lock = $this->lock_factory->createLock(
                self::LOCK_PREFIX . $parent['import_id'],
                SECONDS_IN_DAY,
                false
            );
            if (!$lock->acquire()) {
                continue;
            }

            try {
                $parent = $this->repository->findImport((int) $parent['import_id']);
                if (!$parent || $parent['status'] !== ImportEntityRepository::STATUS_PROCESSING) {
                    continue;
                }

                $running_count = $this->repository->countRunningChildren((int) $parent['import_id']);
                $available_slots = max(0, (int) $parent['max_parallel_processes'] - $running_count);
                if (!$available_slots) {
                    continue;
                }

                $children = $this->repository->findQueuedChildren(
                    (int) $parent['import_id'],
                    $available_slots
                );
                foreach ($children as $child) {
                    $import_id = (int) $child['import_id'];
                    if (!$this->repository->claimChild($import_id)) {
                        continue;
                    }

                    $is_launched = call_user_func(
                        $this->process_launcher,
                        $this->prepareBackgroundCommand($import_id)
                    );
                    if (!$is_launched) {
                        $this->repository->failChild($import_id, 'Unable to launch the import process');
                        continue;
                    }

                    $launched_count++;
                }
            } finally {
                $lock->release();
            }
        }

        return $launched_count;
    }

    /**
     * Builds a detached CLI command for a child import.
     *
     * @param int $import_id Child import identifier
     *
     * @return string
     */
    public function prepareBackgroundCommand($import_id)
    {
        return sprintf(
            '%s %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($this->php_binary),
            escapeshellarg($this->root_dir . '/' . $this->admin_index),
            escapeshellarg('--dispatch=synchro_import.product_process'),
            escapeshellarg('--import_id=' . $import_id)
                . ' ' . escapeshellarg('--cron_password=' . $this->cron_password)
        );
    }

    /**
     * Recalculates and persists the parent import status.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return string Parent import status
     */
    public function reconcileParent($parent_import_id)
    {
        $parent = $this->repository->findImport($parent_import_id);
        if (!$parent || (int) $parent['parent_import_id'] !== 0) {
            return '';
        }

        $lock = $this->lock_factory->createLock(
            self::LOCK_PREFIX . $parent_import_id,
            SECONDS_IN_DAY,
            false
        );
        if (!$lock->acquire()) {
            return (string) $parent['status'];
        }

        try {
            $parent = $this->repository->findImport($parent_import_id);
            if (!$parent || (int) $parent['parent_import_id'] !== 0) {
                return '';
            }

            $children = $this->repository->findChildren($parent_import_id);
            $statuses = array_count_values(array_column($children, 'status'));
            $active_statuses = [
                ImportEntityRepository::STATUS_QUEUED,
                ImportEntityRepository::STATUS_PROCESSING,
                ImportEntityRepository::STATUS_STOPPING,
            ];

            foreach ($active_statuses as $active_status) {
                if (!empty($statuses[$active_status])) {
                    return (string) $parent['status'];
                }
            }

            $completed_count = isset($statuses[ImportEntityRepository::STATUS_COMPLETED])
                ? $statuses[ImportEntityRepository::STATUS_COMPLETED]
                : 0;
            if (!$children || $completed_count === count($children)) {
                $result_status = ImportEntityRepository::STATUS_COMPLETED;
            } elseif ($completed_count > 0) {
                $result_status = ImportEntityRepository::STATUS_PARTIAL_SUCCESS;
            } elseif (!empty($statuses[ImportEntityRepository::STATUS_FAILED])) {
                $result_status = ImportEntityRepository::STATUS_FAILED;
            } else {
                $result_status = ImportEntityRepository::STATUS_CANCELLED;
            }

            $this->repository->updateImportStatus($parent_import_id, $result_status);
            $this->cron_manager->finalizeDeferredTask((int) $parent['cron_script_id'], $result_status);

            return $result_status;
        } finally {
            $lock->release();
        }
    }

    /**
     * Stops processing when a child import was marked for interruption.
     *
     * @param int $import_id Child import identifier
     *
     * @return void
     *
     * @throws \Throwable When the failed import cannot be cleaned up.
     *
     * @throws \Tygh\Addons\Synchro\Exceptions\TaskInterruptedException When interruption is requested.
     */
    public function ensureProcessCanContinue($import_id)
    {
        if (!$import_id) {
            return;
        }

        $process = $this->repository->findImport($import_id);
        if (
            $process
            && in_array($process['status'], [
                ImportEntityRepository::STATUS_STOPPING,
                ImportEntityRepository::STATUS_CANCELLED,
            ], true)
        ) {
            throw new TaskInterruptedException('Import process interruption requested');
        }
    }

    /**
     * Returns an import process.
     *
     * @param int $import_id Import identifier
     *
     * @return array<string, int|string>
     */
    public function getProcess($import_id)
    {
        return $this->repository->findImport($import_id);
    }

    /**
     * Gets the latest parent and child processes for cron tasks.
     *
     * @param array<int> $cron_script_ids Cron script identifiers
     *
     * @return array<int, array{
     *     parent: array<string, int|string>,
     *     children: array<array-key, array<string, int|string>>,
     *     completed_count: int,
     *     total_count: int
     * }>
     */
    public function getLatestProcesses(array $cron_script_ids)
    {
        $processes = [];

        foreach ($cron_script_ids as $cron_script_id) {
            $parent = $this->repository->findLatestParentByCronScriptId($cron_script_id);
            if (!$parent) {
                continue;
            }

            $children = $this->repository->findChildren((int) $parent['import_id']);
            $processes[$cron_script_id] = [
                'parent'          => $parent,
                'children'        => $children,
                'completed_count' => count(array_filter($children, static function (array $child) {
                    return $child['status'] === ImportEntityRepository::STATUS_COMPLETED;
                })),
                'total_count'     => count($children),
            ];
        }

        return $processes;
    }

    /**
     * Stores the page currently processed by a child import.
     *
     * @param int $import_id Import identifier
     * @param int $page      Current page
     *
     * @return bool
     */
    public function updateProgress($import_id, $page)
    {
        return $this->repository->updateChildProgress($import_id, $page);
    }

    /**
     * Completes a child import and fills the released parent slot.
     *
     * @param int $import_id Import identifier
     *
     * @return void
     */
    public function completeProcess($import_id)
    {
        $process = $this->repository->findImport($import_id);
        if (!$process || !(int) $process['parent_import_id']) {
            return;
        }

        $this->repository->completeChild($import_id);
        $this->continueParent((int) $process['parent_import_id']);
    }

    /**
     * Fails a child import and fills the released parent slot.
     *
     * @param int    $import_id Import identifier
     * @param string $error     Error message
     *
     * @return void
     *
     * @throws \Throwable When the failed import cannot be cleaned up.
     */
    public function failProcess($import_id, $error)
    {
        $process = $this->repository->findImport($import_id);
        if (!$process || !(int) $process['parent_import_id']) {
            return;
        }

        $this->repository->failChild($import_id, $error);
        $this->continueParent((int) $process['parent_import_id']);
    }

    /**
     * Cancels a cooperatively interrupted child import.
     *
     * @param int $import_id Import identifier
     *
     * @return void
     *
     * @throws \Throwable When the cancelled import cannot be cleaned up.
     */
    public function cancelProcess($import_id)
    {
        $process = $this->repository->findImport($import_id);
        if (!$process || !(int) $process['parent_import_id']) {
            return;
        }

        $this->repository->cancelChild($import_id);
        $this->continueParent((int) $process['parent_import_id']);
    }

    /**
     * Requests interruption of the latest active parent for a cron task.
     *
     * @param int $cron_script_id Cron script identifier
     *
     * @return bool
     */
    public function requestParentInterruption($cron_script_id)
    {
        $parent = $this->repository->findLatestParentByCronScriptId($cron_script_id);
        if (!$parent || $parent['status'] !== ImportEntityRepository::STATUS_PROCESSING) {
            return false;
        }

        $is_requested = $this->repository->requestParentInterruption((int) $parent['import_id']);
        if ($is_requested) {
            $this->reconcileParent((int) $parent['import_id']);
        }

        return $is_requested;
    }

    /**
     * Requests interruption of a single child process.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     */
    public function requestProcessInterruption($import_id)
    {
        $process = $this->repository->findImport($import_id);
        if (!$process || !(int) $process['parent_import_id']) {
            return false;
        }

        $is_requested = $this->repository->requestChildInterruption($import_id);
        if ($is_requested && $process['status'] === ImportEntityRepository::STATUS_QUEUED) {
            $this->continueParent((int) $process['parent_import_id']);
        }

        return $is_requested;
    }

    /**
     * Requeues one failed or cancelled child process.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     */
    public function retryProcess($import_id)
    {
        $process = $this->repository->findImport($import_id);
        if (!$process || !(int) $process['parent_import_id']) {
            return false;
        }

        if (!$this->repository->retryChild($import_id)) {
            return false;
        }

        $this->repository->updateImportStatus(
            (int) $process['parent_import_id'],
            ImportEntityRepository::STATUS_PROCESSING
        );
        $this->cron_manager->reopenTaskForChildren((int) $process['cron_script_id']);
        $this->dispatchPending((int) $process['parent_import_id']);

        return true;
    }

    /**
     * Requeues every failed or cancelled child of the latest parent import.
     *
     * @param int $cron_script_id Cron script identifier
     *
     * @return bool
     *
     * @throws \Throwable When child imports cannot be requeued.
     */
    public function retryFailedProcesses($cron_script_id)
    {
        $parent = $this->repository->findLatestParentByCronScriptId($cron_script_id);
        if (!$parent || !(int) $parent['import_id']) {
            return false;
        }

        if (!$this->repository->retryChildren((int) $parent['import_id'])) {
            return false;
        }

        $this->cron_manager->reopenTaskForChildren($cron_script_id);
        $this->dispatchPending((int) $parent['import_id']);

        return true;
    }

    /**
     * Fails stale child processes and continues their parents.
     *
     * @return int Number of recovered processes
     *
     * @throws \Throwable When a stale import cannot be failed.
     */
    public function recoverStaleProcesses()
    {
        $stale_processes = $this->repository->findStaleChildren(TIME - self::STALE_PROCESS_TIMEOUT);
        $parent_import_ids = [];

        foreach ($stale_processes as $process) {
            $this->repository->failChild(
                (int) $process['import_id'],
                'The import process stopped reporting progress'
            );
            $parent_import_ids[(int) $process['parent_import_id']] = (int) $process['parent_import_id'];
        }

        foreach ($parent_import_ids as $parent_import_id) {
            $this->continueParent($parent_import_id);
        }

        return count($stale_processes);
    }

    /**
     * Reconciles a parent and starts another child when the parent is still active.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return void
     */
    private function continueParent($parent_import_id)
    {
        $this->dispatchPending($parent_import_id);
        $this->reconcileParent($parent_import_id);
    }
}
