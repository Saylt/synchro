<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Represents a warehouse stock received from the external API.
 */
class WarehouseDto implements RepresentEntityDto
{
    use RepresentEntityDtoTrait;

    const ENTITY_TYPE = 'warehouses';

    /** @var string */
    public $id;

    /** @var int */
    public $amount;

    /** @var string */
    public $checked_at;

    /** @var float */
    public $purchase_price;
}
