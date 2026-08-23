<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Represents a product feature received from the external API.
 */
class ProductFeatureDto implements RepresentEntityDto
{
    use RepresentEntityDtoTrait;

    const ENTITY_TYPE = 'features';

    /** @var int */
    public $id;

    /** @var string */
    public $name;

    /** @var int|null */
    public $group_id;

    /** @var int|null */
    public $position;

    /** @var string|null */
    public $group_name;

    /** @var array<string, \Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto> */
    public $variants = [];
}
