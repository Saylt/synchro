<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository;

/**
 * Converts product feature data received from the external API.
 */
class ProductFeatureConvertor
{
    /** @var \Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository */
    private $repository;

    /** @var array<string, \Tygh\Addons\Synchro\Dto\ProductFeatureDto> */
    private $changed_features = [];

    /**
     * Initializes the product feature convertor.
     *
     * @param \Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository $repository Product feature snapshot repository
     */
    public function __construct(ProductFeatureSnapshotRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Converts product properties into compact feature-to-variant assignments.
     *
     * When an import identifier is provided, also accumulates complete feature
     * definitions for the staging snapshot.
     *
     * @param array<array-key, array<string, int|string>> $data      External product properties
     * @param int                                         $import_id Import identifier
     *
     * @return array<string, array<int, string>>
     */
    public function convertProductFeatureVariantIds(array $data, $import_id = 0)
    {
        list(, $feature_variant_ids) = $this->convertProperties($data, (bool) $import_id);

        return $feature_variant_ids;
    }

    /**
     * Converts product properties without adding them to the staging snapshot.
     *
     * @param array<array-key, array<string, int|string>> $data External product properties
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto>
     */
    public function convertProductFeatures(array $data)
    {
        list($features) = $this->convertProperties($data, false);

        return $features;
    }

    /**
     * Converts product properties and optionally accumulates feature definitions for staging.
     *
     * @param array<array-key, array<string, int|string>> $data             External product properties
     * @param bool                                        $collect_for_stage Whether to collect full feature definitions
     *
     * @return array{0: array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto>, 1: array<string, array<int, string>>}
     */
    private function convertProperties(array $data, $collect_for_stage)
    {
        if (!$data) {
            return [[], []];
        }

        $product_features = [];
        $feature_variant_ids = [];

        foreach ($data as $source_feature) {
            $feature = $this->createProductFeature($source_feature);
            $feature_id = $feature->getEntityId();
            $product_features[] = $feature;
            foreach (array_keys($feature->variants) as $variant_id) {
                $feature_variant_ids[$feature_id][] = $variant_id;
            }

            if (!$collect_for_stage) {
                continue;
            }

            if (!isset($this->changed_features[$feature_id])) {
                $this->changed_features[$feature_id] = clone $feature;
                continue;
            }

            $stored_feature = $this->changed_features[$feature_id];
            $stored_feature->name = $feature->name;
            $stored_feature->group_id = $feature->group_id;
            $stored_feature->position = $feature->position;
            $stored_feature->group_name = $feature->group_name;
            $stored_feature->variants = array_replace($stored_feature->variants, $feature->variants);
        }

        return [$product_features, $feature_variant_ids];
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
     * Saves changed product features and variants for the active import portion.
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

        $result = $this->repository->savePortion($import_id, array_values($this->changed_features));
        $this->changed_features = [];

        return $result;
    }
}
