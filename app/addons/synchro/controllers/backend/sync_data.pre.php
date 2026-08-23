<?php

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
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
    $import_repository = ServiceProvider::getImportEntityRepository();
    $import_id = $import_repository->findLatestCompletedImportId(
        $company_id,
        ImportDataCommand::ENTITY_PRODUCTS
    );
    $request_import_id = isset($_REQUEST['import_id']) && is_numeric($_REQUEST['import_id'])
        ? (int) $_REQUEST['import_id']
        : 0;

    if (!$import_id || $import_id !== $request_import_id) {
        fn_set_notification('E', __('error'), __('synchro.feature_mapping_import_changed'));

        return [CONTROLLER_STATUS_OK, 'sync_data.update?sync_provider_id=synchro'];
    }

    $features = $import_repository->findAllByEntityType($import_id, ProductFeatureDto::ENTITY_TYPE);
    $available_feature_ids = [];

    /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $feature */
    foreach ($features as $feature) {
        $available_feature_ids[$feature->getEntityId()] = true;
    }

    $mappings = [];
    $raw_mappings = isset($_REQUEST['feature_mappings']) && is_array($_REQUEST['feature_mappings'])
        ? $_REQUEST['feature_mappings']
        : [];

    foreach ($raw_mappings as $external_feature_id => $mapping) {
        if (!isset($available_feature_ids[$external_feature_id]) || !is_array($mapping)) {
            continue;
        }

        $local_feature_ids = [];

        if (isset($mapping['local_feature_ids']) && is_array($mapping['local_feature_ids'])) {
            foreach ($mapping['local_feature_ids'] as $local_feature_id) {
                if (is_int($local_feature_id) || is_string($local_feature_id)) {
                    $local_feature_ids[] = $local_feature_id;
                }
            }
        }

        $mappings[(string) $external_feature_id] = [
            'action' => isset($mapping['action']) && is_string($mapping['action'])
                ? $mapping['action']
                : '',
            'local_feature_ids' => $local_feature_ids,
        ];
    }

    ServiceProvider::getProductFeatureMappingRepository()->replaceMappings($company_id, $mappings);
    fn_set_notification('N', __('notice'), __('synchro.feature_mappings_saved'));

    return [CONTROLLER_STATUS_OK, 'sync_data.update?sync_provider_id=synchro'];
}
