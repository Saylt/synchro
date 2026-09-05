<?php

namespace Tygh\Addons\Synchro\Importers {
    use Tygh\Addons\Synchro\Tests\Unit\ProductImporterTest;

    function fn_update_product(array $product_data, $product_id)
    {
        return ProductImporterTest::updateProduct($product_data, $product_id);
    }

    function fn_update_product_prices($product_id, array $product_data, $company_id)
    {
        return ProductImporterTest::updateProductPrices($product_id, $product_data, $company_id);
    }

    function fn_log_event($type, $action, array $data)
    {
        ProductImporterTest::logEvent($type, $action, $data);
    }

    function __($name, array $params = [])
    {
        return $name . json_encode($params);
    }
}

namespace Tygh\Addons\Synchro\Tests\Unit {

use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Dto\WarehouseDto;
use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Addons\Synchro\Importers\ProductImporter;
use Tygh\Addons\Synchro\Importers\ProductStockUpdater;
use Tygh\Addons\Synchro\Importers\WarehouseImporter;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Database\Connection;
use Tygh\Tests\Unit\ATestCase;

class ProductImporterTest extends ATestCase
{
    /** @var callable */
    public static $update_product;

    /** @var callable */
    public static $update_product_prices;

    /** @var callable */
    public static $log_event;

    protected function setUp(): void
    {
        parent::setUp();

        self::$update_product_prices = static function () {
            throw new \RuntimeException('Price updater must not be called');
        };
        self::$log_event = static function () {
            throw new \RuntimeException('Logger must not be called');
        };
    }

    public function testCreatesBaseProductAndStoresMapping()
    {
        $product = $this->createProduct();
        $database = $this->createDatabase();
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->once())
            ->method('findByExternalIds')
            ->with(1, ProductDto::ENTITY_TYPE, ['77'])
            ->willReturn([]);
        $mapping_repository->expects($this->once())
            ->method('save')
            ->with(1, ProductDto::ENTITY_TYPE, '77', 100, 'Imported product');
        $mapping_repository->expects($this->never())
            ->method('markFullyUpdated')
            ->with(1, ProductDto::ENTITY_TYPE, '77');
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->expects($this->once())
            ->method('import')
            ->with($product->warehouses[0])
            ->willReturn(12);
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->once())
            ->method('update')
            ->with([
                100 => ['amount' => 4, 'warehouses' => [12 => 3]],
            ]);
        $updated_data = [];
        $updated_product_id = null;
        self::$update_product = static function (array $product_data, $product_id) use (
            &$updated_data,
            &$updated_product_id
        ) {
            $updated_data = $product_data;
            $updated_product_id = $product_id;

            return 100;
        };

        $product_ids = (new ProductImporter(
            $database,
            $mapping_repository,
            $warehouse_importer,
            $stock_updater
        ))->import([$product], 1);

