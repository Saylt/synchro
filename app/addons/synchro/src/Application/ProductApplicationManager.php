<?php

namespace Tygh\Addons\Synchro\Application;

use InvalidArgumentException;
use RuntimeException;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\CronManager;
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

    const BATCH_SIZE = 30;

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

    /** @var \Tygh\Addons\Synchro\CronManager */
    private $cron_manager;

    /**
     * Initializes the staged product application manager.
     *
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository    $repository         Staged entity repository
     * @param \Tygh\Addons\Synchro\ImportedProductFeatureReader         $feature_reader     Imported feature reader
     * @param \Tygh\Addons\Synchro\Importers\ProductFeatureImporter     $feature_importer   Product feature importer
     * @param \Tygh\Addons\Synchro\Importers\ProductImporter            $product_importer   Product importer
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     * @param \Tygh\Addons\Synchro\CronManager                          $cron_manager       Cron manager
     */
    public function __construct(
        ImportEntityRepository $repository,
        ImportedProductFeatureReader $feature_reader,
        ProductFeatureImporter $feature_importer,
        ProductImporter $product_importer,
        ImportEntityMapRepository $mapping_repository,
        CronManager $cron_manager
    ) {
        $this->repository = $repository;
        $this->feature_reader = $feature_reader;
        $this->feature_importer = $feature_importer;
        $this->product_importer = $product_importer;
        $this->mapping_repository = $mapping_repository;
        $this->cron_manager = $cron_manager;
    }

    /**
     * Applies one completed parent product import.
     *
     * @param int    $parent_import_id Parent import identifier
     * @param string $mode             Application mode
     * @param int    $cron_script_id   Current cron task identifier
     *
     * @return int Number of successfully applied products
     *
     * @throws \InvalidArgumentException When the source import does not match the requested mode.
     * @throws \RuntimeException When product data cannot be applied or mapping metadata cannot be stored.
     * @throws \Throwable When product data cannot be stored or task interruption is requested.
     */
    public function apply($parent_import_id, $mode, $cron_script_id = 0)
    {
        $parent = $this->repository->findImport($parent_import_id);
        $this->validateParent($parent, $mode);
        $child_import_ids = $this->repository->findCompletedChildIds($parent_import_id);
        if (!$child_import_ids) {
            $child_import_ids = [$parent_import_id];
        }

        $company_id = (int) $parent['company_id'];
        $actualize = $mode === self::MODE_ACTUALIZE;
        $this->cron_manager->ensureTaskCanContinue($cron_script_id);

        if (!$actualize) {
            $features = $this->feature_reader->readByParentImportId($parent_import_id);
            $this->feature_importer->import($features, $company_id);
        }

        $after_entity_id = '';
        $applied_count = 0;

        while (true) {
            $this->cron_manager->ensureTaskCanContinue($cron_script_id);
            $products = $this->repository->findEntityBatch(
                $child_import_ids,
                ProductDto::ENTITY_TYPE,
                $after_entity_id,
                self::BATCH_SIZE
            );
            if (!$products) {
                break;
            }

            /** @var array<\Tygh\Addons\Synchro\Dto\ProductDto> $products */
            $last_product = $products[count($products) - 1];
            $after_entity_id = $last_product->getEntityId();
            $import_result = $this->product_importer->import($products, $company_id, $actualize);
            $successful_external_ids = array_keys($import_result['product_ids']);

            if ($actualize) {
                $is_timestamp_updated = $this->mapping_repository->markActualizedMany(
                    $company_id,
                    ProductDto::ENTITY_TYPE,
                    $successful_external_ids
                );
                if (!$is_timestamp_updated) {
                    throw new RuntimeException('Product actualization timestamp cannot be stored');
                }
            } elseif ($import_result['fully_updated_external_ids']) {
                $is_timestamp_updated = $this->mapping_repository->markFullyUpdatedMany(
                    $company_id,
                    ProductDto::ENTITY_TYPE,
                    $import_result['fully_updated_external_ids']
                );
                if (!$is_timestamp_updated) {
                    throw new RuntimeException('Product full update timestamp cannot be stored');
                }
            }

            $applied_count += count($successful_external_ids);
            if ($cron_script_id) {
                $this->cron_manager->updateProgressStatus(
                    $cron_script_id,
                    __('synchro.applied_products', ['[count]' => $applied_count])
                );
            }
            if (count($successful_external_ids) !== count($products)) {
                throw new RuntimeException('One or more products could not be applied');
            }
        }

        if (
            $mode === self::MODE_FULL
            || (
                $actualize
                && $parent['source_type'] === ImportEntityRepository::SOURCE_TYPE_FULL
                && $parent['status'] === ImportEntityRepository::STATUS_COMPLETED
            )
        ) {
            $is_archiving_mark_updated = $this->mapping_repository->markMissingForArchiving(
                $company_id,
                ProductDto::ENTITY_TYPE,
                $child_import_ids
            );
            if (!$is_archiving_mark_updated) {
                throw new RuntimeException('Product archiving mark cannot be stored');
            }
        }

        $this->repository->removeByImportIds($child_import_ids);

        return $applied_count;
    }

    /**
     * Validates that a parent import can be processed in the requested mode.
     *
     * @param array<string, int|string> $parent Parent import data
     * @param string                    $mode   Application mode
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the source import does not match the requested mode.
     */
    private function validateParent(array $parent, $mode)
    {
        if (
            !$parent
            || (int) $parent['parent_import_id'] !== 0
            || $parent['entity_type'] !== ImportDataCommand::ENTITY_PRODUCTS
        ) {
            throw new InvalidArgumentException('A parent product import is required');
        }

        if (!in_array($mode, [self::MODE_FULL, self::MODE_TEST, self::MODE_ACTUALIZE], true)) {
            throw new InvalidArgumentException('Unknown product application mode');
        }

        if ($mode === self::MODE_FULL && $parent['source_type'] !== ImportEntityRepository::SOURCE_TYPE_FULL) {
            throw new InvalidArgumentException('A full source import is required');
        }

        if ($mode === self::MODE_TEST && $parent['source_type'] !== ImportEntityRepository::SOURCE_TYPE_TEST) {
            throw new InvalidArgumentException('A test source import is required');
        }

        $allowed_statuses = $mode === self::MODE_ACTUALIZE
            ? [ImportEntityRepository::STATUS_COMPLETED, ImportEntityRepository::STATUS_PARTIAL_SUCCESS]
            : [ImportEntityRepository::STATUS_COMPLETED];
        if (!in_array($parent['status'], $allowed_statuses, true)) {
            throw new InvalidArgumentException('The source import has not completed successfully');
        }
    }
}
