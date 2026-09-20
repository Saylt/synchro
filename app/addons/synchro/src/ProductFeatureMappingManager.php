<?php

namespace Tygh\Addons\Synchro;

use Throwable;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository;
use Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository;
use Tygh\Common\OperationResult;
use Tygh\Database\Connection;
use Tygh\Enum\ObjectStatuses;
use Tygh\Enum\ProductFeatures;

/**
 * Applies bulk mappings between imported and local product features.
 */
class ProductFeatureMappingManager
{
    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository */
    private $mapping_repository;

    /** @var \Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository|null */
    private $snapshot_repository;

    /**
     * @param \Tygh\Database\Connection                                       $database           Database connection
     * @param \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository $mapping_repository Mapping repository
     * @param \Tygh\Addons\Synchro\Repository\ProductFeatureSnapshotRepository|null $snapshot_repository Imported snapshot repository
     */
    public function __construct(
        Connection $database,
        ProductFeatureMappingRepository $mapping_repository,
        ProductFeatureSnapshotRepository $snapshot_repository = null
    )
    {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
        $this->snapshot_repository = $snapshot_repository;
    }

    /**
     * Marks selected imported features as skipped.
     *
     * @param int                                               $company_id Company identifier
     * @param array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features   Imported features
     *
     * @return \Tygh\Common\OperationResult
     */
    public function skip($company_id, array $features)
    {
        return $this->save($company_id, $features, 0);
    }

    /**
     * Marks selected features from one immutable snapshot as skipped.
     *
     * @param int                      $company_id           Company identifier
     * @param int                      $snapshot_import_id   Snapshot import identifier
     * @param array<array-key, string> $external_feature_ids  Submitted external feature identifiers
     *
     * @return \Tygh\Common\OperationResult
     */
    public function skipSnapshot($company_id, $snapshot_import_id, array $external_feature_ids)
    {
        $external_feature_ids = $this->getSnapshotSelection($snapshot_import_id, $external_feature_ids);
        if (!$external_feature_ids) {
            return $this->failure('selection', __('synchro.feature_mapping_selection_required'));
        }

        return $this->saveExternalFeatureIds($company_id, $external_feature_ids, 0);
    }

    /**
     * Maps selected features from one snapshot to an existing local feature.
     *
     * Values are hydrated only when the target is a numeric selectbox.
     *
     * @param int                      $company_id            Company identifier
     * @param int                      $snapshot_import_id    Snapshot import identifier
     * @param array<array-key, string> $external_feature_ids   Submitted external feature identifiers
     * @param int                      $local_feature_id      Local feature identifier
     * @param array<string>|null       $allowed_feature_types  Allowed local feature types
     *
     * @return \Tygh\Common\OperationResult
     */
    public function mapSnapshot(
        $company_id,
        $snapshot_import_id,
        array $external_feature_ids,
        $local_feature_id,
        array $allowed_feature_types = null
    ) {
        $external_feature_ids = $this->getSnapshotSelection($snapshot_import_id, $external_feature_ids);
        if (!$external_feature_ids) {
            return $this->failure('selection', __('synchro.feature_mapping_selection_required'));
        }

        $local_feature = $this->getAllowedLocalFeature($company_id, $local_feature_id, $allowed_feature_types);
        if (!$local_feature) {
            return $this->failure('target', __('synchro.feature_mapping_invalid_target'));
        }
        if ($local_feature['feature_type'] === ProductFeatures::NUMBER_SELECTBOX) {
            $features = $this->snapshot_repository->findFeaturesByExternalIds(
                $snapshot_import_id,
                $external_feature_ids
            );
            foreach ($features as $feature) {
                foreach ($feature->variants as $variant) {
                    if (!is_numeric($variant->value)) {
                        return $this->failure('values', __('synchro.feature_mapping_non_numeric_values'));
                    }
                }
            }
        }

        return $this->saveExternalFeatureIds(
            $company_id,
            $external_feature_ids,
            (int) $local_feature['feature_id']
        );
    }

    /**
     * Creates one local feature and maps selected features from a snapshot to it.
     *
     * @param int                      $company_id           Company identifier
     * @param int                      $snapshot_import_id   Snapshot import identifier
     * @param array<array-key, string> $external_feature_ids  Submitted external feature identifiers
     * @param string                   $name                 New feature name
     * @param string                   $feature_type         New local feature type
     *
     * @return \Tygh\Common\OperationResult
     */
    public function createAndMapSnapshot(
        $company_id,
        $snapshot_import_id,
        array $external_feature_ids,
        $name,
        $feature_type = ProductFeatures::TEXT_SELECTBOX
    ) {
        $external_feature_ids = $this->getSnapshotSelection($snapshot_import_id, $external_feature_ids);
        if (!$external_feature_ids) {
            return $this->failure('selection', __('synchro.feature_mapping_selection_required'));
        }

        $name = trim($name);
        if ($name === '') {
            return $this->failure('name', __('synchro.feature_mapping_name_required'));
        }
        $local_feature_id = fn_update_product_feature([
            'company_id'      => $company_id,
            'status'          => ObjectStatuses::ACTIVE,
            'feature_type'    => $feature_type,
            'description'     => $name,
            'internal_name'   => $name,
            'parent_id'       => 0,
            'categories_path' => '',
        ], 0);
        if (!$local_feature_id) {
            return $this->failure('create', __('synchro.feature_mapping_creation_failed'));
        }

        return $this->saveExternalFeatureIds($company_id, $external_feature_ids, (int) $local_feature_id);
    }

