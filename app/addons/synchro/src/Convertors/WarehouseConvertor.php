<?php

namespace Tygh\Addons\Synchro\Convertors;

/**
 * Converts warehouse data received from the external API.
 */
class WarehouseConvertor implements ConvertorInterface
{
    /**
     * @inheritDoc
     */
    public function convert(array $data)
    {
        return $data;
    }
}
