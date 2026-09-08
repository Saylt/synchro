<?php

namespace Tygh\Addons\Synchro\Application;

use InvalidArgumentException;
use RuntimeException;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Importers\CategoryImporter;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Applies staged category DTOs to CS-Cart.
 */
class CategoryApplicationManager
{
    const BATCH_SIZE = 30;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var \Tygh\Addons\Synchro\Importers\CategoryImporter */
    private $category_importer;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\CronManager */
    private $cron_manager;

    /**
     * Initializes the staged category application manager.
     *
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository    $repository         Staged entity repository
     * @param \Tygh\Addons\Synchro\Importers\CategoryImporter           $category_importer  Category importer
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     * @param \Tygh\Addons\Synchro\CronManager                          $cron_manager       Cron manager
     */
    public function __construct(
        ImportEntityRepository $repository,
        CategoryImporter $category_importer,
        ImportEntityMapRepository $mapping_repository,
        CronManager $cron_manager
    ) {
        $this->repository = $repository;
        $this->category_importer = $category_importer;
        $this->mapping_repository = $mapping_repository;
        $this->cron_manager = $cron_manager;
    }

    /**
     * Applies one completed category import in bounded batches.
     *
     * @param int $import_id      Import identifier
     * @param int $cron_script_id Current cron task identifier
     *
     * @return int Number of successfully applied categories
     *
     * @throws \InvalidArgumentException When the source import is not a completed category import.
     * @throws \RuntimeException When category update metadata cannot be stored.
     * @throws \Throwable When the task is interrupted or category data cannot be applied.
     */
    public function apply($import_id, $cron_script_id = 0)
    {
        $import = $this->repository->findImport($import_id);
        $this->validateImport($import);
        $application_cursor = isset($import['application_cursor']) ? (int) $import['application_cursor'] : 0;
        $applied_count = 0;
        $total_count = $this->repository->countByEntityType($import_id, CategoryDto::ENTITY_TYPE);

        $this->cron_manager->ensureTaskCanContinue($cron_script_id);
        if ($cron_script_id) {
            $this->cron_manager->updateProgressStatus(
                $cron_script_id,
                __('synchro.applied_categories', ['[count]' => 0, '[total]' => $total_count])
            );
        }

        while (true) {
            $this->cron_manager->ensureTaskCanContinue($cron_script_id);
            $category_batch = $this->repository->findCategoryApplicationBatch(
                $import_id,
                $application_cursor,
                self::BATCH_SIZE
            );
            if (!$category_batch) {
                break;
            }

            $category_ids = $this->category_importer->importBatch(
                array_values($category_batch),
                (int) $import['company_id']
            );
            $this->cron_manager->ensureTaskCanContinue($cron_script_id);
            if (
                $category_ids
                && !$this->mapping_repository->markFullyUpdatedMany(
                    (int) $import['company_id'],
                    CategoryDto::ENTITY_TYPE,
                    array_keys($category_ids)
                )
            ) {
                throw new RuntimeException('Category full update timestamp cannot be stored');
            }

            $application_cursor = (int) max(array_keys($category_batch));
            if (!$this->repository->updateApplicationCursor($import_id, $application_cursor)) {
                throw new RuntimeException('Category application checkpoint cannot be stored');
            }
            $applied_count += count($category_ids);
            if ($cron_script_id) {
                $this->cron_manager->updateProgressStatus(
                    $cron_script_id,
                    __('synchro.applied_categories', ['[count]' => $applied_count, '[total]' => $total_count])
                );
            }
        }

        $this->cron_manager->ensureTaskCanContinue($cron_script_id);
        if ($cron_script_id) {
            $this->cron_manager->updateProgressStatus($cron_script_id, __('synchro.disabling_missing_categories'));
        }
        if (!$this->category_importer->disableMissingCategories($import_id, (int) $import['company_id'])) {
            throw new RuntimeException('Missing categories cannot be disabled');
        }
        $this->repository->removeByImportIds([$import_id]);

        return $applied_count;
    }

    /**
     * Validates that an import can be applied as a category snapshot.
     *
     * @param array<string, int|string> $import Import data
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the import is not completed category staging data.
     */
    private function validateImport(array $import)
    {
        if (
            !$import
            || (int) $import['parent_import_id'] !== 0
            || $import['entity_type'] !== ImportDataCommand::ENTITY_CATEGORIES
            || $import['status'] !== ImportEntityRepository::STATUS_COMPLETED
        ) {
            throw new InvalidArgumentException('A completed root category import is required');
        }
    }
}
