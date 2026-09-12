<?php

use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Enum\ProductFeatures;

defined('BOOTSTRAP') or die('Access denied');

/** @psalm-suppress PossiblyUndefinedArrayOffset */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_REQUEST['sync_provider_id'])
    && $_REQUEST['sync_provider_id'] === 'synchro'
) {
    if ($mode !== 'update') {
        return [CONTROLLER_STATUS_DENIED];
    }

    $company_id = fn_get_runtime_company_id();
    $mapping_action = isset($_REQUEST['mapping_action']) && is_string($_REQUEST['mapping_action'])
        ? $_REQUEST['mapping_action']
        : '';
    $is_brand_mapping = isset($_REQUEST['mapping_scope']) && $_REQUEST['mapping_scope'] === 'brands';
    if (strpos($mapping_action, 'brand_') === 0) {
        $is_brand_mapping = true;
        $mapping_action = substr($mapping_action, strlen('brand_'));
    }
    if ($is_brand_mapping) {
        $features = [ServiceProvider::getImportedManufacturerReader()->createFeature()];
    } else {
        list($import_id, $features) = ServiceProvider::getImportedProductFeatureReader()->readLatest($company_id);
    }
    $request_import_id = isset($_REQUEST['import_id']) && is_numeric($_REQUEST['import_id'])
        ? (int) $_REQUEST['import_id']
        : 0;

    if (!$is_brand_mapping && (!$import_id || $import_id !== $request_import_id)) {
        fn_set_notification('E', __('error'), __('synchro.feature_mapping_import_changed'));

        return [CONTROLLER_STATUS_OK, 'sync_data.update?sync_provider_id=synchro'];
    }

    $requested_feature_ids = [];

    if (isset($_REQUEST['external_feature_ids']) && is_array($_REQUEST['external_feature_ids'])) {
        foreach ($_REQUEST['external_feature_ids'] as $external_feature_id) {
            if (is_int($external_feature_id) || is_string($external_feature_id)) {
                $requested_feature_ids[$external_feature_id] = true;
            }
        }
    }

    $selected_features = [];

    /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $feature */
    foreach ($features as $feature) {
        if (isset($requested_feature_ids[$feature->getEntityId()])) {
            $selected_features[] = $feature;
        }
    }

    $mapping_manager = ServiceProvider::getProductFeatureMappingManager();
    $local_feature_field = $is_brand_mapping ? 'brand_local_feature_id' : 'feature_local_feature_id';
    $new_feature_name_field = $is_brand_mapping ? 'brand_new_feature_name' : 'feature_new_feature_name';
    $allowed_feature_types = $is_brand_mapping
        ? [ProductFeatures::EXTENDED]
        : [ProductFeatures::TEXT_SELECTBOX, ProductFeatures::NUMBER_SELECTBOX];
    $new_feature_type = $is_brand_mapping ? ProductFeatures::EXTENDED : ProductFeatures::TEXT_SELECTBOX;
    $local_feature_id = isset($_REQUEST[$local_feature_field])
        && (is_int($_REQUEST[$local_feature_field]) || is_string($_REQUEST[$local_feature_field]))
        && is_numeric($_REQUEST[$local_feature_field])
        ? (int) $_REQUEST[$local_feature_field]
        : 0;

    if ($mapping_action === 'skip') {
        $result = $mapping_manager->skip($company_id, $selected_features);
    } elseif ($mapping_action === 'map') {
        $result = $mapping_manager->map(
            $company_id,
            $selected_features,
            $local_feature_id,
            $allowed_feature_types
        );
    } elseif ($mapping_action === 'create') {
        $result = $mapping_manager->createAndMap(
            $company_id,
            $selected_features,
            isset($_REQUEST[$new_feature_name_field]) && is_string($_REQUEST[$new_feature_name_field])
                ? $_REQUEST[$new_feature_name_field]
                : '',
            $new_feature_type
        );
    } else {
        fn_set_notification('E', __('error'), __('synchro.feature_mapping_invalid_action'));

        return [CONTROLLER_STATUS_OK, 'sync_data.update?sync_provider_id=synchro'];
    }

    if ($result->isSuccess()) {
        fn_set_notification('N', __('notice'), __('synchro.feature_mappings_saved'));
    } else {
        $result->showNotifications();
    }

    return [CONTROLLER_STATUS_OK, 'sync_data.update?sync_provider_id=synchro'];
}
