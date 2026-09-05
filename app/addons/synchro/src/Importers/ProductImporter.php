<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Database\Connection;
use Tygh\Enum\ObjectStatuses;

/**
 * Imports normalized products into CS-Cart.
 */
class ProductImporter
{
    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\Importers\WarehouseImporter */
    private $warehouse_importer;

    /** @var \Tygh\Addons\Synchro\Importers\ProductStockUpdater */
    private $stock_updater;

    /**
     * @param \Tygh\Database\Connection                                 $database           Database connection
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     * @param \Tygh\Addons\Synchro\Importers\WarehouseImporter          $warehouse_importer Warehouse importer
     * @param \Tygh\Addons\Synchro\Importers\ProductStockUpdater        $stock_updater      Stock updater
     */
    public function __construct(
        Connection $database,
        ImportEntityMapRepository $mapping_repository,
        WarehouseImporter $warehouse_importer,
        ProductStockUpdater $stock_updater
    ) {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
        $this->warehouse_importer = $warehouse_importer;
        $this->stock_updater = $stock_updater;
    }

    /**
     * @param array<\Tygh\Addons\Synchro\Dto\ProductDto> $products   Imported products
     * @param int                                        $company_id Company identifier
     * @param bool                                       $actualize  Whether only price and stock must be updated
     *
     * @return array<string, int> Local product identifiers indexed by external identifiers
     *
     * @throws \Throwable When product stock data cannot be saved.
     */
    public function import(array $products, $company_id, $actualize = false)
    {
        if (!$products) {
            return [];
        }

        $external_ids = [];
        foreach ($products as $product) {
            $external_ids[] = $product->getEntityId();
        }

        $mappings = $this->mapping_repository->findByExternalIds(
            $company_id,
            ProductDto::ENTITY_TYPE,
            $external_ids
        );
        $mapped_product_ids = [];
        foreach ($mappings as $mapping) {
            $mapped_product_ids[] = (int) $mapping['local_id'];
        }
        $existing_product_ids = $mapped_product_ids
            ? array_fill_keys($this->database->getColumn(
                'SELECT product_id FROM ?:products WHERE product_id IN (?n)',
                array_values(array_unique($mapped_product_ids))
            ), true)
            : [];
        $imported_product_ids = [];
        $product_stocks = [];

        foreach ($products as $product) {
            $external_id = $product->getEntityId();
            $mapped_product_id = isset($mappings[$external_id]) ? (int) $mappings[$external_id]['local_id'] : 0;
            $product_id = isset($existing_product_ids[$mapped_product_id]) ? $mapped_product_id : 0;

            if ($actualize && !$product_id) {
                $this->logError(
                    __('synchro.product_import_error.product_not_found', ['[external_id]' => $external_id])
                );
                continue;
            }

            $warehouse_amounts = [];
            foreach ($product->warehouses as $warehouse) {
                $warehouse_id = $this->warehouse_importer->import($warehouse);
                if (!$warehouse_id) {
                    $this->logError(__('synchro.product_import_error.warehouse_not_resolved', [
                        '[external_id]' => $external_id,
                        '[warehouse]'   => $warehouse->getEntityId(),
                    ]));
                    continue 2;
                }

                $warehouse_amounts[$warehouse_id] = $warehouse->amount;
            }

            if ($actualize) {
                fn_update_product_prices($product_id, ['price' => $product->price], $company_id);
            } else {
                $product_data = [
                    'product'          => $product->name,
                    'product_code'     => $product->product_code,
                    'full_description' => $product->description,
                    'seo_name'         => $product->seo_name,
                    'amount'           => $product->amount,
                    'price'            => $product->price,
                ];

                if (!$product_id) {
                    $product_data = [
                        'company_id' => $company_id,
                        'status'     => ObjectStatuses::ACTIVE,
                    ] + $product_data;
                }

                $product_id = fn_update_product($product_data, $product_id);
                if (!$product_id) {
                    $this->logError(
                        __('synchro.product_import_error.update_failed', ['[external_id]' => $external_id])
                    );
                    continue;
                }

                $this->mapping_repository->save(
                    $company_id,
                    ProductDto::ENTITY_TYPE,
                    $external_id,
                    $product_id,
                    $product->name
                );
            }

            $imported_product_ids[$external_id] = $product_id;
            $product_stocks[$product_id] = [
                'amount'     => $product->amount,
                'warehouses' => $warehouse_amounts,
            ];
        }

        if ($product_stocks) {
            $this->stock_updater->update($product_stocks);
        }

        return $imported_product_ids;
    }

    /**
     * @param string $error Error message
     *
     * @return void
     */
    private function logError($error)
    {
        fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
            'script' => 'synchro_import.products',
            'error'  => $error,
        ]);
    }
}
