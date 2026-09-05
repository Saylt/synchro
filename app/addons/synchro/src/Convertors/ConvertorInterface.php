<?php

namespace Tygh\Addons\Synchro\Convertors;

/**
 * Converts data received from the external API.
 */
interface ConvertorInterface
{
    /**
     * @param array<array-key, array|bool|float|int|string|null> $data           External API data
     * @param int                                                $import_id      Import identifier
     * @param int                                                $cron_script_id Cron script identifier
     * @param int                                                $import_process_id Import process identifier
     *
     * @return array<array-key, array|bool|float|int|object|string|null>
     *
     * @throws \Tygh\Addons\Synchro\Exceptions\TaskInterruptedException When task interruption is requested.
     */
    public function convert(array $data, $import_id = 0, $cron_script_id = 0, $import_process_id = 0);
}
