<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Commands\ImportDataCommandHandler;
use Tygh\Addons\Synchro\Convertors\ConvertorInterface;
use Tygh\Addons\Synchro\Convertors\ManufacturerConvertor;
use Tygh\Addons\Synchro\Convertors\ProductConvertor;
use Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor;
use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\ImportProcessManager;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
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
        $convertor = new ProductConvertor(
            $repository,
            7,
            new ProductFeatureConvertor($repository, 7),
            $cron_manager,
            $process_manager
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

    public function __construct()
    {
    }

    public function batchSave($import_id, $company_id, array $entities)
    {
        $this->batch_save_calls++;

        return count($entities);
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
}
