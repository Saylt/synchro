<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Common\OperationResult;
use Tygh\Database\Connection;
use Tygh\Enum\ObjectStatuses;

/**
 * Imports a complete category snapshot into CS-Cart.
 */
class CategoryImporter
{
    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\Importers\ImageImporter */
    private $image_importer;

    /**
     * Initializes the category importer.
     *
     * @param \Tygh\Database\Connection                                 $database           Database connection
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     * @param \Tygh\Addons\Synchro\Importers\ImageImporter              $image_importer     Image importer
     */
    public function __construct(
        Connection $database,
        ImportEntityMapRepository $mapping_repository,
        ImageImporter $image_importer
    ) {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
        $this->image_importer = $image_importer;
    }

    /**
     * Imports a complete category snapshot into CS-Cart.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\CategoryDto> $categories Imported categories
     * @param int                                         $company_id Company identifier
     *
     * @return array<string, int> Local category identifiers indexed by external identifiers
     */
    public function import(array $categories, $company_id)
    {
        $categories_by_id = $this->indexCategories($categories);
        $ordered_categories = $this->orderCategories($categories_by_id);
        $mappings = $this->mapping_repository->findAllByEntityType($company_id, CategoryDto::ENTITY_TYPE);
        $existing_category_ids = $this->findExistingCategoryIds($mappings);
        $current_images = $this->image_importer->findByObjectIds(
            'category',
            array_keys($existing_category_ids)
        );
        $imported_category_ids = $this->importCategories(
            $ordered_categories,
            $company_id,
            $mappings,
            $existing_category_ids,
            $current_images
        );

        $this->disableMissingCategories($categories_by_id, $mappings, $existing_category_ids);

        return $imported_category_ids;
    }

    /**
     * Indexes categories by their external identifiers and skips duplicates.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\CategoryDto> $categories Imported categories
     *
     * @return array<string, \Tygh\Addons\Synchro\Dto\CategoryDto>
     */
    private function indexCategories(array $categories)
    {
        $categories_by_id = [];

        foreach ($categories as $category) {
            $external_id = $category->getEntityId();
            if (isset($categories_by_id[$external_id])) {
                $this->logError(__('synchro.category_import_error.duplicate', [
                    '[external_id]' => $external_id,
                ]));
                continue;
            }

            $categories_by_id[$external_id] = $category;
        }

        return $categories_by_id;
    }

    /**
     * Orders valid categories from roots to leaves.
     *
     * @param array<string, \Tygh\Addons\Synchro\Dto\CategoryDto> $categories_by_id Categories indexed by external ID
     *
     * @return array<\Tygh\Addons\Synchro\Dto\CategoryDto>
     */
    private function orderCategories(array $categories_by_id)
    {
        $ordered_categories = [];
        $resolved_categories = [];
        $skipped_categories = [];
        $remaining_categories = $categories_by_id;

        while ($remaining_categories) {
            $remaining_count = count($remaining_categories);

            foreach ($remaining_categories as $external_id => $category) {
                $parent_external_id = $this->getParentExternalId($category);

                if ($parent_external_id !== '' && !isset($categories_by_id[$parent_external_id])) {
                    $this->logError(__('synchro.category_import_error.parent_not_found', [
                        '[external_id]'        => $external_id,
                        '[parent_external_id]' => $parent_external_id,
                    ]));
                    $skipped_categories[$external_id] = true;
                    unset($remaining_categories[$external_id]);
                    continue;
                }

                if ($parent_external_id !== '' && isset($skipped_categories[$parent_external_id])) {
                    $this->logError(__('synchro.category_import_error.parent_not_imported', [
                        '[external_id]'        => $external_id,
                        '[parent_external_id]' => $parent_external_id,
                    ]));
                    $skipped_categories[$external_id] = true;
                    unset($remaining_categories[$external_id]);
                    continue;
                }

                if ($parent_external_id !== '' && !isset($resolved_categories[$parent_external_id])) {
                    continue;
                }

                $ordered_categories[] = $category;
                $resolved_categories[$external_id] = true;
                unset($remaining_categories[$external_id]);
            }

            if ($remaining_count !== count($remaining_categories)) {
                continue;
            }

            foreach (array_keys($remaining_categories) as $external_id) {
                $this->logError(__('synchro.category_import_error.cycle', [
                    '[external_id]' => $external_id,
                ]));
            }
            break;
        }

        return $ordered_categories;
    }

    /**
     * Finds mapped categories that still exist in CS-Cart.
     *
     * @param array<string, array<string, int|string>> $mappings Category mappings
     *
     * @return array<int, true> Existing category identifiers
     */
    private function findExistingCategoryIds(array $mappings)
    {
        $mapped_category_ids = [];

        foreach ($mappings as $mapping) {
            if ((int) $mapping['local_id'] > 0) {
                $mapped_category_ids[] = (int) $mapping['local_id'];
            }
        }

        if (!$mapped_category_ids) {
            return [];
        }

        /** @var array<int> $category_ids */
        $category_ids = $this->database->getColumn(
            'SELECT category_id FROM ?:categories WHERE category_id IN (?n)',
            array_values(array_unique($mapped_category_ids))
        );

        return array_fill_keys($category_ids, true);
    }

