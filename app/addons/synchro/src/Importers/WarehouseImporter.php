<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Addons\Synchro\Dto\WarehouseDto;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Warehouses\Manager;
use Tygh\Database\Connection;
use Tygh\Enum\ObjectStatuses;

/**
 * Resolves imported warehouses to CS-Cart store locations.
 */
class WarehouseImporter
{
    const COMPANY_ID = 1;

    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var array<string, int> */
    private $warehouse_ids = [];

    /**
     * @param \Tygh\Database\Connection                                 $database           Database connection
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     */
    public function __construct(Connection $database, ImportEntityMapRepository $mapping_repository)
    {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
    }

    /**
     * @param \Tygh\Addons\Synchro\Dto\WarehouseDto $warehouse Imported warehouse
     *
     * @return int
     */
    public function import(WarehouseDto $warehouse)
    {
        $external_id = $warehouse->getEntityId();
        if (isset($this->warehouse_ids[$external_id])) {
            return $this->warehouse_ids[$external_id];
        }

        $mapping = $this->mapping_repository->find(self::COMPANY_ID, WarehouseDto::ENTITY_TYPE, $external_id);
        $warehouse_id = isset($mapping['local_id']) ? (int) $mapping['local_id'] : 0;

        if ($warehouse_id) {
            $warehouse_id = (int) $this->database->getField(
                'SELECT store_location_id FROM ?:store_locations WHERE store_location_id = ?i',
                $warehouse_id
            );
        }

        if ($warehouse_id) {
            return $this->warehouse_ids[$external_id] = $warehouse_id;
        }

        $warehouse_id = (int) $this->database->getField(
            'SELECT locations.store_location_id FROM ?:store_locations AS locations'
            . ' INNER JOIN ?:store_location_descriptions AS descriptions'
            . ' ON descriptions.store_location_id = locations.store_location_id'
            . ' WHERE locations.company_id = ?i AND descriptions.name = ?s'
            . ' ORDER BY locations.store_location_id LIMIT 1',
            self::COMPANY_ID,
            $external_id
        );

        if (!$warehouse_id) {
            $warehouse_id = fn_update_store_location([
                'company_id' => self::COMPANY_ID,
                'name'       => $external_id,
                'latitude'   => 1,
                'longitude'  => 1,
                'store_type' => Manager::STORE_LOCATOR_TYPE_WAREHOUSE,
                'status'     => ObjectStatuses::ACTIVE,
            ], 0);
        }

        if ($warehouse_id) {
            $this->mapping_repository->save(
                self::COMPANY_ID,
                WarehouseDto::ENTITY_TYPE,
                $external_id,
                $warehouse_id,
                $external_id
            );
            $this->warehouse_ids[$external_id] = $warehouse_id;
        }

        return $warehouse_id;
    }
}
