<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\ManufacturerDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Converts manufacturer data received from the external API.
 */
class ManufacturerConvertor implements ConvertorInterface
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /** @var int */
    private $company_id;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository Import entity repository
     * @param int                                                    $company_id Company identifier
     */
    public function __construct(ImportEntityRepository $repository, $company_id)
    {
        $this->repository = $repository;
        $this->company_id = $company_id;
    }

    /**
     * @inheritDoc
     */
    public function convert(array $data, $import_id = 0)
    {
        if (!$data) {
            return [];
        }

        $manufacturers = [];
        /** @var array $source_manufacturers */
        $source_manufacturers = $data['data'];

        foreach ($source_manufacturers as $source_manufacturer) {
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

        $this->repository->batchSave($import_id, $this->company_id, $manufacturers);

        return $manufacturers;
    }
}
