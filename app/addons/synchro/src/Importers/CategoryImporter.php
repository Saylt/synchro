<?php

namespace Tygh\Addons\Synchro\Importers;

use Tygh\Addons\Synchro\Dto\CategoryDto;
use Tygh\Addons\Synchro\Logging;
use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Common\OperationResult;
use Tygh\Database\Connection;
use Tygh\Enum\ObjectStatuses;

/**
 * Imports persisted category batches into CS-Cart.
 */
class CategoryImporter
{
    const LOG_SOURCE = 'synchro_import.categories';

    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\Importers\ImageImporter */
    private $image_importer;

    /** @var \Tygh\Addons\Synchro\Logging */
    private $logging;

    /**
     * Initializes the category importer.
     *
     * @param \Tygh\Database\Connection                                 $database           Database connection
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityMapRepository $mapping_repository Entity mapping repository
     * @param \Tygh\Addons\Synchro\Importers\ImageImporter              $image_importer     Image importer
     * @param \Tygh\Addons\Synchro\Logging|null                         $logging            Synchro journal service
     */
    public function __construct(
        Connection $database,
        ImportEntityMapRepository $mapping_repository,
        ImageImporter $image_importer,
        Logging $logging = null
    ) {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
        $this->image_importer = $image_importer;
        $this->logging = $logging ?: new Logging($database);
    }

    /**
     * Imports one persisted parent-first category batch.
     *
     * @param array<\Tygh\Addons\Synchro\Dto\CategoryDto> $categories Category DTOs in application order
     * @param int                                         $company_id Company identifier
     *
     * @return array<string, int> Local category identifiers indexed by external identifiers
     */
    public function importBatch(array $categories, $company_id)
    {
        if (!$categories) {
            return [];
        }

        $external_ids = [];
        foreach ($categories as $category) {
            $external_ids[] = $category->getEntityId();
            $parent_external_id = $this->getParentExternalId($category);
            if ($parent_external_id !== '') {
                $external_ids[] = $parent_external_id;
            }
        }
        $mappings = $this->mapping_repository->findByExternalIds(
            $company_id,
            CategoryDto::ENTITY_TYPE,
            array_values(array_unique($external_ids))
        );
        $existing_category_ids = $this->findExistingCategoryIds($mappings);
        $current_images = $this->image_importer->findByObjectIds(
            'category',
            array_keys($existing_category_ids)
        );

        return $this->importCategories(
            $categories,
            $company_id,
            $mappings,
            $existing_category_ids,
            $current_images
        );
    }

    /**
     * Disables every mapped category that does not belong to a staged snapshot.
     *
     * @param int $import_id  Completed category import identifier
     * @param int $company_id Company identifier
     *
     * @return bool
     */
    public function disableMissingCategories($import_id, $company_id)
    {
        return $this->database->query(
            'UPDATE ?:categories AS categories'
            . ' INNER JOIN ?:?p AS mappings ON mappings.local_id = categories.category_id'
            . ' LEFT JOIN ?:?p AS entities ON entities.import_id = ?i'
            . ' AND entities.entity_type = ?s AND entities.entity_id = mappings.external_id'
            . ' SET categories.status = ?s'
            . ' WHERE mappings.company_id = ?i AND mappings.entity_type = ?s AND entities.entity_id IS NULL',
            ImportEntityMapRepository::TABLE_NAME,
            ImportEntityRepository::TABLE_NAME,
            $import_id,
            CategoryDto::ENTITY_TYPE,
            ObjectStatuses::DISABLED,
            $company_id,
            CategoryDto::ENTITY_TYPE
        ) !== false;
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
     * Applies parent-first categories and their images to CS-Cart.
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
        $resolved_parent_category_ids = [];

        foreach ($categories as $category) {
            $external_id = $category->getEntityId();
            $mapping = isset($mappings[$external_id]) ? $mappings[$external_id] : [];
            $mapped_category_id = isset($mapping['local_id']) ? (int) $mapping['local_id'] : 0;
            $category_id = isset($existing_category_ids[$mapped_category_id]) ? $mapped_category_id : 0;
            $parent_external_id = $this->getParentExternalId($category);

            if ($parent_external_id !== '' && !isset($resolved_parent_category_ids[$parent_external_id])) {
                $parent_mapping = isset($mappings[$parent_external_id]) ? $mappings[$parent_external_id] : [];
                $parent_category_id = isset($parent_mapping['local_id']) ? (int) $parent_mapping['local_id'] : 0;
                if (isset($existing_category_ids[$parent_category_id])) {
                    $resolved_parent_category_ids[$parent_external_id] = $parent_category_id;
                }
            }

            if ($parent_external_id !== '' && !isset($resolved_parent_category_ids[$parent_external_id])) {
                $this->logging->error(self::LOG_SOURCE, __('synchro.category_import_error.parent_not_imported', [
                    '[external_id]'        => $external_id,
                    '[parent_external_id]' => $parent_external_id,
                ]));
                continue;
            }

            $category_id = fn_update_category([
                'company_id'  => $company_id,
                'parent_id'   => $parent_external_id === '' ? 0 : $resolved_parent_category_ids[$parent_external_id],
                'status'      => $category->status ? ObjectStatuses::ACTIVE : ObjectStatuses::DISABLED,
                'category'    => $category->name,
                'description' => $category->description,
                'seo_name'    => $category->seo_name,
            ], $category_id);

            if (!$category_id) {
                $this->logging->error(self::LOG_SOURCE, __('synchro.category_import_error.update_failed', [
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
            $resolved_parent_category_ids[$external_id] = $category_id;
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
            $this->logging->warning(self::LOG_SOURCE, __('synchro.category_import_error.image_sync_failed', [
                '[external_id]' => $external_id,
                '[error]'       => $error,
            ]));
        }
    }
}
