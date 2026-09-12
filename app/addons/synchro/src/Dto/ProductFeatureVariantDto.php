<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Represents a product feature variant received from the external API.
 */
class ProductFeatureVariantDto implements RepresentEntityDto
{
    use RepresentEntityDtoTrait;

    const ENTITY_TYPE = 'feature_variants';

    /** @var int|string */
    public $id;

    /** @var int */
    public $feature_id;

    /** @var string */
    public $name;

    /** @var bool|float|int|string|null */
    public $value;

    /** @var array<string> */
    public $images = [];
}
