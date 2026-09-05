<?php

namespace Tygh\Addons\Synchro\Importers {
    use Tygh\Addons\Synchro\Tests\Unit\WarehouseImporterTest;

    function fn_update_store_location(array $warehouse_data, $warehouse_id)
    {
        return WarehouseImporterTest::updateWarehouse($warehouse_data, $warehouse_id);
    }
}

namespace Tygh\Addons\Synchro\Tests\Unit {

use Tygh\Addons\Synchro\Dto\WarehouseDto;
use Tygh\Addons\Synchro\Importers\WarehouseImporter;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Database\Connection;
use Tygh\Tests\Unit\ATestCase;

class WarehouseImporterTest extends ATestCase
{
    /** @var callable */
    public static $update_warehouse;

    public function testUsesMappedWarehouse()
    {
        $warehouse = $this->createWarehouse();
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getField')
            ->with('SELECT store_location_id FROM ?:store_locations WHERE store_location_id = ?i', 12)
            ->willReturn('12');
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->once())
            ->method('find')
            ->with(1, WarehouseDto::ENTITY_TYPE, 'etm3')
            ->willReturn(['local_id' => 12]);
        self::$update_warehouse = static function () {
            throw new \RuntimeException('Warehouse updater must not be called');
        };

        $importer = new WarehouseImporter($database, $mapping_repository);

        $this->assertSame(12, $importer->import($warehouse));
        $this->assertSame(12, $importer->import($warehouse));
    }

    public function testMapsWarehouseFoundByNameRegardlessOfType()
    {
        $warehouse = $this->createWarehouse();
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getField')
            ->with(
                'SELECT locations.store_location_id FROM ?:store_locations AS locations'
                . ' INNER JOIN ?:store_location_descriptions AS descriptions'
                . ' ON descriptions.store_location_id = locations.store_location_id'
                . ' WHERE locations.company_id = ?i AND descriptions.name = ?s'
                . ' ORDER BY locations.store_location_id LIMIT 1',
                1,
                'etm3'
            )
            ->willReturn('14');
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->once())
            ->method('find')
            ->willReturn([]);
        $mapping_repository->expects($this->once())
            ->method('save')
            ->with(1, WarehouseDto::ENTITY_TYPE, 'etm3', 14, 'etm3');
        self::$update_warehouse = static function () {
            throw new \RuntimeException('Warehouse updater must not be called');
        };

        $this->assertSame(
            14,
            (new WarehouseImporter($database, $mapping_repository))->import($warehouse)
        );
    }

    public function testCreatesWarehouseWithStoreBuilderDefaults()
    {
        $warehouse = $this->createWarehouse();
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getField')
            ->willReturn(false);
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->once())
            ->method('find')
            ->willReturn([]);
        $mapping_repository->expects($this->once())
            ->method('save')
            ->with(1, WarehouseDto::ENTITY_TYPE, 'etm3', 20, 'etm3');
        self::$update_warehouse = function (array $warehouse_data, $warehouse_id) {
            $this->assertSame([
                'company_id' => 1,
                'name'       => 'etm3',
                'latitude'   => 1,
                'longitude'  => 1,
                'store_type' => 'W',
                'status'     => 'A',
            ], $warehouse_data);
            $this->assertSame(0, $warehouse_id);

            return 20;
        };

        $this->assertSame(
            20,
            (new WarehouseImporter($database, $mapping_repository))->import($warehouse)
        );
    }

    /**
     * @return \Tygh\Addons\Synchro\Dto\WarehouseDto
     */
    private function createWarehouse()
    {
        $warehouse = new WarehouseDto();
        $warehouse->id = 'etm3';
        $warehouse->amount = 3;

        return $warehouse;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Database\Connection
     */
    private function createDatabase()
    {
        return $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->setMethods(['getField'])
            ->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Repository\ImportEntityMapRepository
     */
    private function createMappingRepository()
    {
        return $this->getMockBuilder(ImportEntityMapRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['find', 'save'])
            ->getMock();
    }

    /**
     * @param array<string, int|string> $warehouse_data Warehouse data
     * @param int                       $warehouse_id   Warehouse identifier
     *
     * @return int
     */
    public static function updateWarehouse(array $warehouse_data, $warehouse_id)
    {
        return (int) call_user_func(self::$update_warehouse, $warehouse_data, $warehouse_id);
    }
}
}
