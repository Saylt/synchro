<?php

namespace Tygh\Addons\Synchro\Convertors {
    if (!function_exists(__NAMESPACE__ . '\\__')) {
        function __($language_variable, array $params = [])
        {
            return $language_variable;
        }
    }
}

namespace Tygh\Addons\Synchro\Tests\Unit {

defined('SECONDS_IN_DAY') or define('SECONDS_IN_DAY', 86400);

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Commands\ImportDataCommandHandler;
use Tygh\Addons\Synchro\Convertors\ConvertorInterface;
use Tygh\Addons\Synchro\Convertors\ManufacturerConvertor;
use Tygh\Addons\Synchro\Convertors\ProductConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor;
use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\Dto\ProductDtoFactory;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\ImportProcessManager;
use Tygh\Addons\Synchro\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository;
use Tygh\Tests\Unit\ATestCase;

class ImportInterruptionTest extends ATestCase
{
    public function testImportCommandCarriesCronScriptId()
    {
        $command = ImportDataCommand::create(ImportDataCommand::ENTITY_PRODUCTS, [], 25, 15);

        $this->assertTrue(property_exists($command, 'cron_script_id'));
        $this->assertSame(15, $command->cron_script_id);
    }

    public function testImportCommandCarriesImportProcessId()
    {
        $command = ImportDataCommand::create(ImportDataCommand::ENTITY_PRODUCTS, [], 25, 15, 55);

        $this->assertTrue(property_exists($command, 'import_process_id'));
        $this->assertSame(55, $command->import_process_id);
    }

    public function testCommandHandlerPassesCronScriptIdToConvertor()
    {
        $convertor = new CapturingConvertor();
        $handler = new ImportDataCommandHandler([
            ImportDataCommand::ENTITY_PRODUCTS => $convertor,
        ]);
        $command = ImportDataCommand::create(ImportDataCommand::ENTITY_PRODUCTS, [], 25, 15);

        $handler->handle($command);

        $this->assertSame(15, $convertor->cron_script_id);
    }

    public function testCommandHandlerPassesImportProcessIdToConvertor()
    {
        $convertor = new CapturingConvertor();
        $handler = new ImportDataCommandHandler([
            ImportDataCommand::ENTITY_PRODUCTS => $convertor,
        ]);
        $command = ImportDataCommand::create(ImportDataCommand::ENTITY_PRODUCTS, [], 25, 15, 55);

        $handler->handle($command);

        $this->assertSame(55, $convertor->import_process_id);
    }

    public function testManufacturerBatchIsNotSavedAfterInterruptionBetweenEntities()
    {
        $repository = new CapturingImportEntityRepository();
        $cron_manager = new InterruptAfterFirstEntityCronManager();
        $convertor = new ManufacturerConvertor($repository, 7, $cron_manager);

        try {
            $convertor->convert([
                'data' => [
                    $this->getManufacturerData(1, 'First'),
                    $this->getManufacturerData(2, 'Second'),
                ],
            ], 25, 15);
            $this->fail('The convertor must stop before processing the second manufacturer');
        } catch (TaskInterruptedException $exception) {
            $this->assertSame(2, $cron_manager->checks);
            $this->assertSame(0, $repository->batch_save_calls);
        }
    }

    public function testProductUsesChildProcessInterruptionState()
    {
        $repository = new CapturingImportEntityRepository();
        $cron_manager = new InterruptAfterFirstEntityCronManager();
        $process_manager = new InterruptAfterFirstImportProcessManager();
        $product_feature_convertor = new ProductFeatureConvertor($this->productFeatureSnapshotRepository());
        $convertor = new ProductConvertor(
            $repository,
            7,
            $product_feature_convertor,
            new ProductDtoFactory($product_feature_convertor),
            $cron_manager,
            $process_manager,
            $this->getMockBuilder(Logging::class)->disableOriginalConstructor()->getMock()
        );

        try {
            $convertor->convert([
                'data' => [
                    $this->getProductData(1, 'First'),
                    $this->getProductData(2, 'Second'),
                ],
            ], 25, 15, 55);
            $this->fail('The convertor must stop before processing the second product');
        } catch (TaskInterruptedException $exception) {
            $this->assertSame(2, $process_manager->checks);
            $this->assertSame(0, $cron_manager->checks);
            $this->assertSame(0, $repository->batch_save_calls);
        }
    }

