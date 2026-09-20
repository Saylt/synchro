<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Addons\Synchro\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository;
use Tygh\Common\OperationResult;
use Tygh\Database\Connection;
use Tygh\Enum\ObjectStatuses;

/**
 * Imports normalized products into CS-Cart.
 */
class ProductImporter
{
    const LOG_SOURCE = 'synchro_import.products';

    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository */
    private $feature_mapping_repository;

    /** @var \Tygh\Addons\Synchro\Importers\WarehouseImporter */
    private $warehouse_importer;

    /** @var \Tygh\Addons\Synchro\Importers\ProductStockUpdater */
    private $stock_updater;

    /** @var \Tygh\Addons\Synchro\Importers\ImageImporter */
    private $image_importer;

    /** @var \Tygh\Addons\Synchro\Logging */
    private $logging;

    /**
     * Initializes the product importer.
     *
     * @param \Tygh\Database\Connection                                       $database                   Database connection
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository       $mapping_repository         Entity mapping repository
     * @param \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository $feature_mapping_repository Product feature mapping repository
     * @param \Tygh\Addons\Synchro\Importers\WarehouseImporter                $warehouse_importer         Warehouse importer
     * @param \Tygh\Addons\Synchro\Importers\ProductStockUpdater              $stock_updater              Stock updater
     * @param \Tygh\Addons\Synchro\Importers\ImageImporter                    $image_importer             Image importer
     * @param \Tygh\Addons\Synchro\Logging|null                               $logging                    Synchro journal service
     */
    public function __construct(
        Connection $database,
        ImportEntityMapRepository $mapping_repository,
        ProductFeatureMappingRepository $feature_mapping_repository,
        WarehouseImporter $warehouse_importer,
        ProductStockUpdater $stock_updater,
        ImageImporter $image_importer,
        Logging $logging = null
    ) {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
        $this->feature_mapping_repository = $feature_mapping_repository;
        $this->warehouse_importer = $warehouse_importer;
        $this->stock_updater = $stock_updater;
        $this->image_importer = $image_importer;
        $this->logging = $logging ?: new Logging($database);
    }

    /**
     * Imports or actualizes a batch of normalized products.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\ProductDto> $products   Imported products
     * @param int                                        $company_id Company identifier
     * @param bool                                       $actualize  Whether only price and stock must be updated
     *
     * @return array{
     *     product_ids: array<string, int>,
     *     fully_updated_external_ids: array<string>
     * }
     *
     * @throws \Throwable When product stock data cannot be saved.
     */
    public function import(array $products, $company_id, $actualize = false)
    {
        if (!$products) {
            return [
                'product_ids'                 => [],
                'fully_updated_external_ids' => [],
            ];
        }

        $mappings = $this->mapping_repository->findByExternalIds(
            $company_id,
            ProductDto::ENTITY_TYPE,
            $this->getProductExternalIds($products)
        );
        $existing_product_ids = $this->findExistingProductIds($mappings);
        $feature_mappings = [];
        $variant_mappings = [];

        if (!$actualize) {
            list($feature_mappings, $variant_mappings) = $this->findFeatureMappings($products, $company_id);
        }
        $category_mappings = $actualize ? [] : $this->findCategoryMappings($products, $company_id);

        $current_images = $actualize
            ? []
            : $this->image_importer->findByObjectIds('product', array_keys($existing_product_ids));
        $imported_product_ids = [];
        $fully_updated_external_ids = [];
        $product_stocks = [];

        foreach ($products as $product) {
            $result = $this->processProduct(
                $product,
                $company_id,
                $actualize,
                $mappings,
                $existing_product_ids,
                $feature_mappings,
                $variant_mappings,
                $category_mappings,
                $current_images
            );
            if (!$result) {
                continue;
            }

            $external_id = $product->getEntityId();
            $product_id = $result['product_id'];
            $imported_product_ids[$external_id] = $product_id;
            if ($result['is_fully_updated']) {
                $fully_updated_external_ids[] = $external_id;
            }
            $product_stocks[$product_id] = [
                'amount'     => $product->amount,
                'warehouses' => $result['warehouse_amounts'],
            ];
        }

        if ($product_stocks) {
            $this->stock_updater->update($product_stocks);
        }

        return [
            'product_ids'                 => $imported_product_ids,
            'fully_updated_external_ids' => $fully_updated_external_ids,
        ];
    }

