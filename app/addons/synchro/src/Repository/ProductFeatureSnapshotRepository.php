<?php

namespace Tygh\Addons\Synchro\Repository;

use RuntimeException;
use Throwable;
use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Database\Connection;

/**
 * Stores normalized product-feature snapshots for product imports.
 */
class ProductFeatureSnapshotRepository
{
    const FEATURES_TABLE_NAME = 'synchro_import_product_features';

    const VARIANTS_TABLE_NAME = 'synchro_import_product_feature_variants';

    const MAPPING_STATUS_ALL = 'all';

    const MAPPING_STATUS_MAPPED = 'mapped';

    const MAPPING_STATUS_UNMAPPED = 'unmapped';

    const MAPPING_STATUS_SKIPPED = 'skipped';

    /** @var \Tygh\Database\Connection */
    private $database;

    /**
     * @param \Tygh\Database\Connection $database Database connection
     */
    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * Saves the normalized features collected by one fetched API portion.
     *
     * Repeated features inside the same child import are deliberately updated:
     * a child is private staging and may receive the same feature from many pages.
     *
     * @param int                                                              $import_id Child import identifier
     * @param array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features  Features indexed arbitrarily
     *
     * @return int Number of affected feature and variant records
     */
    public function savePortion($import_id, array $features)
    {
        $feature_records = [];
        $variant_records = [];

        foreach ($features as $feature) {
            $external_feature_id = $feature->getEntityId();
            $feature_records[$external_feature_id] = [
                'import_id'           => (int) $import_id,
                'external_feature_id' => $external_feature_id,
                'name'                => (string) $feature->name,
                'group_id'            => $feature->group_id === null ? null : (int) $feature->group_id,
                'group_name'          => $feature->group_name === null ? null : (string) $feature->group_name,
                'position'            => $feature->position === null ? null : (int) $feature->position,
            ];

            foreach ($feature->variants as $variant) {
                $variant_records[$variant->getEntityId()] = [
                    'import_id'           => (int) $import_id,
                    'external_feature_id' => $external_feature_id,
                    'external_variant_id' => $variant->getEntityId(),
                    'value'               => (string) $variant->value,
                ];
            }
        }

        if (!$feature_records) {
            return 0;
        }

        $result = (int) $this->database->replaceInto(
            self::FEATURES_TABLE_NAME,
            array_values($feature_records),
            true,
            ['name', 'group_id', 'group_name', 'position']
        );
        if ($variant_records) {
            $result += (int) $this->database->replaceInto(
                self::VARIANTS_TABLE_NAME,
                array_values($variant_records),
                true,
                ['external_feature_id', 'value']
            );
        }

        return $result;
    }

    /**
     * Atomically merges a completed child snapshot into its root snapshot.
     *
     * The root row lock serializes only merge finalization for this parent.
     * Existing root rows win, so parallel portions only add values that their
     * previously committed siblings did not already publish.
     *
     * @param int $child_import_id  Completed portion import identifier
     * @param int $parent_import_id Root import identifier
     *
     * @return bool Whether the child was completed
     *
     * @throws \RuntimeException When the root is unavailable or the child cannot be completed.
     * @throws \Throwable When a database operation fails.
     */
    public function mergeAndCompletePortion($child_import_id, $parent_import_id)
    {
        $this->database->beginTransaction();

        try {
            $locked_parent_id = (int) $this->database->getField(
                'SELECT import_id FROM ?:?p WHERE import_id = ?i FOR UPDATE',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                $parent_import_id
            );
            if ($locked_parent_id !== (int) $parent_import_id) {
                throw new RuntimeException('Cannot lock root product-feature snapshot.');
            }

            $this->mergeFeatures($child_import_id, $parent_import_id);
            $this->mergeVariants($child_import_id, $parent_import_id);
            $this->deleteByImportIds([$child_import_id]);

            $completed = (bool) $this->database->query(
                'UPDATE ?:?p SET status = ?s, updated_at = ?i, completed_at = ?i'
                . ' WHERE import_id = ?i AND parent_import_id = ?i AND status = ?s',
                ImportEntityRepository::IMPORTS_TABLE_NAME,
                ImportEntityRepository::STATUS_COMPLETED,
                TIME,
                TIME,
                $child_import_id,
                $parent_import_id,
                ImportEntityRepository::STATUS_PROCESSING
            );
            if (!$completed) {
                throw new RuntimeException('Cannot complete product-feature snapshot portion.');
            }

            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }

        return true;
    }

