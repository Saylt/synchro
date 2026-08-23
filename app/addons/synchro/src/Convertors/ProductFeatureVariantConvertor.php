<?php

namespace Tygh\Addons\Synchro\Convertors;

/**
 * Converts product feature variant data received from the external API.
 */
class ProductFeatureVariantConvertor implements ConvertorInterface
{
    /**
     * @inheritDoc
     */
    public function convert(array $data, $import_id = 0)
    {
        return $data;
    }
}
