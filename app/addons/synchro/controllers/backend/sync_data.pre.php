<?php

use Tygh\Addons\Synchro\ServiceProvider;

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
    list($import_id, $features) = ServiceProvider::getImportedProductFeatureReader()->readLatest($company_id);
    $request_import_id = isset($_REQUEST['import_id']) && is_numeric($_REQUEST['import_id'])
        ? (int) $_REQUEST['import_id']
        : 0;

    if (!$import_id || $import_id !== $request_import_id) {
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

    $mapping_action = isset($_REQUEST['mapping_action']) && is_string($_REQUEST['mapping_action'])
        ? $_REQUEST['mapping_action']
        : '';
    $mapping_manager = ServiceProvider::getProductFeatureMappingManager();
    $local_feature_id = isset($_REQUEST['local_feature_id'])
        && (is_int($_REQUEST['local_feature_id']) || is_string($_REQUEST['local_feature_id']))
        && is_numeric($_REQUEST['local_feature_id'])
        ? (int) $_REQUEST['local_feature_id']
        : 0;

    if ($mapping_action === 'skip') {
        $result = $mapping_manager->skip($company_id, $selected_features);
    } elseif ($mapping_action === 'map') {
        $result = $mapping_manager->map(
            $company_id,
            $selected_features,
            $local_feature_id
        );
    } elseif ($mapping_action === 'create') {
        $result = $mapping_manager->createAndMap(
            $company_id,
            $selected_features,
            isset($_REQUEST['new_feature_name']) && is_string($_REQUEST['new_feature_name'])
                ? $_REQUEST['new_feature_name']
                : ''
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
