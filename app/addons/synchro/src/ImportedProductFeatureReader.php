<?php

namespace Tygh\Addons\Synchro;

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;

/**
 * Reads and combines product features from independently completed import portions.
 */
class ImportedProductFeatureReader
{
    /** @var \Tygh\Addons\Synchro\Repository\ImportEntityRepository */
    private $repository;

    /**
     * @param \Tygh\Addons\Synchro\Repository\ImportEntityRepository $repository Import repository
     */
    public function __construct(ImportEntityRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Reads the latest product import that has at least one completed portion.
     *
     * @param int $company_id Company identifier
     *
     * @return array{int, array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto>}
     */
    public function readLatest($company_id)
    {
        $parent_import_id = $this->repository->findLatestMappableParentId(
            $company_id,
            ImportDataCommand::ENTITY_PRODUCTS
        );
        if (!$parent_import_id) {
            return [0, []];
        }

        return [$parent_import_id, $this->readByParentImportId($parent_import_id)];
    }

    /**
     * Reads and combines product features from a specified parent import.
     *
     * @param int $parent_import_id Parent import identifier
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\ProductFeatureDto>
     */
    public function readByParentImportId($parent_import_id)
    {
        $child_import_ids = $this->repository->findCompletedChildIds($parent_import_id);
        if (!$child_import_ids) {
            $child_import_ids = [$parent_import_id];
        }
        $portion_features = $this->repository->findAllByEntityTypeFromImports(
            $child_import_ids,
            ProductFeatureDto::ENTITY_TYPE,
            false
        );
        $features = [];

        /** @var \Tygh\Addons\Synchro\Dto\ProductFeatureDto $portion_feature */
        foreach ($portion_features as $portion_feature) {
            $feature_id = $portion_feature->getEntityId();
            if (!isset($features[$feature_id])) {
                $features[$feature_id] = $portion_feature;
                continue;
            }

            $features[$feature_id]->name = $portion_feature->name;
            $features[$feature_id]->group_id = $portion_feature->group_id;
            $features[$feature_id]->position = $portion_feature->position;
            $features[$feature_id]->group_name = $portion_feature->group_name;
            $features[$feature_id]->variants = array_replace(
                $features[$feature_id]->variants,
                $portion_feature->variants
            );
        }

        return array_values($features);
    }
}
