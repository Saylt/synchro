<?php

namespace Tygh\Addons\Synchro\Convertors;

/**
 * Converts manufacturer data received from the external API.
 */
class ManufacturerConvertor implements ConvertorInterface
{
    /**
     * @inheritDoc
     */
    public function convert(array $data)
    {
        return $data;
    }
}
