<?php

namespace Tygh\Addons\Synchro\Dto;

/**
 * Represents an external API entity stored before import.
 */
interface RepresentEntityDto
{
    /**
     * @return string
     */
    public function getEntityId();

    /**
     * @return string
     */
    public function getEntityType();
}