    /**
     * Deletes normalized feature snapshots belonging to explicit imports.
     *
     * @param array<array-key, int> $import_ids Import identifiers
     *
     * @return void
     */
    public function deleteByImportIds(array $import_ids)
    {
        $import_ids = array_values(array_unique(array_filter(array_map('intval', $import_ids))));
        if (!$import_ids) {
            return;
        }

        $this->database->query(
            'DELETE FROM ?:?p WHERE import_id IN (?n)',
            self::VARIANTS_TABLE_NAME,
            $import_ids
        );
        $this->database->query(
            'DELETE FROM ?:?p WHERE import_id IN (?n)',
            self::FEATURES_TABLE_NAME,
            $import_ids
        );
    }

    /**
     * Reads one keyset batch of features that have a non-skipped local mapping.
     *
     * Variant values are selected only after the small feature batch is known.
     *
     * @param int    $import_id                 Published snapshot import identifier
     * @param int    $company_id                Company identifier
     * @param string $after_external_feature_id Exclusive external feature identifier cursor
     * @param int    $limit                     Maximum number of features
     *
     * @return array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto>
     */
    public function findMappedFeatureBatch($import_id, $company_id, $after_external_feature_id, $limit)
    {
        $feature_rows = $this->database->getArray(
            'SELECT features.external_feature_id, features.name, features.group_id, features.group_name, features.position'
            . ' FROM ?:?p AS features'
            . ' INNER JOIN ?:?p AS mappings ON mappings.company_id = ?i'
            . ' AND mappings.external_feature_id = features.external_feature_id'
            . ' WHERE features.import_id = ?i AND mappings.local_feature_id > ?i'
            . ' AND features.external_feature_id > ?s'
            . ' ORDER BY features.external_feature_id LIMIT ?i',
            self::FEATURES_TABLE_NAME,
            ProductFeatureMappingRepository::TABLE_NAME,
            $company_id,
            $import_id,
            0,
            $after_external_feature_id,
            $limit
        );
        return $this->hydrateFeatures($import_id, $feature_rows);
    }

    /**
     * Finds the current product-feature snapshot for mapping.
     *
     * A successful full import always wins over a test snapshot, even when
     * the test import finished later.
     *
     * @param int $company_id Company identifier
     *
     * @return int Root import identifier, zero when no snapshot is available
     */
    public function findLatestMappingSnapshotId($company_id)
    {
        return (int) $this->database->getField(
            'SELECT import_id FROM ?:?p'
            . ' WHERE company_id = ?i AND entity_type = ?s AND parent_import_id = ?i'
            . ' AND status = ?s AND collect_product_features = ?s'
            . ' AND source_type IN (?a)'
            . ' ORDER BY source_type = ?s DESC, import_id DESC LIMIT 1',
            ImportEntityRepository::IMPORTS_TABLE_NAME,
            $company_id,
            ImportDataCommand::ENTITY_PRODUCTS,
            0,
            ImportEntityRepository::STATUS_COMPLETED,
            ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES,
            [ImportEntityRepository::SOURCE_TYPE_FULL, ImportEntityRepository::SOURCE_TYPE_TEST],
            ImportEntityRepository::SOURCE_TYPE_FULL
        );
    }

