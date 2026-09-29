<?php

namespace Tygh\Addons\Synchro\Application;

use InvalidArgumentException;
use RuntimeException;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Importers\ProductFeatureImporter;
use Tygh\Addons\Synchro\Importers\ProductImporter;
use Tygh\Addons\Synchro\Logging;
use Tygh\Addons\Synchro\ProductFeatureMappingManager;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository;
use Tygh\Enum\ProductFeatures;

/**
 * Applies staged product DTOs to CS-Cart.
 */
class ProductApplicationManager
{
    const MODE_FULL = 'full';

    const MODE_TEST = 'test';

    const MODE_ACTUALIZE = 'actualize';

    const FEATURE_BATCH_SIZE = 100;

    const LOG_SOURCE = 'synchro_import.apply_products';

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var \Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository */
    private $feature_snapshot_repository;

    /** @var \Tygh\Addons\Synchro\Importers\ProductFeatureImporter */
    private $feature_importer;

    /** @var \Tygh\Addons\Synchro\Importers\ProductImporter */
    private $product_importer;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\ProductFeatureMappingManager */
    private $feature_mapping_manager;

    /** @var \Tygh\Addons\Synchro\Logging */
    private $logging;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository           $repository                  Staged entity repository
     * @param \Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository $feature_snapshot_repository Product feature snapshots
     * @param \Tygh\Addons\Synchro\Importers\ProductFeatureImporter            $feature_importer            Product feature importer
     * @param \Tygh\Addons\Synchro\Importers\ProductImporter                   $product_importer            Product importer
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository        $mapping_repository          Entity mapping repository
     * @param \Tygh\Addons\Synchro\ProductFeatureMappingManager                $feature_mapping_manager     Product feature mapping manager
     * @param \Tygh\Addons\Synchro\Logging                                     $logging                     Synchro event logger
     */
    public function __construct(
        ImportEntityRepository $repository,
        ProductFeatureSnapshotRepository $feature_snapshot_repository,
        ProductFeatureImporter $feature_importer,
        ProductImporter $product_importer,
        ImportEntityMapRepository $mapping_repository,
        ProductFeatureMappingManager $feature_mapping_manager,
        Logging $logging
    ) {
        $this->repository = $repository;
        $this->feature_snapshot_repository = $feature_snapshot_repository;
        $this->feature_importer = $feature_importer;
        $this->product_importer = $product_importer;
        $this->mapping_repository = $mapping_repository;
        $this->feature_mapping_manager = $feature_mapping_manager;
        $this->logging = $logging;
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
                return $this->prepare($staging_import, $mode);
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
     * @param string                    $mode           Application mode
     *
     * @return int
     */
    private function prepare(array $staging_import, $mode)
    {
        if ($mode === self::MODE_FULL) {
            $this->createMissingFeatureMappings($staging_import);
        }
        $after_external_feature_id = '';

        while (true) {
            $features = $this->feature_snapshot_repository->findMappedFeatureBatch(
                (int) $staging_import['import_id'],
                (int) $staging_import['company_id'],
                $after_external_feature_id,
                self::FEATURE_BATCH_SIZE
            );
            if (!$features) {
                break;
            }

            $this->feature_importer->import($features, (int) $staging_import['company_id']);
            $last_feature = $features[count($features) - 1];
            $after_external_feature_id = $last_feature->getEntityId();
        }

        return 0;
    }

    /**
     * Creates local text selectbox features for imported features without a mapping.
     *
     * Explicitly skipped features already have a mapping row and are excluded by the
     * snapshot query.
     *
     * @param array<string, int|string> $staging_import Staging import
     *
     * @return void
     */
    private function createMissingFeatureMappings(array $staging_import)
    {
        $after_external_feature_id = '';

        while (true) {
            $features = $this->feature_snapshot_repository->findUnmappedFeatureBatch(
                (int) $staging_import['import_id'],
                (int) $staging_import['company_id'],
                $after_external_feature_id,
                self::FEATURE_BATCH_SIZE
            );
            if (!$features) {
                return;
            }

            foreach ($features as $feature) {
                $result = $this->feature_mapping_manager->createAndMapSnapshot(
                    (int) $staging_import['company_id'],
                    (int) $staging_import['import_id'],
                    [$feature->getEntityId()],
                    $feature->name,
                    ProductFeatures::TEXT_SELECTBOX
                );
                if (!$result->isSuccess()) {
                    $this->logging->warning(
                        self::LOG_SOURCE,
                        __('synchro.product_feature_mapping_auto_creation_failed', [
                            '[external_id]' => $feature->getEntityId(),
                            '[error]'       => $result->getFirstError(),
                        ])
                    );
                }
            }

            $last_feature = $features[count($features) - 1];
            $after_external_feature_id = $last_feature->getEntityId();
        }
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