    /**
     * Gets the external identifiers of products in a batch.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\ProductDto> $products Imported products
     *
     * @return array<string>
     */
    private function getProductExternalIds(array $products)
    {
        $external_ids = [];

        foreach ($products as $product) {
            $external_ids[] = $product->getEntityId();
        }

        return $external_ids;
    }

    /**
     * Finds local mappings for all features and variants used by a product batch.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\ProductDto> $products   Imported products
     * @param int                                        $company_id Company identifier
     *
     * @return array{0: array<string, int>, 1: array<string, array<string, int|string>>}
     */
    private function findFeatureMappings(array $products, $company_id)
    {
        $external_feature_ids = [];
        $external_variant_ids = [];
        $external_manufacturer_ids = [];

        foreach ($products as $product) {
            foreach ($product->feature_variant_ids as $external_feature_id => $product_variant_ids) {
                $external_feature_ids[] = $external_feature_id;

                foreach ($product_variant_ids as $external_variant_id) {
                    $external_variant_ids[] = $external_variant_id;
                }
            }
            if (!$product->manufacturer instanceof ManufacturerDto) {
                continue;
            }
            $external_feature_ids[] = ManufacturerDto::ENTITY_TYPE;
            $external_manufacturer_ids[] = $product->manufacturer->getEntityId();
        }

        if (!$external_feature_ids) {
            return [[], []];
        }

        $variant_mappings = $external_variant_ids
            ? $this->mapping_repository->findByExternalIds(
                $company_id,
                ProductFeatureVariantDto::ENTITY_TYPE,
                array_values(array_unique($external_variant_ids))
            )
            : [];
        if ($external_manufacturer_ids) {
            $variant_mappings += $this->mapping_repository->findByExternalIds(
                $company_id,
                ManufacturerDto::ENTITY_TYPE,
                array_values(array_unique($external_manufacturer_ids))
            );
        }

        return [
            $this->feature_mapping_repository->findByExternalIds(
                $company_id,
                array_values(array_unique($external_feature_ids))
            ),
            $variant_mappings,
        ];
    }

    /**
     * Finds local mappings for all categories referenced by a product batch.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\ProductDto> $products   Imported products
     * @param int                                        $company_id Company identifier
     *
     * @return array<string, array<string, int|string>>
     */
    private function findCategoryMappings(array $products, $company_id)
    {
        $external_category_ids = [];

        foreach ($products as $product) {
            foreach ($product->categories as $category) {
                $external_category_ids[] = $category->getEntityId();
            }
        }
        if (!$external_category_ids) {
            return [];
        }

        return $this->mapping_repository->findByExternalIds(
            $company_id,
            CategoryDto::ENTITY_TYPE,
            array_values(array_unique($external_category_ids))
        );
    }

    /**
     * Imports or actualizes one product and prepares its stock update.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto                                                             $product              Imported product
     * @param int                                                                                             $company_id           Company identifier
     * @param bool                                                                                            $actualize            Whether only price and stock must be updated
     * @param array<string, array<string, int|string>>                                                        $mappings             Product mappings
     * @param array<int, true>                                                                                $existing_product_ids Existing local product identifiers
     * @param array<string, int>                                                                              $feature_mappings     External-to-local feature mappings
     * @param array<string, array<string, int|string>>                                                        $variant_mappings     External-to-local variant mappings
     * @param array<string, array<string, int|string>>                                                        $category_mappings    External-to-local category mappings
     * @param array<int, array<string, array{pair_id: int, type: string, position: int, image_path: string}>> $current_images       Current product images
     *
     * @return array{product_id: int, warehouse_amounts: array<int, int>, is_fully_updated: bool}|null
     */
    private function processProduct(
        ProductDto $product,
        $company_id,
        $actualize,
        array $mappings,
        array $existing_product_ids,
        array $feature_mappings,
        array $variant_mappings,
        array $category_mappings,
        array $current_images
    ) {
        $external_id = $product->getEntityId();
        $mapping = isset($mappings[$external_id]) ? $mappings[$external_id] : [];
        $product_id = $this->findMappedProductId($mapping, $existing_product_ids);

        if ($actualize && !$product_id) {
            $this->logging->error(
                self::LOG_SOURCE,
                __('synchro.product_import_error.product_not_found', ['[external_id]' => $external_id])
            );

            return null;
        }

        $category_ids = $actualize ? [] : $this->resolveProductCategoryIds($product, $category_mappings);
        if (!$actualize && !$category_ids) {
            $this->logging->error(
                self::LOG_SOURCE,
                __('synchro.product_import_error.categories_not_resolved', ['[external_id]' => $external_id])
            );

            return null;
        }

        $warehouse_amounts = $this->importWarehouses($product);
        if ($warehouse_amounts === false) {
            $this->logging->error(self::LOG_SOURCE, __('synchro.product_import_error.skipped_warehouse_not_resolved', [
                '[external_id]' => $external_id,
            ]));

            return null;
        }

        if ($actualize) {
            $this->actualizeProduct($product, $product_id, $company_id);

            return [
                'product_id'       => $product_id,
                'warehouse_amounts' => $warehouse_amounts,
                'is_fully_updated' => false,
            ];
        }

        $product_id = $this->saveProduct(
            $product,
            $product_id,
            $company_id,
            $this->resolveProductFeatureValues($product, $feature_mappings, $variant_mappings),
            $category_ids
        );
        if (!$product_id) {
            return null;
        }

        return [
            'product_id'       => $product_id,
            'warehouse_amounts' => $warehouse_amounts,
            'is_fully_updated' => $this->syncImages(
                $product,
                $product_id,
                $mapping,
                isset($current_images[$product_id]) ? $current_images[$product_id] : []
            ),
        ];
    }