    /**
     * Finds the newest published snapshot of one source type.
     *
     * This is used when a previously active product application has finished:
     * its snapshot may then be safely removed if a newer snapshot exists.
     *
     * @param int    $company_id  Company identifier
     * @param string $source_type Snapshot source type
     *
     * @return int Root import identifier, zero when no snapshot is available
     */
    public function findLatestSnapshotId($company_id, $source_type)
    {
        return (int) $this->database->getField(
            'SELECT import_id FROM ?:?p'
            . ' WHERE company_id = ?i AND entity_type = ?s AND parent_import_id = ?i'
            . ' AND source_type = ?s AND status = ?s AND collect_product_features = ?s'
            . ' ORDER BY import_id DESC LIMIT 1',
            ImportEntityRepository::IMPORTS_TABLE_NAME,
            $company_id,
            ImportDataCommand::ENTITY_PRODUCTS,
            0,
            $source_type,
            ImportEntityRepository::STATUS_COMPLETED,
            ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES
        );
    }

    /**
     * Finds completed snapshots superseded by a newly completed snapshot.
     *
     * A snapshot referenced by a queued, running, or stopping application is
     * deliberately retained until that application is terminal.
     *
     * @param int    $company_id       Company identifier
     * @param string $source_type      Snapshot source type
     * @param int    $current_import_id Newly completed snapshot identifier
     *
     * @return array<int>
     */
    public function findSupersededSnapshotIds($company_id, $source_type, $current_import_id)
    {
        return array_map('intval', $this->database->getColumn(
            'SELECT snapshots.import_id FROM ?:?p AS snapshots'
            . ' LEFT JOIN ?:?p AS applications ON applications.parent_import_id = ?i'
            . ' AND applications.staging_import_id = snapshots.import_id'
            . ' AND applications.status IN (?a)'
            . ' WHERE snapshots.company_id = ?i AND snapshots.entity_type = ?s'
            . ' AND snapshots.source_type = ?s AND snapshots.parent_import_id = ?i'
            . ' AND snapshots.status = ?s AND snapshots.collect_product_features = ?s'
            . ' AND snapshots.import_id != ?i AND applications.import_id IS NULL',
            ImportEntityRepository::IMPORTS_TABLE_NAME,
            ImportEntityRepository::IMPORTS_TABLE_NAME,
            0,
            [
                ImportEntityRepository::STATUS_QUEUED,
                ImportEntityRepository::STATUS_PROCESSING,
                ImportEntityRepository::STATUS_STOPPING,
            ],
            $company_id,
            ImportDataCommand::ENTITY_PRODUCTS,
            $source_type,
            0,
            ImportEntityRepository::STATUS_COMPLETED,
            ImportEntityRepository::COLLECT_PRODUCT_FEATURES_YES,
            $current_import_id
        ));
    }

