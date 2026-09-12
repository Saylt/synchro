<?php

namespace Tygh\Addons\Synchro\Application;

use InvalidArgumentException;
use RuntimeException;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Importers\CategoryImporter;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Applies staged category DTOs to CS-Cart.
 */
class CategoryApplicationManager
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var \Tygh\Addons\Synchro\Importers\CategoryImporter */
    private $category_importer;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository    $repository         Staged entity repository
     * @param \Tygh\Addons\Synchro\Importers\CategoryImporter           $category_importer  Category importer
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     */
    public function __construct(
        ImportEntityRepository $repository,
        CategoryImporter $category_importer,
        ImportEntityMapRepository $mapping_repository
    ) {
        $this->repository = $repository;
        $this->category_importer = $category_importer;
        $this->mapping_repository = $mapping_repository;
    }

    /**
     * Runs one stage of a category application hierarchy.
     *
     * @param array<string, int|string> $process Application process
     *
     * @return int Number of categories processed by this stage
     *
     * @throws \InvalidArgumentException When process or staging data is invalid.
     * @throws \RuntimeException When category data or progress cannot be stored.
     */
    public function process(array $process)
    {
        $staging_import = $this->repository->findImport($process['staging_import_id']);
        $this->validateProcess($process, $staging_import);

        if ($process['process_stage'] === EntityApplicationPlanBuilder::STAGE_APPLY) {
            return $this->applyRange($process, $staging_import);
        }
        if ($process['process_stage'] === EntityApplicationPlanBuilder::STAGE_FINALIZE) {
            return $this->finalize($staging_import);
        }

        throw new InvalidArgumentException(__('synchro.exception.unknown_category_application_stage'));
    }

    /**
     * Applies one category portion within one tree level.
     *
     * @param array<string, int|string> $process        Application process
     * @param array<string, int|string> $staging_import Staging import
     *
     * @return int Number of applied categories
     *
     * @throws \RuntimeException When mapping timestamps or progress cannot be stored.
     */
    private function applyRange(array $process, array $staging_import)
    {
        $categories = $this->repository->findCategoryLevelBatch(
            $staging_import['import_id'],
            $process['process_group'],
            max(0, $process['page_from'] - 1),
            max(0, $process['page_to'] - $process['page_from'] + 1)
        );
        $category_ids = $this->category_importer->importBatch(
            $categories,
            $staging_import['company_id']
        );
        if (
            $category_ids
            && !$this->mapping_repository->markFullyUpdatedMany(
                $staging_import['company_id'],
                CategoryDto::ENTITY_TYPE,
                array_keys($category_ids)
            )
        ) {
            throw new RuntimeException(__('synchro.exception.category_full_update_timestamp_not_stored'));
        }

        $processed_items = count($category_ids);
        if (!$this->repository->updateProcessedItems($process['import_id'], $processed_items)) {
            throw new RuntimeException(__('synchro.exception.category_application_progress_not_stored'));
        }

        return $processed_items;
    }

    /**
     * Disables categories missing from the snapshot and removes staged DTOs.
     *
     * @param array<string, int|string> $staging_import Staging import
     *
     * @return int
     *
     * @throws \RuntimeException When missing categories cannot be disabled.
     */
    private function finalize(array $staging_import)
    {
        if (
            !$this->category_importer->disableMissingCategories(
                $staging_import['import_id'],
                $staging_import['company_id']
            )
        ) {
            throw new RuntimeException(__('synchro.exception.missing_categories_cannot_be_disabled'));
        }
        $this->repository->removeByImportIds([$staging_import['import_id']]);

        return 0;
    }

    /**
     * Validates application process and staging snapshot compatibility.
     *
     * @param array<string, int|string> $process        Application process
     * @param array<string, int|string> $staging_import Staging import
     *
     * @return void
     *
     * @throws \InvalidArgumentException When process or staging data is invalid.
     */
    private function validateProcess(array $process, array $staging_import)
    {
        if (
            !$staging_import
            || $staging_import['parent_import_id'] !== 0
            || $staging_import['entity_type'] !== ImportDataCommand::ENTITY_CATEGORIES
            || $staging_import['status'] !== ImportEntityRepository::STATUS_COMPLETED
            || !$process['parent_import_id']
            || $process['staging_import_id'] !== $staging_import['import_id']
            || $process['entity_type'] !== ImportDataCommand::ENTITY_CATEGORIES
        ) {
            throw new InvalidArgumentException(__('synchro.exception.category_application_process_required'));
        }
    }
}
