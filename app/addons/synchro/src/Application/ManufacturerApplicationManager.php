<?php

namespace Tygh\Addons\Synchro\Application;

use InvalidArgumentException;
use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\ImportedManufacturerReader;
use Tygh\Addons\Synchro\Importers\ImageImporter;
use Tygh\Addons\Synchro\Importers\ProductFeatureImporter;
use Tygh\Addons\Synchro\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Common\OperationResult;

/**
 * Imports one completed manufacturer snapshot as product feature variants.
 */
class ManufacturerApplicationManager
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var \Tygh\Addons\Synchro\ImportedManufacturerReader */
    private $manufacturer_reader;

    /** @var \Tygh\Addons\Synchro\Importers\ProductFeatureImporter */
    private $feature_importer;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\Importers\ImageImporter */
    private $image_importer;

    /** @var \Tygh\Addons\Synchro\Logging */
    private $logging;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository    $repository          Import repository
     * @param \Tygh\Addons\Synchro\ImportedManufacturerReader           $manufacturer_reader Manufacturer snapshot reader
     * @param \Tygh\Addons\Synchro\Importers\ProductFeatureImporter     $feature_importer    Feature variant importer
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository  Entity mapping repository
     * @param \Tygh\Addons\Synchro\Importers\ImageImporter              $image_importer      Image importer
     * @param \Tygh\Addons\Synchro\Logging                              $logging             Synchro journal service
     */
    public function __construct(
        ImportEntityRepository $repository,
        ImportedManufacturerReader $manufacturer_reader,
        ProductFeatureImporter $feature_importer,
        ImportEntityMapRepository $mapping_repository,
        ImageImporter $image_importer,
        Logging $logging
    ) {
        $this->repository = $repository;
        $this->manufacturer_reader = $manufacturer_reader;
        $this->feature_importer = $feature_importer;
        $this->mapping_repository = $mapping_repository;
        $this->image_importer = $image_importer;
        $this->logging = $logging;
    }

    /**
     * Imports brands from a completed manufacturer snapshot.
     *
     * @param int $import_id Manufacturer snapshot identifier
     *
     * @return int Local brand feature identifier
     *
     * @throws \InvalidArgumentException When the snapshot is not completed manufacturer data.
     */
    public function apply($import_id)
    {
        $staging_import = $this->repository->findImport($import_id);

        if (
            !$staging_import
            || $staging_import['parent_import_id']
            || $staging_import['entity_type'] !== ManufacturerDto::ENTITY_TYPE
            || $staging_import['status'] !== ImportEntityRepository::STATUS_COMPLETED
        ) {
            throw new InvalidArgumentException(__('synchro.exception.completed_manufacturer_import_required'));
        }

        $feature = $this->manufacturer_reader->readByImportId($staging_import['import_id']);
        $prepared_features = $this->feature_importer->import(
            [$feature],
            $staging_import['company_id'],
            ManufacturerDto::ENTITY_TYPE
        );
        if (!isset($prepared_features[ManufacturerDto::ENTITY_TYPE])) {
            return 0;
        }
        $this->syncImages($staging_import['company_id'], $feature);

        return (int) $prepared_features[ManufacturerDto::ENTITY_TYPE];
    }

    /**
     * Synchronizes images of mapped local brand variants.
     *
     * @param int                                        $company_id Company identifier
     * @param \Tygh\Addons\Synchro\Dto\ProductFeatureDto $feature    Imported brand feature
     *
     * @return void
     */
    private function syncImages($company_id, ProductFeatureDto $feature)
    {
        if (!$feature->variants) {
            return;
        }

        $mappings = $this->mapping_repository->findByExternalIds(
            $company_id,
            ManufacturerDto::ENTITY_TYPE,
            array_keys($feature->variants)
        );
        $local_variant_ids = [];

        foreach ($mappings as $mapping) {
            if (!empty($mapping['local_id'])) {
                $local_variant_ids[] = (int) $mapping['local_id'];
            }
        }
        $current_images = $this->image_importer->findByObjectIds(
            'feature_variant',
            array_values(array_unique($local_variant_ids))
        );

        foreach ($feature->variants as $external_id => $variant) {
            $mapping = isset($mappings[$external_id]) ? $mappings[$external_id] : [];
            $local_variant_id = isset($mapping['local_id']) ? (int) $mapping['local_id'] : 0;
            if (!$local_variant_id) {
                continue;
            }

            $result = $this->image_importer->import(
                $local_variant_id,
                'feature_variant',
                $variant->images,
                isset($mapping['full_updated_timestamp']) ? (int) $mapping['full_updated_timestamp'] : 0,
                isset($current_images[$local_variant_id]) ? $current_images[$local_variant_id] : []
            );
            $this->logImageErrors($result, $external_id);
            if ($result->isSuccess()) {
                $this->mapping_repository->markFullyUpdated(
                    $company_id,
                    ManufacturerDto::ENTITY_TYPE,
                    $external_id
                );
            }
        }
    }

    /**
     * Writes every brand image failure as a warning.
     *
     * @param \Tygh\Common\OperationResult $result      Image synchronization result
     * @param string|int                   $external_id External manufacturer identifier
     *
     * @return void
     */
    private function logImageErrors(OperationResult $result, $external_id)
    {
        foreach ($result->getErrors() as $error) {
            $this->logging->warning('synchro_import.manufacturers', __('synchro.manufacturer_import_error.image_sync_failed', [
                '[external_id]' => $external_id,
                '[error]'       => $error,
            ]));
        }
    }
}
