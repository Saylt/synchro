<?php

namespace Tygh\Addons\Synchro\Convertors;

/**
 * Converts product feature data received from the external API.
 */
class ProductFeatureConvertor implements ConvertorInterface
{
    /**
     * @inheritDoc
     */
    public function convert(array $data)
    {
        return $data;
    }
}