    /**
     * Gets one server-side page of imported feature metadata for mapping.
     *
     * Values themselves are deliberately omitted; only their count is needed
     * to render the mapping table.
     *
     * @param int                                                         $import_id      Snapshot import identifier
     * @param int                                                         $company_id     Company identifier
     * @param array{page?: int, q?: string, mapping_status?: string}     $params         Mapping filter parameters
     * @param int                                                         $items_per_page Page size
     *
     * @return array{array<array<string, int|string|null>>, array<string, int|string>}
     */
    public function getMappingPage($import_id, $company_id, array $params, $items_per_page)
    {
        $params['page'] = empty($params['page']) ? 1 : max(1, (int) $params['page']);
        $params['q'] = isset($params['q']) ? trim((string) $params['q']) : '';
        $statuses = [
            self::MAPPING_STATUS_ALL,
            self::MAPPING_STATUS_MAPPED,
            self::MAPPING_STATUS_UNMAPPED,
            self::MAPPING_STATUS_SKIPPED,
        ];
        $params['mapping_status'] = isset($params['mapping_status'])
            && in_array($params['mapping_status'], $statuses, true)
            ? $params['mapping_status']
            : self::MAPPING_STATUS_ALL;
        $condition = '';
        if ($params['q'] !== '') {
            $condition .= $this->database->quote(
                ' AND LOWER(features.name) LIKE LOWER(?s)',
                '%' . $params['q'] . '%'
            );
        }
        if ($params['mapping_status'] === self::MAPPING_STATUS_MAPPED) {
            $condition .= ' AND mappings.local_feature_id > 0';
        } elseif ($params['mapping_status'] === self::MAPPING_STATUS_UNMAPPED) {
            $condition .= ' AND mappings.external_feature_id IS NULL';
        } elseif ($params['mapping_status'] === self::MAPPING_STATUS_SKIPPED) {
            $condition .= ' AND mappings.local_feature_id = 0';
        }

        $total_items = (int) $this->database->getField(
            'SELECT COUNT(*) FROM ?:?p AS features'
            . ' LEFT JOIN ?:?p AS mappings ON mappings.company_id = ?i'
            . ' AND mappings.external_feature_id = features.external_feature_id'
            . ' WHERE features.import_id = ?i ?p',
            self::FEATURES_TABLE_NAME,
            ProductFeatureMappingRepository::TABLE_NAME,
            $company_id,
            $import_id,
            $condition
        );
        $params['items_per_page'] = $items_per_page;
        $params['total_items'] = $total_items;
        $limit = db_paginate($params['page'], $items_per_page, $total_items);
        $mappings = $this->database->getArray(
            'SELECT features.external_feature_id AS external_id, features.name, features.group_name,'
            . ' COUNT(variants.external_variant_id) AS variants_count, mappings.local_feature_id,'
            . ' descriptions.description AS local_feature_name'
            . ' FROM ?:?p AS features'
            . ' LEFT JOIN ?:?p AS variants ON variants.import_id = features.import_id'
            . ' AND variants.external_feature_id = features.external_feature_id'
            . ' LEFT JOIN ?:?p AS mappings ON mappings.company_id = ?i'
            . ' AND mappings.external_feature_id = features.external_feature_id'
            . ' LEFT JOIN ?:product_features_descriptions AS descriptions'
            . ' ON descriptions.feature_id = mappings.local_feature_id AND descriptions.lang_code = ?s'
            . ' WHERE features.import_id = ?i ?p'
            . ' GROUP BY features.external_feature_id, features.name, features.group_name,'
            . ' mappings.local_feature_id, descriptions.description'
            . ' ORDER BY features.name, features.external_feature_id ?p',
            self::FEATURES_TABLE_NAME,
            self::VARIANTS_TABLE_NAME,
            ProductFeatureMappingRepository::TABLE_NAME,
            $company_id,
            CART_LANGUAGE,
            $import_id,
            $condition,
            $limit
        );

        foreach ($mappings as &$mapping) {
            $mapping['local_feature_id'] = $mapping['local_feature_id'] === null
                ? null
                : (int) $mapping['local_feature_id'];
            $mapping['variants_count'] = (int) $mapping['variants_count'];
            $mapping['is_local_feature_missing'] = $mapping['local_feature_id'] > 0
                && $mapping['local_feature_name'] === null;
        }
        unset($mapping);

        return [$mappings, $params];
    }

    /**
     * Returns only submitted feature IDs which still belong to the snapshot.
     *
     * @param int                      $import_id                   Snapshot import identifier
     * @param array<array-key, string> $external_feature_ids         Submitted external feature identifiers
     *
     * @return array<string>
     */
    public function filterExternalFeatureIds($import_id, array $external_feature_ids)
    {
        $external_feature_ids = array_values(array_unique(array_filter($external_feature_ids, static function ($feature_id) {
            return is_string($feature_id) && $feature_id !== '';
        })));
        if (!$external_feature_ids) {
            return [];
        }

        return array_map('strval', $this->database->getColumn(
            'SELECT external_feature_id FROM ?:?p'
            . ' WHERE import_id = ?i AND external_feature_id IN (?a)',
            self::FEATURES_TABLE_NAME,
            $import_id,
            $external_feature_ids
        ));
    }

    /**
     * Hydrates selected features with variants for numeric mapping validation.
     *
     * @param int                      $import_id           Snapshot import identifier
     * @param array<array-key, string> $external_feature_ids Selected external feature identifiers
     *
     * @return array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto>
     */
    public function findFeaturesByExternalIds($import_id, array $external_feature_ids)
    {
        if (!$external_feature_ids) {
            return [];
        }

        $feature_rows = $this->database->getArray(
            'SELECT external_feature_id, name, group_id, group_name, position FROM ?:?p'
            . ' WHERE import_id = ?i AND external_feature_id IN (?a)'
            . ' ORDER BY external_feature_id',
            self::FEATURES_TABLE_NAME,
            $import_id,
            $external_feature_ids
        );

        return $this->hydrateFeatures($import_id, $feature_rows);
    }

