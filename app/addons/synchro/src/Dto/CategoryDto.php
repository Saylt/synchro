<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Represents a category received from the external API.
 */
class CategoryDto implements RepresentEntityDto
{
    use RepresentEntityDtoTrait;

    const ENTITY_TYPE = 'categories';

    /** @var int */
    public $id;

    /** @var int|null */
    public $parent_id;

    /** @var int */
    public $status;

    /** @var string */
    public $name;

    /** @var string */
    public $full_name;

    /** @var string */
    public $seo_name;

    /** @var string */
    public $description;

    /** @var int */
    public $product_count;

    /** @var int */
    public $level = 0;

    /** @var array<array-key, string> */
    public $images = [];
}
