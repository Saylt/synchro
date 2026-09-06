<?php

namespace Tygh\Addons\Synchro\Repository;

use Tygh\Database\Connection;

/**
 * Stores mappings between imported and local product features.
 */
class ProductFeatureMappingRepository
{
    const TABLE_NAME = 'synchro_product_feature_mappings';

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
     * Finds mappings for imported product features.
     *
     * @param int                      $company_id           Company identifier
     * @param array<array-key, string> $external_feature_ids Imported feature identifiers
     *
     * @return array<string, int> Local feature identifiers indexed by external feature identifiers
     */
    public function findByExternalIds($company_id, array $external_feature_ids)
    {
        if (!$external_feature_ids) {
            return [];
        }

        $mappings = $this->database->getSingleHash(
            'SELECT external_feature_id, local_feature_id FROM ?:?p'
            . ' WHERE company_id = ?i AND external_feature_id IN (?a)',
            ['external_feature_id', 'local_feature_id'],
            self::TABLE_NAME,
            $company_id,
            $external_feature_ids
        );

        $local_feature_ids = [];

        foreach ($mappings as $external_feature_id => $local_feature_id) {
            $local_feature_ids[(string) $external_feature_id] = (int) $local_feature_id;
        }

        return $local_feature_ids;
    }

    /**
     * Maps imported product features to one local feature.
     *
     * @param int                      $company_id           Company identifier
     * @param array<array-key, string> $external_feature_ids External feature identifiers
     * @param int                      $local_feature_id     Local feature identifier, zero means skip
     *
     * @return int Number of stored mapping records
     */
    public function saveMappings($company_id, array $external_feature_ids, $local_feature_id)
    {
        $records = [];

        foreach (array_unique($external_feature_ids) as $external_feature_id) {
            if ($external_feature_id === '') {
                continue;
            }

            $records[] = [
                'company_id'          => $company_id,
                'external_feature_id' => $external_feature_id,
                'local_feature_id'    => $local_feature_id,
            ];
        }

        if (!$records) {
            return 0;
        }

        return $this->database->replaceInto(
            self::TABLE_NAME,
            $records,
            true,
            ['local_feature_id']
        );
    }
}
