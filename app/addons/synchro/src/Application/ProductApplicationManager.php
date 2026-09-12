<?php

namespace Tygh\Addons\Synchro\Application;

use InvalidArgumentException;
use RuntimeException;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\ImportedProductFeatureReader;
use Tygh\Addons\Synchro\Importers\ProductFeatureImporter;
use Tygh\Addons\Synchro\Importers\ProductImporter;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Applies staged product DTOs to CS-Cart.
 */
class ProductApplicationManager
{
    const MODE_FULL = 'full';

    const MODE_TEST = 'test';

    const MODE_ACTUALIZE = 'actualize';

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var \Tygh\Addons\Synchro\ImportedProductFeatureReader */
    private $feature_reader;

    /** @var \Tygh\Addons\Synchro\Importers\ProductFeatureImporter */
    private $feature_importer;

    /** @var \Tygh\Addons\Synchro\Importers\ProductImporter */
    private $product_importer;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository    $repository         Staged entity repository
     * @param \Tygh\Addons\Synchro\ImportedProductFeatureReader         $feature_reader     Imported feature reader
     * @param \Tygh\Addons\Synchro\Importers\ProductFeatureImporter     $feature_importer   Product feature importer
     * @param \Tygh\Addons\Synchro\Importers\ProductImporter            $product_importer   Product importer
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     */
    public function __construct(
        ImportEntityRepository $repository,
        ImportedProductFeatureReader $feature_reader,
        ProductFeatureImporter $feature_importer,
        ProductImporter $product_importer,
        ImportEntityMapRepository $mapping_repository
    ) {
        $this->repository = $repository;
        $this->feature_reader = $feature_reader;
        $this->feature_importer = $feature_importer;
        $this->product_importer = $product_importer;
        $this->mapping_repository = $mapping_repository;
    }

    /**
     * Runs one stage of a product application hierarchy.
     *
     * @param array<string, int|string> $process Application process
     * @param string                    $mode    Application mode
     *
     * @return int Number of products processed by this stage
     *
     * @throws \InvalidArgumentException When process or staging data does not match the requested mode.
     * @throws \RuntimeException When product data or progress cannot be stored.
     */
    public function process(array $process, $mode)
    {
        $staging_import = $this->repository->findImport($process['staging_import_id']);
        $this->validateProcess($process, $staging_import, $mode);

        switch ($process['process_stage']) {
            case EntityApplicationPlanBuilder::STAGE_PREPARE:
                return $this->prepare($staging_import);
            case EntityApplicationPlanBuilder::STAGE_APPLY:
                return $this->applyRange($process, $staging_import, $mode);
            case EntityApplicationPlanBuilder::STAGE_FINALIZE:
                return $this->finalize($staging_import, $mode);
        }

        throw new InvalidArgumentException(__('synchro.exception.unknown_product_application_stage'));
    }

    /**
     * Imports product features before parallel product processes start.
     *
     * @param array<string, int|string> $staging_import Staging import
     *
     * @return int
     */
    private function prepare(array $staging_import)
    {
        $features = $this->feature_reader->readByParentImportId($staging_import['import_id']);
        $this->feature_importer->import($features, $staging_import['company_id']);

        return 0;
    }

    /**
     * Applies one inclusive product range.
     *
     * @param array<string, int|string> $process        Application process
     * @param array<string, int|string> $staging_import Staging import
     * @param string                    $mode           Application mode
     *
     * @return int Number of applied products
     *
     * @throws \RuntimeException When any product or its metadata cannot be stored.
     */
    private function applyRange(array $process, array $staging_import, $mode)
    {
        $source_import_ids = $this->getSourceImportIds($staging_import['import_id']);
        $products = $this->repository->findEntityRange(
            $source_import_ids,
            ProductDto::ENTITY_TYPE,
            $process['page_from'],
            $process['page_to']
        );
        $actualize = $mode === self::MODE_ACTUALIZE;
        $import_result = $this->product_importer->import($products, $staging_import['company_id'], $actualize);
        $successful_external_ids = array_keys($import_result['product_ids']);

        if ($actualize && $successful_external_ids) {
            if (
                !$this->mapping_repository->markActualizedMany(
                    $staging_import['company_id'],
                    ProductDto::ENTITY_TYPE,
                    $successful_external_ids
                )
            ) {
                throw new RuntimeException(__('synchro.exception.product_actualization_timestamp_not_stored'));
            }
        } elseif (!$actualize && $import_result['fully_updated_external_ids']) {
            if (
                !$this->mapping_repository->markFullyUpdatedMany(
                    $staging_import['company_id'],
                    ProductDto::ENTITY_TYPE,
                    $import_result['fully_updated_external_ids']
                )
            ) {
                throw new RuntimeException(__('synchro.exception.product_full_update_timestamp_not_stored'));
            }
        }

        $processed_items = count($successful_external_ids);
        if ($processed_items !== count($products)) {
            throw new RuntimeException(__('synchro.exception.products_could_not_be_applied'));
        }
        if (!$this->repository->updateProcessedItems($process['import_id'], $processed_items)) {
            throw new RuntimeException(__('synchro.exception.product_application_progress_not_stored'));
        }

        return $processed_items;
    }

