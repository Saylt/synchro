<?php

namespace Tygh\Addons\Synchro\Convertors;

/**
 * Converts data received from the external API.
 */
interface ConvertorInterface
{
    /**
     * @param array<array-key, array|bool|float|int|string|null> $data      External API data
     * @param int                                                $import_id Import identifier
     *
     * @return array<array-key, array|bool|float|int|object|string|null>
     */
    public function convert(array $data, $import_id = 0);
}
