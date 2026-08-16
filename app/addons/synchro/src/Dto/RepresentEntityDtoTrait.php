<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Implements common methods of an external entity DTO.
 *
 * @property int|string $id
 */
trait RepresentEntityDtoTrait
{
    /**
     * @return string
     */
    public function getEntityId()
    {
        return (string) $this->id;
    }

    /**
     * @return string
     */
    public function getEntityType()
    {
        return static::ENTITY_TYPE;
    }
}
