<?php

namespace Tygh\Addons\Synchro\Repository;

use Throwable;
use Tygh\Database\Connection;

/**
 * Stores mappings between imported and local product features.
 */
class ProductFeatureMappingRepository
{
    const TABLE_NAME = 'synchro_product_feature_mappings';

    const ACTION_SKIP = 'skip';

    const ACTION_CREATE = 'create';

    const ACTION_MAP = 'map';

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
     * @return array<array-key, array{action: string, local_feature_ids: array<int>}>
     */
    public function findByExternalIds($company_id, array $external_feature_ids)
    {
        if (!$external_feature_ids) {
            return [];
        }

        $rows = $this->database->getArray(
            'SELECT external_feature_id, action, local_feature_id FROM ?:?p'
            . ' WHERE company_id = ?i AND external_feature_id IN (?a)'
            . ' ORDER BY external_feature_id, local_feature_id',
            self::TABLE_NAME,
            $company_id,
            $external_feature_ids
        );
        $mappings = [];

        foreach ($rows as $row) {
            $external_feature_id = (string) $row['external_feature_id'];

            if (!isset($mappings[$external_feature_id])) {
                $mappings[$external_feature_id] = [
                    'action'            => (string) $row['action'],
                    'local_feature_ids' => [],
                ];
            }

            if ($row['action'] === self::ACTION_MAP && (int) $row['local_feature_id'] > 0) {
                $mappings[$external_feature_id]['local_feature_ids'][] = (int) $row['local_feature_id'];
            }
        }

        return $mappings;
    }

    /**
     * Replaces mappings for imported product features.
     *
     * @param int   $company_id Company identifier
     * @param array $mappings   Mapping data indexed by imported feature identifier
     *
     * @psalm-param array<array-key, array{
     *     action?: string,
     *     local_feature_ids?: array<array-key, int|string>
     * }> $mappings
     *
     * @return int Number of stored mapping records
     *
     * @throws \Throwable When mappings cannot be replaced.
     */
    public function replaceMappings($company_id, array $mappings)
    {
        $external_feature_ids = [];
        $records = [];

        foreach ($mappings as $external_feature_id => $mapping) {
            $external_feature_id = (string) $external_feature_id;
            if ($external_feature_id === '') {
                continue;
            }

            $action = isset($mapping['action'])
                && in_array(
                    $mapping['action'],
                    [self::ACTION_SKIP, self::ACTION_CREATE, self::ACTION_MAP],
                    true
                )
                ? $mapping['action']
                : self::ACTION_SKIP;
            $local_feature_ids = [];

            if ($action === self::ACTION_MAP && !empty($mapping['local_feature_ids'])) {
                foreach ($mapping['local_feature_ids'] as $local_feature_id) {
                    $local_feature_id = (int) $local_feature_id;
                    if ($local_feature_id > 0) {
                        $local_feature_ids[$local_feature_id] = $local_feature_id;
                    }
                }
            }

            if ($action === self::ACTION_MAP && !$local_feature_ids) {
                $action = self::ACTION_SKIP;
            }

            $external_feature_ids[$external_feature_id] = $external_feature_id;

            if ($action === self::ACTION_MAP) {
                foreach ($local_feature_ids as $local_feature_id) {
                    $records[] = [
                        'company_id'          => $company_id,
                        'external_feature_id' => $external_feature_id,
                        'action'              => $action,
                        'local_feature_id'    => $local_feature_id,
                    ];
                }
            } else {
                $records[] = [
                    'company_id'          => $company_id,
                    'external_feature_id' => $external_feature_id,
                    'action'              => $action,
                    'local_feature_id'    => 0,
                ];
            }
        }

        if (!$external_feature_ids) {
            return 0;
        }

        $this->database->beginTransaction();

        try {
            $this->database->query(
                'DELETE FROM ?:?p WHERE company_id = ?i AND external_feature_id IN (?a)',
                self::TABLE_NAME,
                $company_id,
                array_values($external_feature_ids)
            );
            $this->database->query('INSERT INTO ?:?p ?m', self::TABLE_NAME, $records);
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }

        return count($records);
    }
}