    /**
     * Builds temporary DTOs from feature rows and their selected variants.
     *
     * @param int                               $import_id    Snapshot import identifier
     * @param array<array<string, int|string>> $feature_rows Feature metadata rows
     *
     * @return array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto>
     */
    private function hydrateFeatures($import_id, array $feature_rows)
    {
        if (!$feature_rows) {
            return [];
        }

        $features = [];
        foreach ($feature_rows as $feature_row) {
            $feature = new ProductFeatureDto();
            $feature->id = (string) $feature_row['external_feature_id'];
            $feature->name = (string) $feature_row['name'];
            $feature->group_id = $feature_row['group_id'] === null ? null : (int) $feature_row['group_id'];
            $feature->group_name = $feature_row['group_name'] === null ? null : (string) $feature_row['group_name'];
            $feature->position = $feature_row['position'] === null ? null : (int) $feature_row['position'];
            $features[$feature->getEntityId()] = $feature;
        }

        $variant_rows = $this->database->getArray(
            'SELECT external_feature_id, external_variant_id, value FROM ?:?p'
            . ' WHERE import_id = ?i AND external_feature_id IN (?a)'
            . ' ORDER BY external_feature_id, external_variant_id',
            self::VARIANTS_TABLE_NAME,
            $import_id,
            array_keys($features)
        );
        foreach ($variant_rows as $variant_row) {
            $external_feature_id = (string) $variant_row['external_feature_id'];
            if (!isset($features[$external_feature_id])) {
                continue;
            }

            $variant = new ProductFeatureVariantDto();
            $variant->id = (string) $variant_row['external_variant_id'];
            $variant->feature_id = $external_feature_id;
            $variant->name = (string) $variant_row['value'];
            $variant->value = (string) $variant_row['value'];
            $features[$external_feature_id]->variants[$variant->getEntityId()] = $variant;
        }

        return array_values($features);
    }

    /**
     * Copies child feature definitions absent from the root snapshot.
     *
     * @param int $child_import_id  Child import identifier
     * @param int $parent_import_id Parent import identifier
     *
     * @return void
     */
    private function mergeFeatures($child_import_id, $parent_import_id)
    {
        $this->database->query(
            'INSERT INTO ?:?p (import_id, external_feature_id, name, group_id, group_name, position)'
            . ' SELECT ?i, child.external_feature_id, child.name, child.group_id, child.group_name, child.position'
            . ' FROM ?:?p AS child'
            . ' LEFT JOIN ?:?p AS parent ON parent.import_id = ?i'
            . ' AND parent.external_feature_id = child.external_feature_id'
            . ' WHERE child.import_id = ?i AND parent.external_feature_id IS NULL',
            self::FEATURES_TABLE_NAME,
            $parent_import_id,
            self::FEATURES_TABLE_NAME,
            self::FEATURES_TABLE_NAME,
            $parent_import_id,
            $child_import_id
        );
    }

    /**
     * Copies child feature variants absent from the root snapshot.
     *
     * @param int $child_import_id  Child import identifier
     * @param int $parent_import_id Parent import identifier
     *
     * @return void
     */
    private function mergeVariants($child_import_id, $parent_import_id)
    {
        $this->database->query(
            'INSERT INTO ?:?p (import_id, external_feature_id, external_variant_id, value)'
            . ' SELECT ?i, child.external_feature_id, child.external_variant_id, child.value'
            . ' FROM ?:?p AS child'
            . ' LEFT JOIN ?:?p AS parent ON parent.import_id = ?i'
            . ' AND parent.external_variant_id = child.external_variant_id'
            . ' WHERE child.import_id = ?i AND parent.external_variant_id IS NULL',
            self::VARIANTS_TABLE_NAME,
            $parent_import_id,
            self::VARIANTS_TABLE_NAME,
            self::VARIANTS_TABLE_NAME,
            $parent_import_id,
            $child_import_id
        );
    }
}
