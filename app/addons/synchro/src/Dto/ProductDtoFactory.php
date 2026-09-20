<?php

namespace Tygh\Addons\Synchro\Dto;

use Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor;

class ProductDtoFactory
{
    /** @var \Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor */
    private $product_feature_convertor;

    /**
     * Initializes the source product DTO factory.
     *
     * @param \Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor $product_feature_convertor Product feature convertor
     */
    public function __construct(ProductFeatureConvertor $product_feature_convertor)
    {
        $this->product_feature_convertor = $product_feature_convertor;
    }

    /**
     * Converts one source product into a normalized product DTO.
     *
     * @param array<string, array|float|int|string> $source_product           Source product data
     * @param int                                    $import_id               Import identifier
     * @param bool                                   $collect_product_features Whether properties populate feature assignments and the staging snapshot
     *
     * @return \Tygh\Addons\Synchro\Dto\ProductDto|false
     */
    public function create(array $source_product, $import_id = 0, $collect_product_features = true)
    {
        if (
            isset($source_product['error'])
            && !empty($source_product['error'])
        ) {
            return false;
        }
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
        if ($collect_product_features) {
            $product->feature_variant_ids = $this->product_feature_convertor->convertProductFeatureVariantIds(
                $source_product['properties'],
                $import_id
            );
        }

        foreach ($source_product['rests'] as $source_warehouse) {
            if ($source_warehouse['name'] === null || !$source_warehouse['rest']) {
                continue;
            }
            $warehouse = new WarehouseDto();
            $warehouse->id = $source_warehouse['name'];
            $warehouse->amount = $source_warehouse['rest'];
            $warehouse->checked_at = $source_warehouse['checked'];
            $warehouse->purchase_price = $source_warehouse['price'];
            $product->warehouses[] = $warehouse;
            $product->amount += $warehouse->amount;
        }

        return $product;
    }
}
