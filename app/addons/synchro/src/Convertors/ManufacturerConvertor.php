<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\CronManager;

/**
 * Converts manufacturer data received from the external API.
 */
class ManufacturerConvertor implements ConvertorInterface
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var int */
    private $company_id;

    /** @var \Tygh\Addons\Synchro\CronManager */
    private $cron_manager;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository   Import entity repository
     * @param int                                                    $company_id   Company identifier
     * @param \Tygh\Addons\Synchro\CronManager                       $cron_manager Cron task manager
     */
    public function __construct(ImportEntityRepository $repository, $company_id, CronManager $cron_manager)
    {
        $this->repository = $repository;
        $this->company_id = $company_id;
        $this->cron_manager = $cron_manager;
    }

    /**
     * @inheritDoc
     */
    public function convert(array $data, $import_id = 0, $cron_script_id = 0, $import_process_id = 0)
    {
        if (!$data) {
            return [];
        }

        $manufacturers = [];
        /** @var array $source_manufacturers */
        $source_manufacturers = $data['data'];

        foreach ($source_manufacturers as $source_manufacturer) {
            $this->cron_manager->ensureTaskCanContinue($cron_script_id);

            $manufacturer = new ManufacturerDto();
            $manufacturer->id = $source_manufacturer['id'];
            $manufacturer->status = $source_manufacturer['status'];
            $manufacturer->name = $source_manufacturer['title'];
            $manufacturer->seo_name = $source_manufacturer['url'];
            $manufacturer->description = $source_manufacturer['description'];
            $manufacturer->product_count = $source_manufacturer['products'];
            $manufacturer->images = $source_manufacturer['images'];
            $manufacturers[] = $manufacturer;
        }

        $this->cron_manager->ensureTaskCanContinue($cron_script_id);
        $this->repository->batchSave($import_id, $this->company_id, $manufacturers);

        return $manufacturers;
    }
}
