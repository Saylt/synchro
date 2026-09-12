<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Addons\Synchro\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository;
use Tygh\Database\Connection;
use Tygh\Enum\ProductFeatures;

/**
 * Prepares local variants for mapped imported product features.
 */
class ProductFeatureImporter
{
    const LOG_SOURCE = 'synchro_import.features';

    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository */
    private $feature_mapping_repository;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $entity_mapping_repository;

    /** @var \Tygh\Addons\Synchro\Logging */
    private $logging;

    /**
     * @param \Tygh\Database\Connection                                       $database                   Database connection
     * @param \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository $feature_mapping_repository Feature mapping repository
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository       $entity_mapping_repository  Entity mapping repository
     * @param \Tygh\Addons\Synchro\Logging|null                               $logging                    Synchro journal service
     */
    public function __construct(
        Connection $database,
        ProductFeatureMappingRepository $feature_mapping_repository,
        ImportEntityMapRepository $entity_mapping_repository,
        Logging $logging = null
    ) {
        $this->database = $database;
        $this->feature_mapping_repository = $feature_mapping_repository;
        $this->entity_mapping_repository = $entity_mapping_repository;
        $this->logging = $logging ?: new Logging($database);
    }

    /**
     * Creates or reuses local variants and maps every valid external variant to them.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features            Imported product features
     * @param int                                               $company_id          Company identifier
     * @param string                                            $variant_entity_type External variant entity type
     *
     * @return array<string, int> Prepared local feature IDs indexed by external feature ID
     */
    public function import(array $features, $company_id, $variant_entity_type = ProductFeatureVariantDto::ENTITY_TYPE)
    {
        $features_by_id = [];

        foreach ($features as $feature) {
            if ($feature instanceof ProductFeatureDto) {
                $features_by_id[$feature->getEntityId()] = $feature;
            }
        }

        if (!$features_by_id) {
            return [];
        }

        $feature_mappings = $this->feature_mapping_repository->findByExternalIds(
            $company_id,
            array_keys($features_by_id)
        );
        $target_feature_ids = array_values(array_unique(array_filter($feature_mappings)));

        if (!$target_feature_ids) {
            return [];
        }

        $target_features = $this->database->getHash(
            'SELECT feature_id, feature_type FROM ?:product_features'
            . ' WHERE company_id = ?i AND feature_id IN (?n)',
            'feature_id',
            $company_id,
            $target_feature_ids
        );
        $variant_groups = [];
        $is_feature_prepared = [];
        $external_variant_ids = [];

        foreach ($features_by_id as $external_feature_id => $feature) {
            $target_feature_id = isset($feature_mappings[$external_feature_id])
                ? $feature_mappings[$external_feature_id]
                : 0;

            if (!$target_feature_id) {
                continue;
            }

            if (!isset($target_features[$target_feature_id])) {
                $this->logging->error(self::LOG_SOURCE, __('synchro.product_feature_import_error.target_not_found', [
                    '[external_id]' => $external_feature_id,
                    '[feature_id]'  => $target_feature_id,
                ]));
                continue;
            }

            $feature_type = $target_features[$target_feature_id]['feature_type'];
            $is_supported = false;
            if ($variant_entity_type === ProductFeatureVariantDto::ENTITY_TYPE) {
                $is_supported = in_array(
                    $feature_type,
                    [
                        ProductFeatures::TEXT_SELECTBOX,
                        ProductFeatures::NUMBER_SELECTBOX
                    ],
                    true
                );
            } elseif ($variant_entity_type === ManufacturerDto::ENTITY_TYPE){
                $is_supported = $feature_type === ProductFeatures::EXTENDED;
            }

            if (!$is_supported) {
                $this->logging->error(self::LOG_SOURCE, __('synchro.product_feature_import_error.unsupported_target', [
                    '[external_id]' => $external_feature_id,
                    '[feature_id]'  => $target_feature_id,
                ]));
                continue;
            }

            $is_feature_prepared[$external_feature_id] = true;

            foreach ($feature->variants as $variant) {
                if (
                    $feature_type === ProductFeatures::NUMBER_SELECTBOX
                    && !is_numeric($variant->value)
                ) {
                    $is_feature_prepared[$external_feature_id] = false;
                    $this->logging->error(self::LOG_SOURCE, __('synchro.product_feature_import_error.non_numeric_variant', [
                        '[external_id]' => $external_feature_id,
                        '[value]'       => (string) $variant->value,
                    ]));
                    continue;
                }

                $variant_value = $this->cleanTextValue($variant->value);
                $variant_key = $this->getVariantKey($variant_value, $feature_type);
                $external_variant_id = $variant->getEntityId();

                if (!isset($variant_groups[$target_feature_id][$variant_key])) {
                    $variant_groups[$target_feature_id][$variant_key] = [
                        'feature_type' => $feature_type,
                        'value'        => $variant_value,
                        'sources'      => [],
                    ];
                }

                $variant_groups[$target_feature_id][$variant_key]['sources'][$external_variant_id]
                    = $external_feature_id;
                $external_variant_ids[] = $external_variant_id;
            }
        }

        if (!$variant_groups) {
            return [];
        }

        $local_variants = $this->loadLocalVariants(array_keys($variant_groups), $target_features);
        $stored_variant_mappings = $this->entity_mapping_repository->findByExternalIds(
            $company_id,
            $variant_entity_type,
            $external_variant_ids
        );
        $variant_mappings = [];

        foreach ($variant_groups as $target_feature_id => $variants) {
            foreach ($variants as $variant_key => $variant) {
                $local_variant = $this->findMappedVariant(
                    array_keys($variant['sources']),
                    $target_feature_id,
                    $stored_variant_mappings,
                    $local_variants['by_id']
                );

                if (!$local_variant && isset($local_variants['by_value'][$target_feature_id][$variant_key])) {
                    $local_variant = $local_variants['by_value'][$target_feature_id][$variant_key];
                }

                if (!$local_variant) {
                    $local_variant_id = fn_update_product_feature_variant(
                        $target_feature_id,
                        $variant['feature_type'],
                        ['variant' => $variant['value']],
                        CART_LANGUAGE
                    );

                    if (!$local_variant_id) {
                        foreach ($variant['sources'] as $external_feature_id) {
                            $is_feature_prepared[$external_feature_id] = false;
                        }
                        $this->logging->error(self::LOG_SOURCE, __('synchro.product_feature_import_error.variant_creation_failed', [
                            '[feature_id]' => $target_feature_id,
                            '[value]'      => $variant['value'],
                        ]));
                        continue;
                    }

                    $local_variant = [
                        'variant_id' => (int) $local_variant_id,
                        'feature_id' => $target_feature_id,
                        'variant'    => $variant['value'],
                    ];
                }

                foreach (array_keys($variant['sources']) as $external_variant_id) {
                    $variant_mappings[$external_variant_id] = [
                        'local_id'    => $local_variant['variant_id'],
                        'entity_name' => $local_variant['variant'],
                    ];
                }
            }
        }

        if ($variant_mappings) {
            $this->entity_mapping_repository->saveMany(
                $company_id,
                $variant_entity_type,
                $variant_mappings
            );
        }

        $prepared_features = [];

        foreach ($is_feature_prepared as $external_feature_id => $is_prepared) {
            if ($is_prepared) {
                $prepared_features[$external_feature_id] = $feature_mappings[$external_feature_id];
            }
        }

        return $prepared_features;
    }

