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

    if (!function_exists(__NAMESPACE__ . '\\__')) {
        function __($name, array $params = [])
        {
            return $name . json_encode($params);
        }
    }
}

namespace Tygh\Addons\Synchro\Tests\Unit {

use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Addons\Synchro\Dto\WarehouseDto;
use Tygh\Addons\Synchro\Importers\ImageImporter;
use Tygh\Addons\Synchro\Importers\ProductImporter;
use Tygh\Addons\Synchro\Importers\ProductStockUpdater;
use Tygh\Addons\Synchro\Importers\WarehouseImporter;
use Tygh\Addons\Synchro\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository;
use Tygh\Common\OperationResult;
use Tygh\Database\Connection;
use Tygh\Tests\Unit\ATestCase;

class ProductImporterTest extends ATestCase
{
    /** @var callable */
    public static $update_product;

    /** @var callable */
    public static $update_product_prices;

    protected function setUp(): void
    {
        parent::setUp();

        self::$update_product_prices = static function () {
            throw new \RuntimeException('Price updater must not be called');
        };
    }

    public function testCreatesBaseProductAndStoresMapping()
    {
        $product = $this->createProduct();
        $database = $this->createDatabase();
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(2))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, ['77']],
                [1, CategoryDto::ENTITY_TYPE, ['10']]
            )
            ->willReturnOnConsecutiveCalls([], ['10' => ['local_id' => 501]]);
        $mapping_repository->expects($this->once())
            ->method('save')
            ->with(1, ProductDto::ENTITY_TYPE, '77', 100, 'Imported product');
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
        $image_importer = $this->createImageImporter();
        $image_importer->expects($this->once())
            ->method('findByObjectIds')
            ->with('product', [])
            ->willReturn([]);
        $image_importer->expects($this->once())
            ->method('import')
            ->with(
                100,
                'product',
                ['https://example.com/main.jpg', 'https://example.com/additional.jpg'],
                0,
                []
            )
            ->willReturn(new OperationResult(true));
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
            $this->createFeatureMappingRepository(),
            $warehouse_importer,
            $stock_updater,
            $image_importer
        ))->import([$product], 1);

        $this->assertSame([
            'product_ids'                 => ['77' => 100],
            'fully_updated_external_ids' => ['77'],
        ], $product_ids);
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
            'category_ids'     => [501],
        ], $updated_data);
    }

    public function testActualizesOnlyPriceAndAmountOfMappedProduct()
    {
        $product = $this->createProduct();
        $this->addFeature($product, 10, 'Red');
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
        $image_importer = $this->createImageImporter();
        $image_importer->expects($this->never())->method('findByObjectIds');
        $image_importer->expects($this->never())->method('import');
        $feature_mapping_repository = $this->createFeatureMappingRepository();
        $feature_mapping_repository->expects($this->never())->method('findByExternalIds');

        $product_ids = (new ProductImporter(
            $database,
            $mapping_repository,
            $feature_mapping_repository,
            $warehouse_importer,
            $stock_updater,
            $image_importer
        ))->import(
            [$product],
            1,
            true
        );

        $this->assertSame([
            'product_ids'                 => ['77' => 57],
            'fully_updated_external_ids' => [],
        ], $product_ids);
    }

    public function testAssignsResolvedCategoriesToProduct()
    {
        $product = $this->createProduct();
        $product->categories = [];
        $this->addCategory($product, 10);
        $this->addCategory($product, 20);
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(2))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, ['77']],
                [1, CategoryDto::ENTITY_TYPE, ['10', '20']]
            )
            ->willReturnOnConsecutiveCalls([], [
                '10' => ['local_id' => 501],
                '20' => ['local_id' => 502],
            ]);
        $mapping_repository->expects($this->once())->method('save');
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->method('import')->willReturn(12);
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->once())->method('update');
        $image_importer = $this->createImageImporter();
        $image_importer->method('findByObjectIds')->willReturn([]);
        $image_importer->method('import')->willReturn(new OperationResult(true));
        $updated_data = [];
        self::$update_product = static function (array $product_data) use (&$updated_data) {
            $updated_data = $product_data;

            return 100;
        };

        (new ProductImporter(
            $this->createDatabase(),
            $mapping_repository,
            $this->createFeatureMappingRepository(),
            $warehouse_importer,
            $stock_updater,
            $image_importer
        ))->import([$product], 1);

        $this->assertSame([501, 502], $updated_data['category_ids']);
    }

    public function testImportsProductWithResolvedCategoriesAndLogsUnresolvedOnes()
    {
        $product = $this->createProduct();
        $product->categories = [];
        $this->addCategory($product, 10);
        $this->addCategory($product, 20);
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(2))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, ['77']],
                [1, CategoryDto::ENTITY_TYPE, ['10', '20']]
            )
            ->willReturnOnConsecutiveCalls([], ['10' => ['local_id' => 501]]);
        $mapping_repository->expects($this->once())->method('save');
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->method('import')->willReturn(12);
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->once())->method('update');
        $image_importer = $this->createImageImporter();
        $image_importer->method('findByObjectIds')->willReturn([]);
        $image_importer->method('import')->willReturn(new OperationResult(true));
        $updated_data = [];
        self::$update_product = static function (array $product_data) use (&$updated_data) {
            $updated_data = $product_data;

            return 100;
        };
        $logged_errors = [];
        $logging = $this->createCapturingLogging($logged_errors);

        $this->assertSame(
            ['77' => 100],
            (new ProductImporter(
                $this->createDatabase(),
                $mapping_repository,
                $this->createFeatureMappingRepository(),
                $warehouse_importer,
                $stock_updater,
                $image_importer,
                $logging
            ))->import([$product], 1)['product_ids']
        );
        $this->assertSame([501], $updated_data['category_ids']);
        $this->assertCount(1, $logged_errors);
        $this->assertStringContainsString('77', $logged_errors[0]);
        $this->assertStringContainsString('20', $logged_errors[0]);
    }

    public function testSkipsProductWhenNoneOfItsCategoriesAreResolved()
    {
        $product = $this->createProduct();
        $product->categories = [];
        $this->addCategory($product, 10);
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(2))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, ['77']],
                [1, CategoryDto::ENTITY_TYPE, ['10']]
            )
            ->willReturnOnConsecutiveCalls([], []);
        $mapping_repository->expects($this->never())->method('save');
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->expects($this->never())->method('import');
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->never())->method('update');
        $image_importer = $this->createImageImporter();
        $image_importer->expects($this->once())->method('findByObjectIds')->willReturn([]);
        $image_importer->expects($this->never())->method('import');
        self::$update_product = static function () {
            throw new \RuntimeException('Product updater must not be called');
        };
        $logged_errors = [];
        $logging = $this->createCapturingLogging($logged_errors);

        $this->assertSame(
            [
                'product_ids'                 => [],
                'fully_updated_external_ids' => [],
            ],
            (new ProductImporter(
                $this->createDatabase(),
                $mapping_repository,
                $this->createFeatureMappingRepository(),
                $warehouse_importer,
                $stock_updater,
                $image_importer,
                $logging
            ))->import([$product], 1)
        );
        $this->assertCount(2, $logged_errors);
        $this->assertStringContainsString('77', $logged_errors[0]);
        $this->assertStringContainsString('10', $logged_errors[0]);
        $this->assertStringContainsString('77', $logged_errors[1]);
    }

    public function testAssignsOneLocalVariantFromMergedExternalFeatures()
    {
        $product = $this->createProduct();
        $color_variant_id = $this->addFeature($product, 10, 'Red');
        $shirt_color_variant_id = $this->addFeature($product, 11, 'red');
        $updated_data = [];
        $logged_errors = [];

        $product_ids = $this->importFullProduct(
            $product,
            ['10', '11'],
            ['10' => 57, '11' => 57],
            [
                $color_variant_id       => ['local_id' => 901],
                $shirt_color_variant_id => ['local_id' => 901],
            ],
            $updated_data,
            $logged_errors
        );

        $this->assertSame(['77' => 100], $product_ids['product_ids']);
        $this->assertSame(['77'], $product_ids['fully_updated_external_ids']);
        $this->assertSame([57 => 901], $updated_data['product_features']);
        $this->assertSame([], $logged_errors);
    }

    public function testAssignsResolvedManufacturerAsBrandFeature()
    {
        $product = $this->createProduct();
        $manufacturer = new ManufacturerDto();
        $manufacturer->id = 10;
        $manufacturer->name = 'ACME';
        $product->manufacturer = $manufacturer;
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(3))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, ['77']],
                [1, ManufacturerDto::ENTITY_TYPE, ['10']],
                [1, CategoryDto::ENTITY_TYPE, ['10']]
            )
            ->willReturnOnConsecutiveCalls([], ['10' => ['local_id' => 901]], ['10' => ['local_id' => 501]]);
        $mapping_repository->expects($this->once())->method('save');
        $feature_mapping_repository = $this->createFeatureMappingRepository();
        $feature_mapping_repository->expects($this->once())
            ->method('findByExternalIds')
            ->with(1, [ManufacturerDto::ENTITY_TYPE])
            ->willReturn([ManufacturerDto::ENTITY_TYPE => 57]);
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->method('import')->willReturn(12);
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->once())->method('update');
        $image_importer = $this->createImageImporter();
        $image_importer->method('findByObjectIds')->willReturn([]);
        $image_importer->method('import')->willReturn(new OperationResult(true));
        self::$update_product = static function (array $product_data) use (&$updated_data) {
            $updated_data = $product_data;

            return 100;
        };

        (new ProductImporter(
            $this->createDatabase(),
            $mapping_repository,
            $feature_mapping_repository,
            $warehouse_importer,
            $stock_updater,
            $image_importer
        ))->import([$product], 1);

        $this->assertSame([57 => 901], $updated_data['product_features']);
    }

    public function testSkipsConflictingMergedFeatureValues()
    {
        $product = $this->createProduct();
        $color_variant_id = $this->addFeature($product, 10, 'Red');
        $shirt_color_variant_id = $this->addFeature($product, 11, 'Blue');
        $updated_data = [];
        $logged_errors = [];

        $product_ids = $this->importFullProduct(
            $product,
            ['10', '11'],
            ['10' => 57, '11' => 57],
            [
                $color_variant_id       => ['local_id' => 901],
                $shirt_color_variant_id => ['local_id' => 902],
            ],
            $updated_data,
            $logged_errors
        );

        $this->assertSame(['77' => 100], $product_ids['product_ids']);
        $this->assertSame(['77'], $product_ids['fully_updated_external_ids']);
        $this->assertArrayNotHasKey('product_features', $updated_data);
        $this->assertCount(1, $logged_errors);
        $this->assertStringContainsString('77', $logged_errors[0]);
        $this->assertStringContainsString('57', $logged_errors[0]);
    }

    public function testLogsUnresolvedAndSilentlySkipsExplicitlySkippedFeatures()
    {
        $product = $this->createProduct();
        $skipped_variant_id = $this->addFeature($product, 10, 'Red');
        $unresolved_variant_id = $this->addFeature($product, 11, 'Blue');
        $updated_data = [];
        $logged_errors = [];

        $product_ids = $this->importFullProduct(
            $product,
            ['10', '11'],
            ['10' => 0],
            [
                $skipped_variant_id    => ['local_id' => 901],
                $unresolved_variant_id => ['local_id' => 902],
            ],
            $updated_data,
            $logged_errors
        );

        $this->assertSame(['77' => 100], $product_ids['product_ids']);
        $this->assertSame(['77'], $product_ids['fully_updated_external_ids']);
        $this->assertArrayNotHasKey('product_features', $updated_data);
        $this->assertCount(1, $logged_errors);
        $this->assertStringContainsString('77', $logged_errors[0]);
        $this->assertStringContainsString('11', $logged_errors[0]);
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
        $logging = $this->createMock(Logging::class);
        $logging->expects($this->once())
            ->method('error')
            ->with('synchro_import.products', $this->stringContains('77'));
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->never())->method('update');
        $image_importer = $this->createImageImporter();
        $image_importer->expects($this->never())->method('findByObjectIds');
        $image_importer->expects($this->never())->method('import');

        $product_ids = (new ProductImporter(
            $this->createDatabase(),
            $mapping_repository,
            $this->createFeatureMappingRepository(),
            $warehouse_importer,
            $stock_updater,
            $image_importer,
            $logging
        ))->import([$product], 1, true);

        $this->assertSame([
            'product_ids'                 => [],
            'fully_updated_external_ids' => [],
        ], $product_ids);
    }

    public function testLogsWarehouseResolutionFailure()
    {
        $product = $this->createProduct();
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(2))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, ['77']],
                [1, CategoryDto::ENTITY_TYPE, ['10']]
            )
            ->willReturnOnConsecutiveCalls([], ['10' => ['local_id' => 501]]);
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->expects($this->once())
            ->method('import')
            ->with($product->warehouses[0])
            ->willReturn(0);
        self::$update_product = static function () {
            throw new \RuntimeException('Product updater must not be called');
        };
        $logged_errors = [];
        $logging = $this->createCapturingLogging($logged_errors);
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->never())->method('update');
        $image_importer = $this->createImageImporter();
        $image_importer->expects($this->once())
            ->method('findByObjectIds')
            ->with('product', [])
            ->willReturn([]);
        $image_importer->expects($this->never())->method('import');

        $product_ids = (new ProductImporter(
            $this->createDatabase(),
            $mapping_repository,
            $this->createFeatureMappingRepository(),
            $warehouse_importer,
            $stock_updater,
            $image_importer,
            $logging
        ))->import([$product], 1);

        $this->assertSame([
            'product_ids'                 => [],
            'fully_updated_external_ids' => [],
        ], $product_ids);
        $this->assertStringContainsString('etm3', $logged_errors[0]);
        $this->assertCount(2, $logged_errors);
        $this->assertStringContainsString('77', $logged_errors[1]);
        $this->assertStringContainsString('warehouse', $logged_errors[1]);
    }

    public function testLogsProductImageFailureReason()
    {
        $product = $this->createProduct();
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(2))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, ['77']],
                [1, CategoryDto::ENTITY_TYPE, ['10']]
            )
            ->willReturnOnConsecutiveCalls([], ['10' => ['local_id' => 501]]);
        $mapping_repository->expects($this->once())->method('save');
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->expects($this->once())->method('import')->willReturn(12);
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->once())->method('update')->with([
            100 => ['amount' => 4, 'warehouses' => [12 => 3]],
        ]);
        $image_result = new OperationResult(false);
        $image_result->addError('download.main.jpg', 'Image download failed');
        $image_importer = $this->createImageImporter();
        $image_importer->expects($this->once())->method('findByObjectIds')->willReturn([]);
        $image_importer->expects($this->once())->method('import')->willReturn($image_result);
        self::$update_product = static function () {
            return 100;
        };
        $logged_warnings = [];
        $logging = $this->createMock(Logging::class);
        $logging->expects($this->once())
            ->method('warning')
            ->with('synchro_import.products', $this->isType('string'))
            ->willReturnCallback(static function ($source, $message) use (&$logged_warnings) {
                $logged_warnings[] = $message;
            });

        $this->assertSame(
            [
                'product_ids'                 => ['77' => 100],
                'fully_updated_external_ids' => [],
            ],
            (new ProductImporter(
                $this->createDatabase(),
                $mapping_repository,
                $this->createFeatureMappingRepository(),
                $warehouse_importer,
                $stock_updater,
                $image_importer,
                $logging
            ))->import([$product], 1)
        );
        $this->assertStringContainsString('77', $logged_warnings[0]);
        $this->assertStringContainsString('Image download failed', $logged_warnings[0]);
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
        $product->images = [
            ['url' => 'https://example.com/main.jpg', 'hash' => 'main'],
            ['url' => 'https://example.com/additional.jpg', 'hash' => 'additional'],
        ];
        $warehouse = new WarehouseDto();
        $warehouse->id = 'etm3';
        $warehouse->amount = 3;
        $product->warehouses[] = $warehouse;
        $this->addCategory($product, 10);

        return $product;
    }

    /**
     * Adds a category reference to a product.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto $product     Imported product
     * @param int                                    $category_id External category identifier
     *
     * @return void
     */
    private function addCategory(ProductDto $product, $category_id)
    {
        $category = new CategoryDto();
        $category->id = $category_id;
        $product->categories[] = $category;
    }

    /**
     * Adds one compact feature variant assignment to a product.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto $product    Imported product
     * @param int                                   $feature_id External feature identifier
     * @param string                                $value      Selected feature value
     *
     * @return string External variant identifier
     */
    private function addFeature(ProductDto $product, $feature_id, $value)
    {
        $variant_id = $feature_id . '#' . md5($value);
        $product->feature_variant_ids[(string) $feature_id][] = $variant_id;

        return $variant_id;
    }

    /**
     * Imports a product with prepared feature mappings and captures updated data and errors.
     *
     * @param \Tygh\Addons\Synchro\Dto\ProductDto $product          Imported product
     * @param array<string>                         $external_feature_ids External feature identifiers
     * @param array<string, int>                    $feature_mappings     External-to-local feature mappings
     * @param array<string, array{local_id: int}>   $variant_mappings     External-to-local variant mappings
     * @param array                                 $updated_data         Captured product data
     * @param array<string>                         $logged_errors        Captured errors
     *
     * @return array{product_ids: array<string, int>, fully_updated_external_ids: array<string>}
     */
    private function importFullProduct(
        ProductDto $product,
        array $external_feature_ids,
        array $feature_mappings,
        array $variant_mappings,
        array &$updated_data,
        array &$logged_errors
    ) {
        $mapping_repository = $this->createMappingRepository();
        $mapping_repository->expects($this->exactly(3))
            ->method('findByExternalIds')
            ->withConsecutive(
                [1, ProductDto::ENTITY_TYPE, [$product->getEntityId()]],
                [1, ProductFeatureVariantDto::ENTITY_TYPE, array_keys($variant_mappings)],
                [1, CategoryDto::ENTITY_TYPE, ['10']]
            )
            ->willReturnOnConsecutiveCalls([], $variant_mappings, ['10' => ['local_id' => 501]]);
        $mapping_repository->expects($this->once())->method('save');
        $feature_mapping_repository = $this->createFeatureMappingRepository();
        $feature_mapping_repository->expects($this->once())
            ->method('findByExternalIds')
            ->with(1, $external_feature_ids)
            ->willReturn($feature_mappings);
        $warehouse_importer = $this->createWarehouseImporter();
        $warehouse_importer->method('import')->willReturn(12);
        $stock_updater = $this->createProductStockUpdater();
        $stock_updater->expects($this->once())->method('update');
        $image_importer = $this->createImageImporter();
        $image_importer->method('findByObjectIds')->willReturn([]);
        $image_importer->method('import')->willReturn(new OperationResult(true));
        self::$update_product = static function (array $product_data) use (&$updated_data) {
            $updated_data = $product_data;

            return 100;
        };
        $logging = $this->createCapturingLogging($logged_errors);

        return (new ProductImporter(
            $this->createDatabase(),
            $mapping_repository,
            $feature_mapping_repository,
            $warehouse_importer,
            $stock_updater,
            $image_importer,
            $logging
        ))->import([$product], 1);
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
            ->setMethods(['findByExternalIds', 'save', 'markFullyUpdated', 'markFullyUpdatedMany', 'markActualized'])
            ->getMock();
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository
     */
    private function createFeatureMappingRepository()
    {
        return $this->getMockBuilder(ProductFeatureMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findByExternalIds'])
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
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Importers\ImageImporter
     */
    private function createImageImporter()
    {
        return $this->getMockBuilder(ImageImporter::class)
            ->disableOriginalConstructor()
            ->setMethods(['findByObjectIds', 'import'])
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
     * @param array<string> $errors Captured error messages
     *
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Logging
     */
    private function createCapturingLogging(array &$errors)
    {
        $logging = $this->createMock(Logging::class);
        $capture = static function ($source, $message) use (&$errors) {
            $errors[] = $message;
        };
        $logging->method('error')->willReturnCallback($capture);
        $logging->method('warning')->willReturnCallback($capture);

        return $logging;
    }
}
}
