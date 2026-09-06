<?php

namespace Tygh\Addons\Synchro;

use Throwable;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository;
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

    /**
     * @param \Tygh\Database\Connection                                       $database           Database connection
     * @param \Tygh\Addons\Synchro\Repository\ProductFeatureMappingRepository $mapping_repository Mapping repository
     */
    public function __construct(Connection $database, ProductFeatureMappingRepository $mapping_repository)
    {
        $this->database = $database;
        $this->mapping_repository = $mapping_repository;
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
     * Maps selected imported features to an existing local feature.
     *
     * @param int                                               $company_id       Company identifier
     * @param array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features         Imported features
     * @param int                                               $local_feature_id Local feature identifier
     *
     * @return \Tygh\Common\OperationResult
     */
    public function map($company_id, array $features, $local_feature_id)
    {
        if (!$features) {
            return $this->failure('selection', __('synchro.feature_mapping_selection_required'));
        }

        $local_feature = $this->database->getRow(
            'SELECT feature_id, feature_type FROM ?:product_features'
            . ' WHERE feature_id = ?i AND company_id = ?i',
            $local_feature_id,
            $company_id
        );

        if (
            !$local_feature
            || !in_array(
                $local_feature['feature_type'],
                [ProductFeatures::TEXT_SELECTBOX, ProductFeatures::NUMBER_SELECTBOX],
                true
            )
        ) {
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
     * Creates a text selectbox and maps selected imported features to it.
     *
     * @param int                                               $company_id Company identifier
     * @param array<\Tygh\Addons\Synchro\Dto\ProductFeatureDto> $features   Imported features
     * @param string                                            $name       New feature name
     *
     * @return \Tygh\Common\OperationResult
     */
    public function createAndMap($company_id, array $features, $name)
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
            'feature_type'    => ProductFeatures::TEXT_SELECTBOX,
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

        try {
            $this->mapping_repository->saveMappings(
                $company_id,
                array_values($external_feature_ids),
                $local_feature_id
            );
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