    /**
     * Loads local variants and indexes them by ID and normalized value.
     *
     * @param array<int>                                                      $feature_ids     Local feature identifiers
     * @param array<int, array{feature_id: int|string, feature_type: string}> $target_features Local features indexed by ID
     *
     * @return array{
     *     by_id: array<int, array{variant_id: int, feature_id: int, variant: string}>,
     *     by_value: array<int, array<string, array{variant_id: int, feature_id: int, variant: string}>>
     * }
     */
    private function loadLocalVariants(array $feature_ids, array $target_features)
    {
        $rows = $this->database->getArray(
            'SELECT variants.variant_id, variants.feature_id, descriptions.variant'
            . ' FROM ?:product_feature_variants AS variants'
            . ' LEFT JOIN ?:product_feature_variant_descriptions AS descriptions'
            . ' ON descriptions.variant_id = variants.variant_id AND descriptions.lang_code = ?s'
            . ' WHERE variants.feature_id IN (?n)',
            CART_LANGUAGE,
            $feature_ids
        );
        $variants = ['by_id' => [], 'by_value' => []];

        foreach ($rows as $row) {
            $variant = [
                'variant_id' => (int) $row['variant_id'],
                'feature_id' => (int) $row['feature_id'],
                'variant'    => (string) $row['variant'],
            ];
            $variants['by_id'][$variant['variant_id']] = $variant;
            $feature_type = $target_features[$variant['feature_id']]['feature_type'];
            $variant_key = $this->getVariantKey($variant['variant'], $feature_type);

            if (!isset($variants['by_value'][$variant['feature_id']][$variant_key])) {
                $variants['by_value'][$variant['feature_id']][$variant_key] = $variant;
            }
        }

        return $variants;
    }

    /**
     * Finds a valid persisted mapping for any source variant in a normalized group.
     *
     * @param array<string>                                                        $external_variant_ids    External variant identifiers
     * @param int                                                                  $target_feature_id       Local target feature identifier
     * @param array<string, array<string, int|string>>                             $stored_variant_mappings Stored external mappings
     * @param array<int, array{variant_id: int, feature_id: int, variant: string}> $local_variants_by_id    Local variants indexed by ID
     *
     * @return array{variant_id: int, feature_id: int, variant: string}|null
     */
    private function findMappedVariant(
        array $external_variant_ids,
        $target_feature_id,
        array $stored_variant_mappings,
        array $local_variants_by_id
    ) {
        foreach ($external_variant_ids as $external_variant_id) {
            $local_variant_id = isset($stored_variant_mappings[$external_variant_id]['local_id'])
                ? (int) $stored_variant_mappings[$external_variant_id]['local_id']
                : 0;

            if (
                isset($local_variants_by_id[$local_variant_id])
                && $local_variants_by_id[$local_variant_id]['feature_id'] === $target_feature_id
            ) {
                return $local_variants_by_id[$local_variant_id];
            }
        }

        return null;
    }

    /**
     * Removes surrounding and repeated whitespace from a variant value.
     *
     * @param bool|float|int|string|null $value Variant value
     *
     * @return string
     */
    private function cleanTextValue($value)
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Builds a normalized value key for a local feature type.
     *
     * @param string $value        Clean variant value
     * @param string $feature_type Local feature type
     *
     * @return string
     */
    private function getVariantKey($value, $feature_type)
    {
        if ($feature_type === ProductFeatures::NUMBER_SELECTBOX) {
            return (string) (float) $value;
        }

        return mb_strtolower($value, 'UTF-8');
    }
}