    /**
     * Applies ordered categories and their images to CS-Cart.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\CategoryDto>                                                     $categories            Ordered categories
     * @param int                                                                                             $company_id            Company identifier
     * @param array<string, array<string, int|string>>                                                        $mappings              Category mappings
     * @param array<int, true>                                                                                $existing_category_ids Existing category identifiers
     * @param array<int, array<string, array{pair_id: int, type: string, position: int, image_path: string}>> $current_images        Current category images
     *
     * @return array<string, int>
     */
    private function importCategories(
        array $categories,
        $company_id,
        array $mappings,
        array $existing_category_ids,
        array $current_images
    ) {
        $imported_category_ids = [];

        foreach ($categories as $category) {
            $external_id = $category->getEntityId();
            $mapping = isset($mappings[$external_id]) ? $mappings[$external_id] : [];
            $mapped_category_id = isset($mapping['local_id']) ? (int) $mapping['local_id'] : 0;
            $category_id = isset($existing_category_ids[$mapped_category_id]) ? $mapped_category_id : 0;
            $parent_external_id = $this->getParentExternalId($category);

            if ($parent_external_id !== '' && !isset($imported_category_ids[$parent_external_id])) {
                $this->logError(__('synchro.category_import_error.parent_not_imported', [
                    '[external_id]'        => $external_id,
                    '[parent_external_id]' => $parent_external_id,
                ]));
                continue;
            }

            $category_id = fn_update_category([
                'company_id'  => $company_id,
                'parent_id'   => $parent_external_id === '' ? 0 : $imported_category_ids[$parent_external_id],
                'status'      => $category->status ? ObjectStatuses::ACTIVE : ObjectStatuses::DISABLED,
                'category'    => $category->name,
                'description' => $category->description,
                'seo_name'    => $category->seo_name,
            ], $category_id);

            if (!$category_id) {
                $this->logError(__('synchro.category_import_error.update_failed', [
                    '[external_id]' => $external_id,
                ]));
                continue;
            }

            $this->mapping_repository->save(
                $company_id,
                CategoryDto::ENTITY_TYPE,
                $external_id,
                $category_id,
                $category->name
            );
            $imported_category_ids[$external_id] = $category_id;
            $image_urls = $category->images ? [reset($category->images)] : [];

            $image_result = $this->image_importer->import(
                $category_id,
                'category',
                $image_urls,
                isset($mapping['full_updated_timestamp']) ? (int) $mapping['full_updated_timestamp'] : 0,
                isset($current_images[$category_id]) ? $current_images[$category_id] : []
            );
            $this->logImageErrors($image_result, $external_id);
        }

        return $imported_category_ids;
    }

    /**
     * Disables mapped categories that are absent from the external snapshot.
     *
     * @param array<string, \Tygh\Addons\Synchro\Dto\CategoryDto> $categories_by_id      Imported categories
     * @param array<string, array<string, int|string>>            $mappings              Category mappings
     * @param array<int, true>                                    $existing_category_ids Existing category identifiers
     *
     * @return void
     */
    private function disableMissingCategories(
        array $categories_by_id,
        array $mappings,
        array $existing_category_ids
    ) {
        foreach ($mappings as $external_id => $mapping) {
            $category_id = (int) $mapping['local_id'];
            if (isset($categories_by_id[$external_id]) || !isset($existing_category_ids[$category_id])) {
                continue;
            }

            if (
                !fn_tools_update_status([
                    'table'   => 'categories',
                    'id_name' => 'category_id',
                    'id'      => $category_id,
                    'status'  => ObjectStatuses::DISABLED,
                ])
            ) {
                $this->logError(__('synchro.category_import_error.disable_failed', [
                    '[external_id]' => $external_id,
                ]));
            }
        }
    }

    /**
     * Gets the external identifier of a category parent.
     *
     * @param \Tygh\Addons\Synchro\Dto\CategoryDto $category Category
     *
     * @return string
     */
    private function getParentExternalId(CategoryDto $category)
    {
        return $category->parent_id ? (string) $category->parent_id : '';
    }

    /**
     * Writes image synchronization failures to the category import log.
     *
     * @param \Tygh\Common\OperationResult $result      Image synchronization result
     * @param string                       $external_id External category identifier
     *
     * @return void
     */
    private function logImageErrors(OperationResult $result, $external_id)
    {
        /** @var array<int|string, string> $errors */
        $errors = $result->getErrors();

        foreach ($errors as $error) {
            $this->logError(__('synchro.category_import_error.image_sync_failed', [
                '[external_id]' => $external_id,
                '[error]'       => $error,
            ]));
        }
    }

    /**
     * Writes a category import error to the Synchro log.
     *
     * @param string $error Error message
     *
     * @return void
     */
    private function logError($error)
    {
        fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
            'script' => 'synchro_import.categories',
            'error'  => $error,
        ]);
    }
}
