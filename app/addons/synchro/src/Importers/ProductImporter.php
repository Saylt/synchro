<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Common\OperationResult;
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

    /** @var \Tygh\Addons\Synchro\Importers\ImageImporter */
    private $image_importer;

    /**
     * Initializes the product importer.
     *
     * @param \Tygh\Database\Connection                                 $database           Database connection
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     * @param \Tygh\Addons\Synchro\Importers\WarehouseImporter          $warehouse_importer Warehouse importer
     * @param \Tygh\Addons\Synchro\Importers\ProductStockUpdater        $stock_updater      Stock updater
     * @param \Tygh\Addons\Synchro\Importers\ImageImporter              $image_importer     Image importer
     */
    public function __construct(
        Connection $database,
        ImportEntityMapRepository $mapping_repository,
        WarehouseImporter $warehouse_importer,
        ProductStockUpdater $stock_updater,
        ImageImporter $image_importer
    ) {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
        $this->warehouse_importer = $warehouse_importer;
        $this->stock_updater = $stock_updater;
        $this->image_importer = $image_importer;
    }

    /**
     * Imports or actualizes a batch of normalized products.
     *
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
        $existing_product_ids = $this->findExistingProductIds($mappings);
        $current_images = $actualize
            ? []
            : $this->image_importer->findByObjectIds('product', array_keys($existing_product_ids));
        $imported_product_ids = [];
        $product_stocks = [];

        foreach ($products as $product) {
            $external_id = $product->getEntityId();
            $mapping = isset($mappings[$external_id]) ? $mappings[$external_id] : [];
            $mapped_product_id = isset($mapping['local_id']) ? (int) $mapping['local_id'] : 0;
            $product_id = isset($existing_product_ids[$mapped_product_id]) ? $mapped_product_id : 0;

            if ($actualize && !$product_id) {
                $this->logError(
                    __('synchro.product_import_error.product_not_found', ['[external_id]' => $external_id])
                );
                continue;
            }

            $warehouse_amounts = $this->importWarehouses($product);
            if ($warehouse_amounts === false) {
                continue;
            }

            if ($actualize) {
                fn_update_product_prices($product_id, ['price' => $product->price], $company_id);
            } else {
                $product_id = $this->importProduct($product, $product_id, $company_id);
                if (!$product_id) {
                    continue;
                }

                $this->syncImages(
                    $product,
                    $product_id,
                    $mapping,
                    isset($current_images[$product_id]) ? $current_images[$product_id] : []
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
     * Finds mapped products that still exist in CS-Cart.
     *
     * @param array<string, array<string, int|string>> $mappings Product mappings
     *
     * @return array<int, true>
     */
    private function findExistingProductIds(array $mappings)
    {
        $mapped_product_ids = [];

        foreach ($mappings as $mapping) {
            $mapped_product_ids[] = (int) $mapping['local_id'];
        }

        if (!$mapped_product_ids) {
            return [];
        }

        /** @var array<int> $product_ids */
        $product_ids = $this->database->getColumn(
            'SELECT product_id FROM ?:products WHERE product_id IN (?n)',
            array_values(array_unique($mapped_product_ids))
        );

        return array_fill_keys($product_ids, true);
    }

    /**
     * Resolves product warehouses and returns their local amounts.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto $product Imported product
     *
     * @return array<int, int>|false
     */
    private function importWarehouses(ProductDto $product)
    {
        $warehouse_amounts = [];

        foreach ($product->warehouses as $warehouse) {
            $warehouse_id = $this->warehouse_importer->import($warehouse);
            if (!$warehouse_id) {
                $this->logError(__('synchro.product_import_error.warehouse_not_resolved', [
                    '[external_id]' => $product->getEntityId(),
                    '[warehouse]'   => $warehouse->getEntityId(),
                ]));

                return false;
            }

            $warehouse_amounts[$warehouse_id] = $warehouse->amount;
        }

        return $warehouse_amounts;
    }

    /**
     * Creates or fully updates a product and stores its external mapping.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto $product    Imported product
     * @param int                                 $product_id Existing local product identifier
     * @param int                                 $company_id Company identifier
     *
     * @return int
     */
    private function importProduct(ProductDto $product, $product_id, $company_id)
    {
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

        $product_id = (int) fn_update_product($product_data, $product_id);
        if (!$product_id) {
            $this->logError(__('synchro.product_import_error.update_failed', [
                '[external_id]' => $product->getEntityId(),
            ]));

            return 0;
        }

        $this->mapping_repository->save(
            $company_id,
            ProductDto::ENTITY_TYPE,
            $product->getEntityId(),
            $product_id,
            $product->name
        );

        return $product_id;
    }

    /**
     * Synchronizes product images and logs every reported failure.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto                                                 $product        Imported product
     * @param int                                                                                 $product_id     Local product identifier
     * @param array<string, int|string>                                                           $mapping        Product mapping
     * @param array<string, array{pair_id: int, type: string, position: int, image_path: string}> $current_images Current product images
     *
     * @return void
     */
    private function syncImages(ProductDto $product, $product_id, array $mapping, array $current_images)
    {
        $image_urls = [];
        foreach ($product->images as $image) {
            $image_urls[] = $image['url'];
        }

        $result = $this->image_importer->import(
            $product_id,
            'product',
            $image_urls,
            isset($mapping['full_updated_timestamp']) ? (int) $mapping['full_updated_timestamp'] : 0,
            $current_images
        );

        $this->logImageErrors($result, $product->getEntityId());
    }

    /**
     * Writes image synchronization failures to the product import log.
     *
     * @param \Tygh\Common\OperationResult $result      Image synchronization result
     * @param string                       $external_id External product identifier
     *
     * @return void
     */
    private function logImageErrors(OperationResult $result, $external_id)
    {
        /** @var array<int|string, string> $errors */
        $errors = $result->getErrors();

        foreach ($errors as $error) {
            $this->logError(__('synchro.product_import_error.image_sync_failed', [
                '[external_id]' => $external_id,
                '[error]'       => $error,
            ]));
        }
    }

    /**
     * Writes a product import error to the Synchro log.
     *
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
