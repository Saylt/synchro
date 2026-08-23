<?php

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository;
use Tygh\Addons\Synchro\ServiceProvider;

defined('BOOTSTRAP') or die('Access denied');

/** @psalm-suppress PossiblyUndefinedArrayOffset */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $mode === 'update'
    && isset($_REQUEST['sync_provider_id'])
    && $_REQUEST['sync_provider_id'] === 'synchro'
) {
    $company_id = fn_get_runtime_company_id();
    $import_repository = ServiceProvider::getImportEntityRepository();
    $import_id = $import_repository->findLatestCompletedImportId(
        $company_id,
        ImportDataCommand::ENTITY_PRODUCTS
    );
    $features = $import_repository->findAllByEntityType($import_id, ProductFeatureDto::ENTITY_TYPE);
    $external_feature_ids = [];

    /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $feature */
    foreach ($features as $feature) {
        $external_feature_ids[] = $feature->getEntityId();
    }

    $stored_mappings = ServiceProvider::getProductFeatureMappingRepository()->findByExternalIds(
        $company_id,
        $external_feature_ids
    );
    $feature_mappings = [];

    /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $feature */
    foreach ($features as $feature) {
        $external_feature_id = $feature->getEntityId();
        $mapping = isset($stored_mappings[$external_feature_id])
            ? $stored_mappings[$external_feature_id]
            : [
                'action'            => ProductFeatureMappingRepository::ACTION_SKIP,
                'local_feature_ids' => [],
            ];

        $feature_mappings[] = [
            'external_id'       => $external_feature_id,
            'name'              => $feature->name,
            'group_name'        => $feature->group_name,
            'variants_count'    => count($feature->variants),
            'action'            => $mapping['action'],
            'local_feature_ids' => $mapping['local_feature_ids'],
        ];
    }

    usort($feature_mappings, static function (array $left, array $right) {
        return strcasecmp($left['name'], $right['name']);
    });

    Tygh::$app['view']->assign([
        'synchro_import_id'        => $import_id,
        'synchro_feature_mappings' => $feature_mappings,
        'synchro_mapping_actions'  => [
            ProductFeatureMappingRepository::ACTION_SKIP   => __('synchro.feature_mapping_action_skip'),
            ProductFeatureMappingRepository::ACTION_CREATE => __('synchro.feature_mapping_action_create'),
            ProductFeatureMappingRepository::ACTION_MAP    => __('synchro.feature_mapping_action_map'),
        ],
    ]);
}
