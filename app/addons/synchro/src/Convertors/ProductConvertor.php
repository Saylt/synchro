<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
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

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository Import entity repository
     * @param int                                                    $company_id Company identifier
     */
    public function __construct(ImportEntityRepository $repository, $company_id)
    {
        $this->repository = $repository;
        $this->company_id = $company_id;
    }

    /**
     * @inheritDoc
     */
    public function convert(array $data)
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

            foreach ($source_product['properties'] as $source_feature) {
                $feature = new ProductFeatureDto();
                $feature->id = $source_feature['id'];
                $feature->value = $source_feature['value'];
                $feature->name = $source_feature['title'];
                $feature->group_id = $source_feature['group_id'];
                $feature->position = $source_feature['ordering_in_group'];
                $feature->group_name = $source_feature['group_title'];
                $product->features[] = $feature;
            }

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

        $this->repository->batchSave($this->company_id, $products);

        return $products;
    }
}
