<?php

namespace Tygh\Addons\Synchro\Convertors;

use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\CronManager;

/**
 * Converts category data received from the external API.
 */
class CategoryConvertor implements ConvertorInterface
{
    /**
     * @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository
     */
    private $repository;

    /**
     * @var int
     */
    private $company_id;

    /**
     * @var \Tygh\Addons\Synchro\CronManager
     */
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

        $categories = [];
        /** @var array $source_categories */
        $source_categories = $data['data'];

        foreach ($source_categories as $source_category) {
            $this->convertCategory($source_category, null, $categories, $cron_script_id);
        }

        $this->cron_manager->ensureTaskCanContinue($cron_script_id);
        $categories = array_values($categories);
        $this->repository->batchSaveCategories($import_id, $this->company_id, $categories);

        return $categories;
    }

    /**
     * Converts a category and its children into a flat list.
     *
     * @param array<string, array|int|string>                     $source_category API category data
     * @param \Tygh\Addons\Synchro\Dto\CategoryDto|null           $parent_category Parent category DTO
     * @param array<string, \Tygh\Addons\Synchro\Dto\CategoryDto> $categories      Converted categories
     * @param int                                                 $cron_script_id  Cron script identifier
     *
     * @return void
     *
     * @psalm-suppress PossiblyInvalidArgument
     * @psalm-suppress PossiblyInvalidIterator
     * @psalm-suppress PossiblyInvalidPropertyAssignmentValue
     *
     * @throws \Tygh\Addons\Synchro\Exceptions\TaskInterruptedException When task interruption is requested.
     */
    private function convertCategory(
        array $source_category,
        CategoryDto $parent_category = null,
        array &$categories,
        $cron_script_id = 0
    ) {
        $this->cron_manager->ensureTaskCanContinue($cron_script_id);

        $category = new CategoryDto();
        $category->id = $source_category['id'];
        $category->parent_id = $parent_category ? $parent_category->id : null;
        $category->status = $source_category['status'];
        $category->name = $source_category['title'];
        $category->full_name = $parent_category
            ? sprintf('%s/%s', $parent_category->full_name, $category->name)
            : $category->name;
        $category->seo_name = $source_category['url'];
        $category->description = $source_category['description'];
        $category->product_count = $source_category['products'];
        $category->images = $source_category['images'];

        $categories[$category->getEntityId()] = $category;
        /** @var array $children */
        $children = $source_category['children'];

        foreach ($children as $child) {
            $this->convertCategory($child, $category, $categories, $cron_script_id);
        }
    }
}
