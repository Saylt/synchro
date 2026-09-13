<?php

use Tygh\Addons\Synchro\ProductSynchronizationManager;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($mode === 'synchro_full' || $mode === 'synchro_actualize')
) {
    $is_bulk_synchronization = isset($_REQUEST['product_ids']);
    $product_ids = $is_bulk_synchronization ? $_REQUEST['product_ids'] : [$_REQUEST['product_id']];
    $synchronization_mode = $mode === 'synchro_full'
        ? ProductSynchronizationManager::MODE_FULL
        : ProductSynchronizationManager::MODE_ACTUALIZE;

    $result = ServiceProvider::getProductSynchronizationManager()->synchronize(
        $product_ids,
        fn_get_runtime_company_id(),
        $synchronization_mode
    );
    if ($result['synced']) {
        fn_set_notification('N', __('notice'), __('synchro.product_synchronization_completed', [
            '[count]' => $result['synced'],
        ]));
    }
    if ($result['unmapped']) {
        fn_set_notification('W', __('warning'), __('synchro.product_synchronization_unmapped', [
            '[count]' => $result['unmapped'],
        ]));
    }
    if ($result['failed']) {
        fn_set_notification('E', __('error'), __('synchro.product_synchronization_failed', [
            '[count]' => $result['failed'],
        ]));
    }

    return [CONTROLLER_STATUS_REDIRECT, $is_bulk_synchronization
        ? 'products.manage'
        : 'products.update?product_id=' . $_REQUEST['product_id']
    ];
}

if ($mode === 'update') {
    $product_id = (int) $_REQUEST['product_id'];
    $mappings = ServiceProvider::getImportEntityMapRepository()->findByLocalIds(
        fn_get_runtime_company_id(),
        ProductDto::ENTITY_TYPE,
        [$product_id]
    );

    Tygh::$app['view']->assign('synchro_product_is_mapped', isset($mappings[$product_id]));
}

return [CONTROLLER_STATUS_OK];