        $this->assertSame(['77' => 100], $product_ids);
        $this->assertSame(0, $updated_product_id);
        $this->assertSame([
            'company_id'       => 1,
            'status'           => 'A',
            'product'          => 'Imported product',
            'product_code'     => 'SKU-77',
            'full_description' => 'Product description',
            'seo_name'         => 'imported-product',
            'amount'           => 4,
            'price'            => 250.5,
        ], $updated_data);
    }

    public function testActualizesOnlyPriceAndAmountOfMappedProduct()
    {
        $product = $this->createProduct();
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('getColumn')
            ->with('SELECT product_id FROM ?:products WHERE product_id IN (?n)', [57])
            ->willReturn(['57']);
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->once())
            ->method('findByExternalIds')
            ->with(1, ProductDto::ENTITY_TYPE, ['77'])
            ->willReturn(['77' => ['local_id' => 57]]);
        $mapping_repository->expects($this->never())
            ->method('markActualized')
            ->with(1, ProductDto::ENTITY_TYPE, '77');
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->expects($this->once())
            ->method('import')
            ->with($product->warehouses[0])
            ->willReturn(12);
        self::$update_product = static function () {
            throw new \RuntimeException('Product updater must not be called');
        };
        self::$update_product_prices = function ($product_id, array $product_data, $company_id) {
            $this->assertSame(57, $product_id);
            $this->assertSame(['price' => 250.5], $product_data);
            $this->assertSame(1, $company_id);

            return $product_data;
        };
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->once())
            ->method('update')
            ->with([
                57 => ['amount' => 4, 'warehouses' => [12 => 3]],
            ]);

        $product_ids = (new ProductImporter(
            $database,
            $mapping_repository,
            $warehouse_importer,
            $stock_updater
        ))->import(
            [$product],
            1,
            true
        );

        $this->assertSame(['77' => 57], $product_ids);
    }

    public function testDoesNotCreateProductDuringActualization()
    {
        $product = $this->createProduct();
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->once())
            ->method('findByExternalIds')
            ->willReturn([]);
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->expects($this->never())
            ->method('import');
        self::$update_product = static function () {
            throw new \RuntimeException('Product updater must not be called');
        };
        $logged_event = [];
        self::$log_event = static function ($type, $action, array $data) use (&$logged_event) {
            $logged_event = [$type, $action, $data];
        };
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->never())->method('update');

        $product_ids = (new ProductImporter(
            $this->createDatabase(),
            $mapping_repository,
            $warehouse_importer,
            $stock_updater
        ))->import([$product], 1, true);

        $this->assertSame([], $product_ids);
        $this->assertSame(Logging::LOG_TYPE_CRON_MANAGER, $logged_event[0]);
        $this->assertSame(Logging::ACTION_ERRORS, $logged_event[1]);
        $this->assertSame('synchro_import.products', $logged_event[2]['script']);
        $this->assertStringContainsString('77', $logged_event[2]['error']);
    }

    public function testLogsWarehouseResolutionFailure()
    {
        $product = $this->createProduct();
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->once())
            ->method('findByExternalIds')
            ->willReturn([]);
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->expects($this->once())
            ->method('import')
            ->with($product->warehouses[0])
            ->willReturn(0);
        self::$update_product = static function () {
            throw new \RuntimeException('Product updater must not be called');
        };
        $logged_event = [];
        self::$log_event = static function ($type, $action, array $data) use (&$logged_event) {
            $logged_event = [$type, $action, $data];
        };
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->never())->method('update');

        $product_ids = (new ProductImporter(
            $this->createDatabase(),
            $mapping_repository,
            $warehouse_importer,
            $stock_updater
        ))->import([$product], 1);

        $this->assertSame([], $product_ids);
        $this->assertSame(Logging::LOG_TYPE_CRON_MANAGER, $logged_event[0]);
        $this->assertSame(Logging::ACTION_ERRORS, $logged_event[1]);
        $this->assertStringContainsString('etm3', $logged_event[2]['error']);
    }

    /**
     * @return \Tygh\Addons\Synchro\Dto\ProductDto
     */
    private function createProduct()
    {
        $product = new ProductDto();
        $product->id = 77;
        $product->name = 'Imported product';
        $product->product_code = 'SKU-77';
        $product->description = 'Product description';
        $product->seo_name = 'imported-product';
        $product->amount = 4;
        $product->price = 250.5;
        $warehouse = new WarehouseDto();
        $warehouse->id = 'etm3';
        $warehouse->amount = 3;
        $product->warehouses[] = $warehouse;

        return $product;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Database\Connection
     */
    private function createDatabase()
    {
        return $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->setMethods(['getColumn'])
            ->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Repository\ImportEntityMapRepository
     */
    private function createMappingRepository()
    {
        return $this->getMockBuilder(ImportEntityMapRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findByExternalIds', 'save', 'markFullyUpdated', 'markActualized'])
            ->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Importers\WarehouseImporter
     */
    private function createWarehouseImporter()
    {
        return $this->getMockBuilder(WarehouseImporter::class)
            ->disableOriginalConstructor()
            ->setMethods(['import'])
            ->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Importers\ProductStockUpdater
     */
    private function createProductStockUpdater()
    {
        return $this->getMockBuilder(ProductStockUpdater::class)
            ->disableOriginalConstructor()
            ->setMethods(['update'])
            ->getMock();
    }

    /**
     * @param array<string, bool|float|int|string> $product_data Product data
     * @param int                                  $product_id   Product identifier
     *
     * @return int
     */
    public static function updateProduct(array $product_data, $product_id)
    {
        return (int) call_user_func(self::$update_product, $product_data, $product_id);
    }

    /**
     * @param int   $product_id   Product identifier
     * @param array $product_data Product data
     * @param int   $company_id   Company identifier
     *
     * @return array
     */
    public static function updateProductPrices($product_id, array $product_data, $company_id)
    {
        return call_user_func(self::$update_product_prices, $product_id, $product_data, $company_id);
    }

    /**
     * @param string $type   Log type
     * @param string $action Log action
     * @param array  $data   Log data
     *
     * @return void
     */
    public static function logEvent($type, $action, array $data)
    {
        call_user_func(self::$log_event, $type, $action, $data);
    }
}
}
