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
     * @param string                                             $entity_type Imported entity type
     * @param array<array-key, array|bool|float|int|string|null> $data        External API data
     * @param int                                                $import_id   Import identifier
     *
     * @return self
     */
    public static function create($entity_type, array $data = [], $import_id = 0)
    {
        $command = new self();
        $command->entity_type = $entity_type;
        $command->data = $data;
        $command->import_id = $import_id;

        return $command;
    }
}