    /**
     * Maps selected imported features to an existing local feature.
     *
     * @param int                                               $company_id            Company identifier
     * @param array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features              Imported features
     * @param int                                               $local_feature_id      Local feature identifier
     * @param array<string>|null                                $allowed_feature_types Local feature types allowed for this mapping
     *
     * @return \Tygh\Common\OperationResult
     */
    public function map($company_id, array $features, $local_feature_id, array $allowed_feature_types = null)
    {
        if (!$features) {
            return $this->failure('selection', __('synchro.feature_mapping_selection_required'));
        }

        $local_feature = $this->getAllowedLocalFeature(
            $company_id,
            $local_feature_id,
            $allowed_feature_types
        );
        if (!$local_feature) {
            return $this->failure('target', __('synchro.feature_mapping_invalid_target'));
        }

        if ($local_feature['feature_type'] === ProductFeatures::NUMBER_SELECTBOX) {
            foreach ($features as $feature) {
                foreach ($feature->variants as $variant) {
                    if (!is_numeric($variant->value)) {
                        return $this->failure(
                            'values',
                            __('synchro.feature_mapping_non_numeric_values')
                        );
                    }
                }
            }
        }

        return $this->save($company_id, $features, (int) $local_feature['feature_id']);
    }

    /**
     * Creates a local feature and maps selected imported features to it.
     *
     * @param int                                               $company_id   Company identifier
     * @param array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features     Imported features
     * @param string                                            $name         New feature name
     * @param string                                            $feature_type New local feature type
     *
     * @return \Tygh\Common\OperationResult
     */
    public function createAndMap($company_id, array $features, $name, $feature_type = ProductFeatures::TEXT_SELECTBOX)
    {
        if (!$features) {
            return $this->failure('selection', __('synchro.feature_mapping_selection_required'));
        }

        $name = trim($name);
        if ($name === '') {
            return $this->failure('name', __('synchro.feature_mapping_name_required'));
        }

        $local_feature_id = fn_update_product_feature([
            'company_id'      => $company_id,
            'status'          => ObjectStatuses::ACTIVE,
            'feature_type'    => $feature_type,
            'description'     => $name,
            'internal_name'   => $name,
            'parent_id'       => 0,
            'categories_path' => '',
        ], 0);

        if (!$local_feature_id) {
            return $this->failure('create', __('synchro.feature_mapping_creation_failed'));
        }

        return $this->save($company_id, $features, (int) $local_feature_id);
    }

    /**
     * Saves one local target for every selected imported feature.
     *
     * @param int                                               $company_id       Company identifier
     * @param array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features         Imported features
     * @param int                                               $local_feature_id Local feature identifier
     *
     * @return \Tygh\Common\OperationResult
     */
    private function save($company_id, array $features, $local_feature_id)
    {
        $external_feature_ids = [];

        foreach ($features as $feature) {
            if (!$feature instanceof ProductFeatureDto) {
                continue;
            }

            $external_feature_ids[$feature->getEntityId()] = $feature->getEntityId();
        }

        if (!$external_feature_ids) {
            return $this->failure('selection', __('synchro.feature_mapping_selection_required'));
        }

        return $this->saveExternalFeatureIds($company_id, array_values($external_feature_ids), $local_feature_id);
    }

    /**
     * Validates that selected identifiers still belong to the submitted snapshot.
     *
     * @param int                      $snapshot_import_id  Snapshot import identifier
     * @param array<array-key, string> $external_feature_ids Submitted external feature identifiers
     *
     * @return array<string>
     */
    private function getSnapshotSelection($snapshot_import_id, array $external_feature_ids)
    {
        if (!$this->snapshot_repository) {
            return [];
        }

        return $this->snapshot_repository->filterExternalFeatureIds($snapshot_import_id, $external_feature_ids);
    }

    /**
     * Finds a permitted local target feature.
     *
     * @param int                $company_id           Company identifier
     * @param int                $local_feature_id     Local feature identifier
     * @param array<string>|null $allowed_feature_types Permitted local feature types
     *
     * @return array<string, int|string>|false
     */
    private function getAllowedLocalFeature($company_id, $local_feature_id, array $allowed_feature_types = null)
    {
        $local_feature = $this->database->getRow(
            'SELECT feature_id, feature_type FROM ?:product_features'
            . ' WHERE feature_id = ?i AND company_id = ?i',
            $local_feature_id,
            $company_id
        );
        $allowed_feature_types = $allowed_feature_types ?: [
            ProductFeatures::TEXT_SELECTBOX,
            ProductFeatures::NUMBER_SELECTBOX,
        ];

        return $local_feature && in_array($local_feature['feature_type'], $allowed_feature_types, true)
            ? $local_feature
            : false;
    }

    /**
     * Persists mappings for already validated external feature identifiers.
     *
     * @param int                      $company_id           Company identifier
     * @param array<array-key, string> $external_feature_ids  External feature identifiers
     * @param int                      $local_feature_id      Local feature identifier
     *
     * @return \Tygh\Common\OperationResult
     */
    private function saveExternalFeatureIds($company_id, array $external_feature_ids, $local_feature_id)
    {
        try {
            $this->mapping_repository->saveMappings($company_id, $external_feature_ids, $local_feature_id);
        } catch (Throwable $exception) {
            return $this->failure('save', __('synchro.feature_mapping_save_failed'));
        }

        return new OperationResult(true, $local_feature_id);
    }

    /**
     * Creates a failed operation result.
     *
     * @param string $code  Error code
     * @param string $error Error message
     *
     * @return \Tygh\Common\OperationResult
     */
    private function failure($code, $error)
    {
        $result = new OperationResult(false);
        $result->addError($code, $error);

        return $result;
    }
}