    /**
     * Applies snapshot-wide metadata and removes staged product DTOs.
     *
     * @param array<string, int|string> $staging_import Staging import
     * @param string                    $mode           Application mode
     *
     * @return int
     *
     * @throws \RuntimeException When the archiving mark cannot be stored.
     */
    private function finalize(array $staging_import, $mode)
    {
        $source_import_ids = $this->getSourceImportIds($staging_import['import_id']);
        if (
            $mode === self::MODE_FULL
            || (
                $mode === self::MODE_ACTUALIZE
                && $staging_import['source_type'] === ImportEntityRepository::SOURCE_TYPE_FULL
                && $staging_import['status'] === ImportEntityRepository::STATUS_COMPLETED
            )
        ) {
            if (
                !$this->mapping_repository->markMissingForArchiving(
                    $staging_import['company_id'],
                    ProductDto::ENTITY_TYPE,
                    $source_import_ids
                )
            ) {
                throw new RuntimeException(__('synchro.exception.product_archiving_mark_not_stored'));
            }
        }

        $this->repository->removeByImportIds($source_import_ids);

        return 0;
    }

    /**
     * Returns completed staging portions or the root import for a non-portioned snapshot.
     *
     * @param int $source_import_id Staging import identifier
     *
     * @return array<int>
     */
    private function getSourceImportIds($source_import_id)
    {
        $source_import_ids = $this->repository->findCompletedChildIds($source_import_id);

        return $source_import_ids ?: [$source_import_id];
    }

    /**
     * Validates application process and staging snapshot compatibility.
     *
     * @param array<string, int|string> $process        Application process
     * @param array<string, int|string> $staging_import Staging import
     * @param string                    $mode           Application mode
     *
     * @return void
     *
     * @throws \InvalidArgumentException When process or staging data does not match the requested mode.
     */
    private function validateProcess(array $process, array $staging_import, $mode)
    {
        if (
            !$staging_import
            || $staging_import['parent_import_id'] !== 0
            || $staging_import['entity_type'] !== ImportDataCommand::ENTITY_PRODUCTS
            || !$process['parent_import_id']
            || $process['staging_import_id'] !== $staging_import['import_id']
            || $process['entity_type'] !== ImportDataCommand::ENTITY_PRODUCTS
        ) {
            throw new InvalidArgumentException(__('synchro.exception.product_application_process_required'));
        }

        if (!in_array($mode, [self::MODE_FULL, self::MODE_TEST, self::MODE_ACTUALIZE], true)) {
            throw new InvalidArgumentException(__('synchro.exception.unknown_product_application_mode'));
        }
        if (
            $mode === self::MODE_FULL
            && $staging_import['source_type'] !== ImportEntityRepository::SOURCE_TYPE_FULL
        ) {
            throw new InvalidArgumentException(__('synchro.exception.full_source_import_required'));
        }
        if (
            $mode === self::MODE_TEST
            && $staging_import['source_type'] !== ImportEntityRepository::SOURCE_TYPE_TEST
        ) {
            throw new InvalidArgumentException(__('synchro.exception.test_source_import_required'));
        }

        $allowed_statuses = $mode === self::MODE_ACTUALIZE
            ? [ImportEntityRepository::STATUS_COMPLETED, ImportEntityRepository::STATUS_PARTIAL_SUCCESS]
            : [ImportEntityRepository::STATUS_COMPLETED];
        if (!in_array($staging_import['status'], $allowed_statuses, true)) {
            throw new InvalidArgumentException(__('synchro.exception.source_import_not_completed'));
        }
    }
}
