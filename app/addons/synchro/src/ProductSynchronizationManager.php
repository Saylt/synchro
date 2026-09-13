<?php

namespace Tygh\Addons\Synchro;

use RuntimeException;
use Tygh\Addons\Synchro\Api\ApiClient;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Dto\ProductDtoFactory;
use Tygh\Addons\Synchro\Importers\ProductImporter;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Throwable;

class ProductSynchronizationManager
{
    const MODE_FULL = 'full';
    const MODE_ACTUALIZE = 'actualize';
    const LOG_SOURCE = 'synchro_product_synchronization';

    /** @var \Tygh\Addons\Synchro\Api\ApiClient */
    private $api_client;

    /** @var \Tygh\Addons\Synchro\Dto\ProductDtoFactory */
    private $product_dto_factory;

    /** @var \Tygh\Addons\Synchro\Importers\ProductImporter */
    private $product_importer;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\Logging */
    private $logging;

    /**
     * Initializes the direct product synchronization manager.
     *
     * @param \Tygh\Addons\Synchro\Api\ApiClient                        $api_client          Source API client
     * @param \Tygh\Addons\Synchro\Dto\ProductDtoFactory                $product_dto_factory Product data converter
     * @param \Tygh\Addons\Synchro\Importers\ProductImporter            $product_importer    Product importer
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository  Entity mappings
     * @param \Tygh\Addons\Synchro\Logging                              $logging             Synchro journal
     */
    public function __construct(
        ApiClient $api_client,
        ProductDtoFactory $product_dto_factory,
        ProductImporter $product_importer,
        ImportEntityMapRepository $mapping_repository,
        Logging $logging
    ) {
        $this->api_client = $api_client;
        $this->product_dto_factory = $product_dto_factory;
        $this->product_importer = $product_importer;
        $this->mapping_repository = $mapping_repository;
        $this->logging = $logging;
    }

    /**
     * Synchronizes selected mapped products without creating staging data.
     *
     * @param array<int> $product_ids Local product identifiers
     * @param int        $company_id  Company identifier
     * @param string     $mode        Synchronization mode
     *
     * @return array{synced: int, unmapped: int, failed: int}
     *
     * @throws \RuntimeException When the synchronization mode is unknown.
     */
    public function synchronize(array $product_ids, $company_id, $mode)
    {
        if (!in_array($mode, [self::MODE_FULL, self::MODE_ACTUALIZE], true)) {
            throw new RuntimeException(__('synchro.exception.unknown_product_synchronization_mode'));
        }
        $result = ['synced' => 0, 'unmapped' => 0, 'failed' => 0];
        $mappings = $this->mapping_repository->findByLocalIds($company_id, ProductDto::ENTITY_TYPE, $product_ids);
        foreach ($product_ids as $product_id) {
            if (!isset($mappings[$product_id])) {
                $result['unmapped']++;
                $this->logging->warning(self::LOG_SOURCE, __('synchro.product_synchronization_product_unmapped', [
                    '[product_id]' => $product_id,
                ]));
                continue;
            }
            try {
                $mapping = $mappings[$product_id];
                $data = $this->api_client->getProductData($mapping['external_id']);
                if (empty($data['data'][0]) || !is_array($data['data'][0])) {
                    throw new RuntimeException(__('synchro.product_synchronization_invalid_response'));
                }
                $source_product = $data['data'][0];
                if (!empty($source_product['error'])) {
                    throw new RuntimeException((string) $source_product['error']);
                }
                $product = $this->product_dto_factory->create($source_product);
                $import_result = $this->product_importer->import(
                    [$product],
                    $company_id,
                    $mode === self::MODE_ACTUALIZE
                );
                if (empty($import_result['product_ids'][$mapping['external_id']])) {
                    throw new RuntimeException(__('synchro.product_synchronization_product_failed'));
                }
                $result['synced']++;
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->logging->error(self::LOG_SOURCE, $exception->getMessage(), [
                    'product_id'  => $product_id,
                    'external_id' => $mapping['external_id'],
                ]);
            }
        }

        return $result;
    }
}
