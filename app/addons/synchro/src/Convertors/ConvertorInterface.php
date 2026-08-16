<?php

namespace Tygh\Addons\Synchro\Convertors;

/**
 * Converts data received from the external API.
 */
interface ConvertorInterface
{
    /**
     * @param array<array-key, array|bool|float|int|string|null> $data External API data
     *
     * @return array<array-key, array|bool|float|int|object|string|null>
     */
    public function convert(array $data);
}