    public function testSkipsSourceErrorProductAndLogsWarning()
    {
        $repository = new CapturingImportEntityRepository();
        $product_feature_convertor = new ProductFeatureConvertor($this->productFeatureSnapshotRepository());
        $logging = $this->getMockBuilder(Logging::class)
            ->disableOriginalConstructor()
            ->setMethods(['warning'])
            ->getMock();
        $logging->expects($this->once())
            ->method('warning')
            ->with('synchro_import.products', $this->anything());
        $convertor = new ProductConvertor(
            $repository,
            7,
            $product_feature_convertor,
            new ProductDtoFactory($product_feature_convertor),
            new NonInterruptingCronManager(),
            new InterruptAfterFirstImportProcessManager(),
            $logging
        );

        $products = $convertor->convert([
            'data' => [
                $this->getProductData(1, 'First'),
                ['id' => 2, 'error' => 'Товар не найден'],
            ],
        ], 25, 15);

        $this->assertCount(1, $products);
        $this->assertSame(1, $products[0]->id);
        $this->assertCount(1, $repository->last_batch);
    }

    /**
     * Creates a no-op snapshot repository for conversion tests that do not reach persistence.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository
     */
    private function productFeatureSnapshotRepository()
    {
        return $this->getMockBuilder(ProductFeatureSnapshotRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['savePortion'])
            ->getMock();
    }

    /**
     * @param int    $id   Manufacturer identifier
     * @param string $name Manufacturer name
     *
     * @return array<string, array<int, string>|int|string>
     */
    private function getManufacturerData($id, $name)
    {
        return [
            'id'          => $id,
            'status'      => 1,
            'title'       => $name,
            'url'         => strtolower($name),
            'description' => '',
            'products'    => 0,
            'images'      => [],
        ];
    }

    /**
     * @param int    $id   Product identifier
     * @param string $name Product name
     *
     * @return array<string, array|int|string>
     */
    private function getProductData($id, $name)
    {
        return [
            'id'             => $id,
            'error'          => '',
            'sku'            => 'SKU-' . $id,
            'title'          => $name,
            'description'    => '',
            'url'            => strtolower($name),
            'etmid'          => '',
            'rlid'           => '',
            'images'         => [],
            'purchase_price' => 0,
            'user_price'     => 0,
            'categories'     => [],
            'manufacturer'   => ['id' => 1, 'title' => 'Brand'],
            'properties'     => [],
            'rests'          => [],
        ];
    }
}

class CapturingConvertor implements ConvertorInterface
{
    /** @var int */
    public $cron_script_id = 0;

    /** @var int */
    public $import_process_id = 0;

    public function convert(array $data, $import_id = 0, $cron_script_id = 0, $import_process_id = 0)
    {
        $this->cron_script_id = $cron_script_id;
        $this->import_process_id = $import_process_id;

        return [];
    }
}

class CapturingImportEntityRepository extends ImportEntityRepository
{
    /** @var int */
    public $batch_save_calls = 0;

    /** @var array */
    public $last_batch = [];

    public function __construct()
    {
    }

    public function batchSave($import_id, $company_id, array $entities)
    {
        $this->batch_save_calls++;
        $this->last_batch = $entities;

        return count($entities);
    }
}

class NonInterruptingCronManager extends CronManager
{
    public function __construct()
    {
    }

    public function ensureTaskCanContinue($script_id)
    {
    }
}

class InterruptAfterFirstEntityCronManager extends CronManager
{
    /** @var int */
    public $checks = 0;

    public function __construct()
    {
    }

    public function ensureTaskCanContinue($script_id)
    {
        $this->checks++;
        if ($this->checks > 1) {
            throw new TaskInterruptedException('Cron task interruption requested');
        }
    }
}

class InterruptAfterFirstImportProcessManager extends ImportProcessManager
{
    /** @var int */
    public $checks = 0;

    public function __construct()
    {
    }

    public function ensureProcessCanContinue($import_id)
    {
        $this->checks++;
        if ($this->checks > 1) {
            throw new TaskInterruptedException('Import process interruption requested');
        }
    }

    public function getProcess($import_id)
    {
        return ['collect_product_features' => ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES];
    }
}
}
