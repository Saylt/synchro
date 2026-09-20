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
    $params = [];
    foreach (['page', 'q', 'mapping_status', 'items_per_page'] as $name) {
        if (isset($_REQUEST[$name]) && is_scalar($_REQUEST[$name])) {
            $params[$name] = (string) $_REQUEST[$name];
        }
    }
    $items_per_page = !empty($params['items_per_page'])
        ? (int) $params['items_per_page']
        : (int) Registry::get('settings.Appearance.admin_elements_per_page');
    $snapshot_repository = ServiceProvider::getProductFeatureSnapshotRepository();
    $import_id = $snapshot_repository->findLatestMappingSnapshotId($company_id);
    $feature_mappings = [];
    $search = [
        'page'           => 1,
        'items_per_page' => $items_per_page,
        'total_items'    => 0,
        'q'              => isset($params['q']) ? $params['q'] : '',
        'mapping_status' => isset($params['mapping_status']) ? $params['mapping_status'] : 'all',
    ];
    if ($import_id) {
        list($feature_mappings, $search) = $snapshot_repository->getMappingPage(
            $import_id,
            $company_id,
            $params,
            $items_per_page
        );
    }

    Tygh::$app['view']->assign([
        'synchro_import_id'        => $import_id,
        'synchro_feature_mappings' => $feature_mappings,
        'search'                    => $search,
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
