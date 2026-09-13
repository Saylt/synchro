<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\ProductDtoFactory;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\CronManager;
use Tygh\Addons\Synchro\ImportProcessManager;

/**
 * Converts product data received from the external API.
 */
class ProductConvertor implements ConvertorInterface
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var int */
    private $company_id;

    /** @var \Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor */
    private $product_feature_convertor;

    /** @var \Tygh\Addons\Synchro\Dto\ProductDtoFactory */
    private $product_dto_factory;

    /** @var \Tygh\Addons\Synchro\CronManager */
    private $cron_manager;

    /** @var \Tygh\Addons\Synchro\ImportProcessManager */
    private $import_process_manager;

    /**
     * Initializes the product convertor.
     *
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository  $repository                Import entity repository
     * @param int                                                     $company_id                Company identifier
     * @param \Tygh\Addons\Synchro\Convertors\ProductFeatureConvertor $product_feature_convertor Product feature convertor
     * @param \Tygh\Addons\Synchro\Dto\ProductDtoFactory              $product_dto_factory       Product DTO factory
     * @param \Tygh\Addons\Synchro\CronManager                        $cron_manager              Cron task manager
     * @param \Tygh\Addons\Synchro\ImportProcessManager               $import_process_manager    Import process manager
     */
    public function __construct(
        ImportEntityRepository $repository,
        $company_id,
        ProductFeatureConvertor $product_feature_convertor,
        ProductDtoFactory $product_dto_factory,
        CronManager $cron_manager,
        ImportProcessManager $import_process_manager
    ) {
        $this->repository = $repository;
        $this->company_id = $company_id;
        $this->product_feature_convertor = $product_feature_convertor;
        $this->product_dto_factory = $product_dto_factory;
        $this->cron_manager = $cron_manager;
        $this->import_process_manager = $import_process_manager;
    }

    /**
     * Converts one source product batch into staging DTOs.
     *
     * @param array<array-key, array|bool|float|int|string|null> $data              External API response
     * @param int                                                $import_id         Import identifier
     * @param int                                                $cron_script_id    Cron task identifier
     * @param int                                                $import_process_id Import process identifier
     *
     * @return array<\Tygh\Addons\Synchro\Dto\ProductDto>
     */
    public function convert(array $data, $import_id = 0, $cron_script_id = 0, $import_process_id = 0)
    {
        if (!$data) {
            return [];
        }

        $products = [];
        /** @var array $source_products */
        $source_products = $data['data'];

        foreach ($source_products as $source_product) {
            $this->ensureImportCanContinue($cron_script_id, $import_process_id);

            $product = $this->product_dto_factory->create($source_product);
            $this->product_feature_convertor->convert($source_product['properties'], $import_id);

            $products[] = $product;
        }

        $this->ensureImportCanContinue($cron_script_id, $import_process_id);
        $this->product_feature_convertor->save($import_id);
        $this->repository->batchSave($import_id, $this->company_id, $products);

        return $products;
    }

    /**
     * Checks the child process state or falls back to the cron task state.
     *
     * @param int $cron_script_id    Cron script identifier
     * @param int $import_process_id Import process identifier
     *
     * @return void
     *
     * @throws \Tygh\Addons\Synchro\Exceptions\TaskInterruptedException When interruption is requested.
     */
    private function ensureImportCanContinue($cron_script_id, $import_process_id)
    {
        if ($import_process_id) {
            $this->import_process_manager->ensureProcessCanContinue($import_process_id);

            return;
        }

        $this->cron_manager->ensureTaskCanContinue($cron_script_id);
    }
}