    /**
     * Finds an existing local product from its external mapping.
     *
     * @param array<string, int|string> $mapping              Product mapping
     * @param array<int, true>          $existing_product_ids Existing local product identifiers
     *
     * @return int
     */
    private function findMappedProductId(array $mapping, array $existing_product_ids)
    {
        $mapped_product_id = isset($mapping['local_id']) ? (int) $mapping['local_id'] : 0;

        return isset($existing_product_ids[$mapped_product_id]) ? $mapped_product_id : 0;
    }

    /**
     * Actualizes the price of an existing product.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto $product    Imported product
     * @param int                                 $product_id Local product identifier
     * @param int                                 $company_id Company identifier
     *
     * @return void
     */
    private function actualizeProduct(ProductDto $product, $product_id, $company_id)
    {
        fn_update_product_prices($product_id, ['price' => $product->price], $company_id);
    }

    /**
     * Resolves all mapped local category identifiers for a product.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto      $product           Imported product
     * @param array<string, array<string, int|string>> $category_mappings External-to-local category mappings
     *
     * @return array<int>
     */
    private function resolveProductCategoryIds(ProductDto $product, array $category_mappings)
    {
        $category_ids = [];

        foreach ($product->categories as $category) {
            $external_category_id = $category->getEntityId();
            $category_id = isset($category_mappings[$external_category_id]['local_id'])
                ? (int) $category_mappings[$external_category_id]['local_id']
                : 0;
            if (!$category_id) {
                $this->logging->error(self::LOG_SOURCE, __('synchro.product_import_error.category_mapping_not_found', [
                    '[external_id]'          => $product->getEntityId(),
                    '[category_external_id]' => $external_category_id,
                ]));
                continue;
            }

            $category_ids[] = $category_id;
        }

        return $category_ids;
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
                $this->logging->error(self::LOG_SOURCE, __('synchro.product_import_error.warehouse_not_resolved', [
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
     * @param \Tygh\Addons\Synchro\Dto\ProductDto $product                Imported product
     * @param int                                 $product_id             Existing local product identifier
     * @param int                                 $company_id             Company identifier
     * @param array<int, int>                     $product_feature_values Product feature values
     * @param array<int>                          $category_ids           Local category identifiers
     *
     * @return int
     */
    private function saveProduct(
        ProductDto $product,
        $product_id,
        $company_id,
        array $product_feature_values,
        array $category_ids
    ) {
        $product_data = [
            'product'          => $product->name,
            'product_code'     => $product->product_code,
            'full_description' => $product->description,
            'seo_name'         => $product->seo_name,
            'amount'           => $product->amount,
            'price'            => $product->price,
        ];

        if ($product_feature_values) {
            $product_data['product_features'] = $product_feature_values;
        }
        if ($category_ids) {
            $product_data['category_ids'] = $category_ids;
        }

        if (!$product_id) {
            $product_data = [
                'company_id' => $company_id,
                'status'     => ObjectStatuses::ACTIVE,
            ] + $product_data;
        }

        $product_id = (int) fn_update_product($product_data, $product_id);
        if (!$product_id) {
            $this->logging->error(self::LOG_SOURCE, __('synchro.product_import_error.update_failed', [
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
     * Resolves prepared local feature variants for a product.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto      $product          Imported product
     * @param array<string, int>                       $feature_mappings External-to-local feature mappings
     * @param array<string, array<string, int|string>> $variant_mappings External-to-local variant mappings
     *
     * @return array<int, int>
     */
    private function resolveProductFeatureValues(ProductDto $product, array $feature_mappings, array $variant_mappings)
    {
        $values = [];
        $conflicted_features = [];

        foreach ($product->feature_variant_ids as $external_feature_id => $product_variant_ids) {
            if (!array_key_exists($external_feature_id, $feature_mappings)) {
                $this->logging->warning(self::LOG_SOURCE, __('synchro.product_import_error.feature_mapping_not_found', [
                    '[external_id]'         => $product->getEntityId(),
                    '[feature_external_id]' => $external_feature_id,
                ]));
                continue;
            }

            $feature_id = $feature_mappings[$external_feature_id];

            if (!$feature_id) {
                continue;
            }

            foreach ($product_variant_ids as $external_variant_id) {
                $variant_id = isset($variant_mappings[$external_variant_id]['local_id'])
                    ? (int) $variant_mappings[$external_variant_id]['local_id']
                    : 0;

                if (!$variant_id || isset($conflicted_features[$feature_id])) {
                    continue;
                }

                if (isset($values[$feature_id]) && $values[$feature_id] !== $variant_id) {
                    unset($values[$feature_id]);
                    $conflicted_features[$feature_id] = true;
                    $this->logging->warning(self::LOG_SOURCE, __('synchro.product_import_error.feature_conflict', [
                        '[external_id]' => $product->getEntityId(),
                        '[feature_id]'  => $feature_id,
                    ]));
                    continue;
                }

                $values[$feature_id] = $variant_id;
            }
        }

        $this->addManufacturerFeatureValue($product, $feature_mappings, $variant_mappings, $values);

        return $values;
    }

    /**
     * Adds a resolved manufacturer variant as the configured local brand feature.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto      $product          Imported product
     * @param array<string, int>                       $feature_mappings External-to-local feature mappings
     * @param array<string, array<string, int|string>> $variant_mappings External-to-local variant mappings
     * @param array<int, int>                          $values           Local feature values
     *
     * @return void
     */
    private function addManufacturerFeatureValue(
        ProductDto $product,
        array $feature_mappings,
        array $variant_mappings,
        array &$values
    ) {
        if (!$product->manufacturer instanceof ManufacturerDto) {
            return;
        }

        if (!array_key_exists(ManufacturerDto::ENTITY_TYPE, $feature_mappings)) {
            $this->logging->warning(self::LOG_SOURCE, __('synchro.product_import_error.brand_feature_mapping_not_found', [
                '[external_id]' => $product->getEntityId(),
            ]));

            return;
        }

        $feature_id = $feature_mappings[ManufacturerDto::ENTITY_TYPE];
        if (!$feature_id) {
            return;
        }

        $manufacturer_id = $product->manufacturer->getEntityId();
        $variant_id = isset($variant_mappings[$manufacturer_id]['local_id'])
            ? (int) $variant_mappings[$manufacturer_id]['local_id']
            : 0;
        if (!$variant_id) {
            $this->logging->warning(self::LOG_SOURCE, __('synchro.product_import_error.brand_variant_mapping_not_found', [
                '[external_id]'     => $product->getEntityId(),
                '[manufacturer_id]' => $manufacturer_id,
            ]));

            return;
        }

        if (isset($values[$feature_id]) && $values[$feature_id] !== $variant_id) {
            unset($values[$feature_id]);
            $this->logging->warning(self::LOG_SOURCE, __('synchro.product_import_error.feature_conflict', [
                '[external_id]' => $product->getEntityId(),
                '[feature_id]'  => $feature_id,
            ]));

            return;
        }

        $values[$feature_id] = $variant_id;
    }

    /**
     * Synchronizes product images and logs every reported failure.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto                                                 $product        Imported product
     * @param int                                                                                 $product_id     Local product identifier
     * @param array<string, int|string>                                                           $mapping        Product mapping
     * @param array<string, array{pair_id: int, type: string, position: int, image_path: string}> $current_images Current product images
     *
     * @return bool
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

        return $result->isSuccess();
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
            $this->logging->warning(self::LOG_SOURCE, __('synchro.product_import_error.image_sync_failed', [
                '[external_id]' => $external_id,
                '[error]'       => $error,
            ]));
        }
    }
}
