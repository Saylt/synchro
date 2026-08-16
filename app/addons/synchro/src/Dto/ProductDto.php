<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Represents a product received from the external API.
 */
class ProductDto implements RepresentEntityDto
{
    use RepresentEntityDtoTrait;

    const ENTITY_TYPE = 'products';

    /** @var int */
    public $id;

    /** @var string */
    public $source_error;

    /** @var string */
    public $product_code;

    /** @var string */
    public $name;

    /** @var string */
    public $description;

    /** @var string */
    public $seo_name;

    /** @var string */
    public $etm_id;

    /** @var int */
    public $rl_id;

    /** @var array<array-key, \Tygh\Addons\Synchro\Dto\CategoryDto> */
    public $categories = [];

    /** @var \Tygh\Addons\Synchro\Dto\ManufacturerDto|null */
    public $manufacturer;

    /** @var array<array-key, array{url: string, hash: string}> */
    public $images = [];

    /** @var array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto> */
    public $features = [];

    /** @var array<array-key, \Tygh\Addons\Synchro\Dto\WarehouseDto> */
    public $warehouses = [];

    /** @var int */
    public $amount = 0;

    /** @var float */
    public $purchase_price;

    /** @var float */
    public $price;
}
