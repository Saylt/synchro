<?php

namespace Tygh\Addons\Synchro\Commands;

/**
 * Describes a batch of external API data to convert and import.
 */
class ImportDataCommand
{
    const ENTITY_PRODUCTS = 'products';

    const ENTITY_CATEGORIES = 'categories';

    const ENTITY_MANUFACTURERS = 'manufacturers';

    const ENTITY_FEATURES = 'features';

    const ENTITY_FEATURE_VARIANTS = 'feature_variants';

    const ENTITY_WAREHOUSES = 'warehouses';

    /**
     * @var string
     */
    public $entity_type;

    /**
     * @var array<array-key, array|bool|float|int|string|null>
     */
    public $data;

    /**
     * @var int
     */
    public $import_id;

    /**
     * @var int
     */
    public $cron_script_id;

    /**
     * @var int
     */
    public $import_process_id;

    /**
     * @param string                                             $entity_type    Imported entity type
     * @param array<array-key, array|bool|float|int|string|null> $data           External API data
     * @param int                                                $import_id      Import identifier
     * @param int                                                $cron_script_id Cron script identifier
     * @param int                                                $import_process_id Import process identifier
     *
     * @return self
     */
    public static function create(
        $entity_type,
        array $data = [],
        $import_id = 0,
        $cron_script_id = 0,
        $import_process_id = 0
    )
    {
        $command = new self();
        $command->entity_type = $entity_type;
        $command->data = $data;
        $command->import_id = $import_id;
        $command->cron_script_id = $cron_script_id;
        $command->import_process_id = $import_process_id;

        return $command;
    }
}
