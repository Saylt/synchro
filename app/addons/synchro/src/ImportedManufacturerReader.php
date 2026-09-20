<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Represents a completed manufacturer snapshot as one imported product feature.
 */
class ImportedManufacturerReader
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository Import repository
     */
    public function __construct(ImportEntityRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Builds a manufacturer feature from one completed snapshot.
     *
     * @param int $import_id Import identifier
     *
     * @return \Tygh\Addons\Synchro\Dto\ProductFeatureDto
     */
    public function readByImportId($import_id)
    {
        $feature = $this->createFeature();

        foreach ($this->repository->findAllByEntityType($import_id, ManufacturerDto::ENTITY_TYPE) as $manufacturer) {
            if (!$manufacturer instanceof ManufacturerDto) {
                continue;
            }

            $variant = new ProductFeatureVariantDto();
            $variant->id = $manufacturer->getEntityId();
            $variant->feature_id = ManufacturerDto::ENTITY_TYPE;
            $variant->name = $manufacturer->name;
            $variant->value = $manufacturer->name;
            $variant->images = $manufacturer->images;
            $feature->variants[$variant->getEntityId()] = $variant;
        }

        return $feature;
    }

    /**
     * Creates the fixed imported feature used for brand mapping.
     *
     * @return \Tygh\Addons\Synchro\Dto\ProductFeatureDto
     */
    public function createFeature()
    {
        $feature = new ProductFeatureDto();
        $feature->id = ManufacturerDto::ENTITY_TYPE;

        return $feature;
    }
}
