<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Converts product feature data received from the external API.
 */
class ProductFeatureConvertor implements ConvertorInterface
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var int */
    private $company_id;

    /** @var array<string, \Tygh\Addons\Synchro\Dto\ProductFeatureDto> */
    private $changed_features = [];

    /**
     * Initializes the product feature convertor.
     *
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository Import entity repository
     * @param int                                                    $company_id Company identifier
     */
    public function __construct(ImportEntityRepository $repository, $company_id)
    {
        $this->repository = $repository;
        $this->company_id = $company_id;
    }

    /**
     * Converts feature data and accumulates its variants for staging.
     *
     * @param array<array-key, array|bool|float|int|string|null> $data              External API data
     * @param int                                                $import_id         Import identifier
     * @param int                                                $cron_script_id    Cron script identifier
     * @param int                                                $import_process_id Import process identifier
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto>
     */
    public function convert(array $data, $import_id = 0, $cron_script_id = 0, $import_process_id = 0)
    {
        if (!$data) {
            return [];
        }

        $product_features = [];
        /** @var array<array-key, array> $source_features */
        $source_features = $data;

        foreach ($source_features as $source_feature) {
            $feature_id = (string) $source_feature['id'];
            $product_feature = $this->createProductFeature($source_feature);

            if (!isset($this->changed_features[$feature_id])) {
                $this->changed_features[$feature_id] = clone $product_feature;
            }

            $feature = $this->changed_features[$feature_id];
            $feature->name = $product_feature->name;
            $feature->group_id = $product_feature->group_id;
            $feature->position = $product_feature->position;
            $feature->group_name = $product_feature->group_name;
            $variant = reset($product_feature->variants);
            $feature->variants[$variant->getEntityId()] = $variant;
            $product_features[] = $product_feature;
        }

        return $product_features;
    }

    /**
     * Converts product features without changing the staging snapshot.
     *
     * @param array<array-key, array<string, int|string>> $data External API feature data
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto>
     */
    public function convertProductFeatures(array $data)
    {
        $features = [];
        foreach ($data as $source_feature) {
            $features[] = $this->createProductFeature($source_feature);
        }

        return $features;
    }

    /**
     * Converts one source product property to a feature with one variant.
     *
     * @param array<string, int|string> $source_feature Source product feature
     *
     * @return \Tygh\Addons\Synchro\Dto\ProductFeatureDto
     */
    private function createProductFeature(array $source_feature)
    {
        $feature = new ProductFeatureDto();
        $feature->id = $source_feature['id'];
        $feature->name = $source_feature['title'];
        $feature->group_id = $source_feature['group_id'];
        $feature->position = $source_feature['ordering_in_group'];
        $feature->group_name = $source_feature['group_title'];
        $variant = new ProductFeatureVariantDto();
        $variant->id = sprintf('%s#%s', $feature->id, md5((string) $source_feature['value']));
        $variant->feature_id = $feature->id;
        $variant->name = (string) $source_feature['value'];
        $variant->value = $source_feature['value'];
        $feature->variants[$variant->getEntityId()] = $variant;

        return $feature;
    }

    /**
     * Merges changed product features with stored variants and saves the batch.
     *
     * @param int $import_id Import identifier
     *
     * @return int
     */
    public function save($import_id)
    {
        if (!$this->changed_features) {
            return 0;
        }

        $stored_features = $this->repository->findByEntityIds(
            $import_id,
            ProductFeatureDto::ENTITY_TYPE,
            array_keys($this->changed_features)
        );

        /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $stored_feature */
        foreach ($stored_features as $stored_feature) {
            $feature_id = $stored_feature->getEntityId();
            $feature = $this->changed_features[$feature_id];
            $feature->variants = array_replace($stored_feature->variants, $feature->variants);
        }

        $result = $this->repository->batchSave(
            $import_id,
            $this->company_id,
            array_values($this->changed_features)
        );
        $this->changed_features = [];

        return $result;
    }
}
