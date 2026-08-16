<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Represents a manufacturer received from the external API.
 */
class ManufacturerDto implements RepresentEntityDto
{
    use RepresentEntityDtoTrait;

    const ENTITY_TYPE = 'manufacturers';

    /** @var int */
    public $id;

    /** @var string */
    public $name;
}
