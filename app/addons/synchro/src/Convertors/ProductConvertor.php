<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Dto\WarehouseDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Converts product data received from the external API.
 */
class ProductConvertor implements ConvertorInterface
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var int */
    private $company_id;

    /** @var \Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor */
    private $product_feature_convertor;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository  $repository                Import entity repository
     * @param int                                                     $company_id                Company identifier
     * @param \Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor $product_feature_convertor Product feature convertor
     */
    public function __construct(
        ImportEntityRepository $repository,
        $company_id,
        ProductFeatureConvertor $product_feature_convertor
    ) {
        $this->repository = $repository;
        $this->company_id = $company_id;
        $this->product_feature_convertor = $product_feature_convertor;
    }

    /**
     * @inheritDoc
     */
    public function convert(array $data, $import_id = 0)
    {
        if (!$data) {
            return [];
        }

        $products = [];
        /** @var array $source_products */
        $source_products = $data['data'];

        foreach ($source_products as $source_product) {
            $product = new ProductDto();
            $product->id = $source_product['id'];
            $product->source_error = $source_product['error'];
            $product->product_code = $source_product['sku'];
            $product->name = $source_product['title'];
            $product->description = $source_product['description'];
            $product->seo_name = $source_product['url'];
            $product->etm_id = $source_product['etmid'];
            $product->rl_id = $source_product['rlid'];
            $product->images = $source_product['images'];
            $product->purchase_price = $source_product['purchase_price'];
            $product->price = $source_product['user_price'];

            foreach ($source_product['categories'] as $source_category) {
                $category = new CategoryDto();
                $category->id = $source_category['id'];
                $category->name = $source_category['title'];
                $product->categories[] = $category;
            }

            $product->manufacturer = new ManufacturerDto();
            $product->manufacturer->id = $source_product['manufacturer']['id'];
            $product->manufacturer->name = $source_product['manufacturer']['title'];

            $product->features = $this->product_feature_convertor->convert(
                $source_product['properties'],
                $import_id
            );

            foreach ($source_product['rests'] as $source_warehouse) {
                $warehouse = new WarehouseDto();
                $warehouse->id = $source_warehouse['name'];
                $warehouse->amount = $source_warehouse['rest'];
                $warehouse->checked_at = $source_warehouse['checked'];
                $warehouse->purchase_price = $source_warehouse['price'];
                $product->warehouses[] = $warehouse;
                $product->amount += $warehouse->amount;
            }

            $products[] = $product;
        }

        $this->product_feature_convertor->save($import_id);
        $this->repository->batchSave($import_id, $this->company_id, $products);

        return $products;
    }
}
