<?php

use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Enum\ProductFeatures;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

/** @psalm-suppress PossiblyUndefinedArrayOffset */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $mode === 'update'
    && isset($_REQUEST['sync_provider_id'])
    && $_REQUEST['sync_provider_id'] === 'synchro'
) {
    Registry::set('navigation.tabs', [
        'features' => [
            'title' => __('synchro.product_features'),
            'js'    => true,
        ],
        'brands' => [
            'title' => __('synchro.brands'),
            'js'    => true,
        ],
    ]);

    $company_id = fn_get_runtime_company_id();
    list($import_id, $features) = ServiceProvider::getImportedProductFeatureReader()->readLatest($company_id);
    $external_feature_ids = [];

    /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $feature */
    foreach ($features as $feature) {
        $external_feature_ids[] = $feature->getEntityId();
    }

    $stored_mappings = ServiceProvider::getProductFeatureMappingRepository()->findByExternalIds(
        $company_id,
        $external_feature_ids
    );
    $local_feature_ids = array_values(array_unique(array_filter($stored_mappings)));
    $local_features = $local_feature_ids
        ? Tygh::$app['db']->getSingleHash(
            'SELECT features.feature_id, descriptions.description FROM ?:product_features AS features'
            . ' LEFT JOIN ?:product_features_descriptions AS descriptions'
            . ' ON descriptions.feature_id = features.feature_id AND descriptions.lang_code = ?s'
            . ' WHERE features.feature_id IN (?n)',
            ['feature_id', 'description'],
            CART_LANGUAGE,
            $local_feature_ids
        )
        : [];
    $feature_mappings = [];

    /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $feature */
    foreach ($features as $feature) {
        $external_feature_id = $feature->getEntityId();
        $local_feature_id = isset($stored_mappings[$external_feature_id])
            ? $stored_mappings[$external_feature_id]
            : null;

        $feature_mappings[] = [
            'external_id'             => $external_feature_id,
            'name'                    => $feature->name,
            'group_name'              => $feature->group_name,
            'variants_count'          => count($feature->variants),
            'local_feature_id'        => $local_feature_id,
            'local_feature_name'      => $local_feature_id > 0 && isset($local_features[$local_feature_id])
                ? $local_features[$local_feature_id]
                : '',
            'is_local_feature_missing' => $local_feature_id > 0
                && !array_key_exists($local_feature_id, $local_features),
        ];
    }

    usort($feature_mappings, static function (array $left, array $right) {
        return strcasecmp($left['name'], $right['name']);
    });

    Tygh::$app['view']->assign([
        'synchro_import_id'        => $import_id,
        'synchro_feature_mappings' => $feature_mappings,
        'synchro_target_feature_types' => [
            ProductFeatures::TEXT_SELECTBOX,
            ProductFeatures::NUMBER_SELECTBOX,
        ],
        'synchro_brand_target_feature_types' => [ProductFeatures::EXTENDED],
    ]);

    $brand_feature = ServiceProvider::getImportedManufacturerReader()->createFeature();
    $brand_mapping = ServiceProvider::getProductFeatureMappingRepository()->findByExternalIds(
        $company_id,
        [$brand_feature->getEntityId()]
    );
    $brand_local_feature = [];
    $brand_feature_id = isset($brand_mapping[$brand_feature->getEntityId()])
        ? (int) $brand_mapping[$brand_feature->getEntityId()]
        : 0;
    if ($brand_feature_id) {
        $brand_local_feature = Tygh::$app['db']->getRow(
            'SELECT features.feature_id, descriptions.description FROM ?:product_features AS features'
            . ' LEFT JOIN ?:product_features_descriptions AS descriptions'
            . ' ON descriptions.feature_id = features.feature_id AND descriptions.lang_code = ?s'
            . ' WHERE features.feature_id = ?i',
            CART_LANGUAGE,
            $brand_feature_id
        );
    }
    Tygh::$app['view']->assign([
        'synchro_brand_local_feature_id' => $brand_feature_id,
        'synchro_brand_local_feature'    => $brand_local_feature,
    ]);
}
