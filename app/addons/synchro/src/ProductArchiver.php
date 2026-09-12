<?php

namespace Tygh\Addons\Synchro;

use RuntimeException;
use Tygh\Addons\Synchro\Api\ApiClient;
use Tygh\Addons\Synchro\Dto\ProductDto;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Database\Connection;
use Tygh\Enum\ObjectStatuses;

/**
 * Moves source-missing mapped products to the archive category.
 */
class ProductArchiver
{
    const ARCHIVE_CATEGORY_NAME = 'Архивные';

    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Api\ApiClient */
    private $api_client;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\CronManager */
    private $cron_manager;

    /**
     * @param \Tygh\Database\Connection                                 $database           Database connection
     * @param \Tygh\Addons\Synchro\Api\ApiClient                        $api_client         Source API client
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mappings
     * @param \Tygh\Addons\Synchro\CronManager                          $cron_manager       Cron task manager
     */
    public function __construct(
        Connection $database,
        ApiClient $api_client,
        ImportEntityMapRepository $mapping_repository,
        CronManager $cron_manager
    ) {
        $this->database = $database;
        $this->api_client = $api_client;
        $this->mapping_repository = $mapping_repository;
        $this->cron_manager = $cron_manager;
    }

    /**
     * Archives mapped products confirmed missing by the source API.
     *
     * @param int $company_id     Company identifier
     * @param int $cron_script_id Cron task identifier
     *
     * @return int Number of archived products
     *
     * @throws \RuntimeException When an archived product or its mapping cannot be saved.
     */
    public function archive($company_id, $cron_script_id)
    {
        $mappings = $this->mapping_repository->findPendingArchiving($company_id, ProductDto::ENTITY_TYPE);
        if (!$mappings) {
            return 0;
        }

        $archive_category_id = $this->getArchiveCategoryId($company_id);
        $archived_count = 0;
        foreach ($mappings as $external_id => $mapping) {
            $this->cron_manager->ensureTaskCanContinue($cron_script_id);
            $product_data = $this->api_client->getProductData($external_id);
            if (!$this->isProductMissing($product_data)) {
                continue;
            }

            $product_id = (int) fn_update_product([
                'category_ids' => [$archive_category_id],
            ], (int) $mapping['local_id']);
            if (!$product_id) {
                throw new RuntimeException(__('synchro.exception.product_archiving_failed'));
            }
            if (
                !$this->mapping_repository->clearArchivingMark(
                    $company_id,
                    ProductDto::ENTITY_TYPE,
                    $external_id
                )
            ) {
                throw new RuntimeException(__('synchro.exception.product_archiving_mark_not_cleared'));
            }
            $archived_count++;
        }

        return $archived_count;
    }

    /**
     * Returns the root archive category, creating it disabled when missing.
     *
     * @param int $company_id Company identifier
     *
     * @return int
     *
     * @throws \RuntimeException When the category cannot be created.
     */
    private function getArchiveCategoryId($company_id)
    {
        $category_id = (int) $this->database->getField(
            'SELECT categories.category_id FROM ?:categories AS categories'
            . ' INNER JOIN ?:category_descriptions AS descriptions'
            . ' ON descriptions.category_id = categories.category_id AND descriptions.lang_code = ?s'
            . ' WHERE categories.company_id = ?i AND categories.parent_id = ?i AND descriptions.category = ?s'
            . ' LIMIT 1',
            CART_LANGUAGE,
            $company_id,
            0,
            self::ARCHIVE_CATEGORY_NAME
        );
        if ($category_id) {
            return $category_id;
        }

        $category_id = (int) fn_update_category([
            'company_id' => $company_id,
            'parent_id'  => 0,
            'category'   => self::ARCHIVE_CATEGORY_NAME,
            'status'     => ObjectStatuses::DISABLED,
        ], 0);
        if (!$category_id) {
            throw new RuntimeException(__('synchro.exception.archive_category_not_created'));
        }

        return $category_id;
    }

    /**
     * @param array<array-key, array|bool|float|int|string|null> $product_data Product API response
     *
     * @return bool
     */
    private function isProductMissing(array $product_data)
    {
        return isset($product_data['data'][0]['error'])
            && $product_data['data'][0]['error'] === 'Товар не найден';
    }
}
