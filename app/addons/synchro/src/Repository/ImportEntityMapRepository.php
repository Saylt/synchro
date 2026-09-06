<?php

namespace Tygh\Addons\Synchro\Repository;

use Tygh\Database\Connection;

/**
 * Maps identifiers received from the external API to CS-Cart entity identifiers.
 */
class ImportEntityMapRepository
{
    const TABLE_NAME = 'synchro_import_entity_map';

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
     * Finds an entity mapping.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Entity type
     * @param string $external_id External entity identifier
     *
     * @return array<string, int|string>
     */
    public function find($company_id, $entity_type, $external_id)
    {
        return $this->database->getRow(
            'SELECT * FROM ?:?p WHERE company_id = ?i AND entity_type = ?s AND external_id = ?s',
            self::TABLE_NAME,
            $company_id,
            $entity_type,
            $external_id
        );
    }

    /**
     * Finds mappings for a batch of external entities.
     *
     * @param int           $company_id   Company identifier
     * @param string        $entity_type  Entity type
     * @param array<string> $external_ids External entity identifiers
     *
     * @return array<string, array<string, int|string>> Mappings indexed by external identifier
     */
    public function findByExternalIds($company_id, $entity_type, array $external_ids)
    {
        if (!$external_ids) {
            return [];
        }

        return $this->database->getHash(
            'SELECT * FROM ?:?p WHERE company_id = ?i AND entity_type = ?s AND external_id IN (?a)',
            'external_id',
            self::TABLE_NAME,
            $company_id,
            $entity_type,
            $external_ids
        );
    }

    /**
     * Finds all mappings of an entity type.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Entity type
     *
     * @return array<string, array<string, int|string>> Mappings indexed by external identifier
     */
    public function findAllByEntityType($company_id, $entity_type)
    {
        /** @var array<string, array<string, int|string>> $mappings */
        $mappings = $this->database->getHash(
            'SELECT * FROM ?:?p WHERE company_id = ?i AND entity_type = ?s',
            'external_id',
            self::TABLE_NAME,
            $company_id,
            $entity_type
        );

        return $mappings;
    }

    /**
     * Creates a mapping or updates its mutable data without changing update timestamps.
     * Company, entity type and external ID identify the mapping and therefore are not updated on conflict.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Entity type
     * @param string $external_id External entity identifier
     * @param int    $local_id    CS-Cart entity identifier
     * @param string $entity_name Entity name
     *
     * @return int
     */
    public function save($company_id, $entity_type, $external_id, $local_id, $entity_name = '')
    {
        return $this->database->replaceInto(
            self::TABLE_NAME,
            [
                'company_id'  => $company_id,
                'entity_type' => $entity_type,
                'external_id' => $external_id,
                'local_id'    => $local_id,
                'entity_name' => $entity_name,
            ],
            false,
            ['local_id', 'entity_name']
        );
    }

    /**
     * Stores the timestamp of the last successful full entity update.
     *
     * @param int      $company_id  Company identifier
     * @param string   $entity_type Entity type
     * @param string   $external_id External entity identifier
     * @param int|null $timestamp   Update timestamp
     *
     * @return bool
     */
    public function markFullyUpdated($company_id, $entity_type, $external_id, $timestamp = null)
    {
        return $this->updateTimestamp(
            $company_id,
            $entity_type,
            $external_id,
            'full_updated_timestamp',
            $timestamp
        );
    }

    /**
     * Stores the timestamp of the last successful price and stock actualization.
     *
     * @param int      $company_id  Company identifier
     * @param string   $entity_type Entity type
     * @param string   $external_id External entity identifier
     * @param int|null $timestamp   Actualization timestamp
     *
     * @return bool
     */
    public function markActualized($company_id, $entity_type, $external_id, $timestamp = null)
    {
        return $this->updateTimestamp(
            $company_id,
            $entity_type,
            $external_id,
            'actualized_timestamp',
            $timestamp
        );
    }

    /**
     * Removes an entity mapping by its external identifier.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Entity type
     * @param string $external_id External entity identifier
     *
     * @return bool
     */
    public function remove($company_id, $entity_type, $external_id)
    {
        return (bool) $this->database->query(
            'DELETE FROM ?:?p WHERE company_id = ?i AND entity_type = ?s AND external_id = ?s',
            self::TABLE_NAME,
            $company_id,
            $entity_type,
            $external_id
        );
    }

    /**
     * Updates one of the mapping timestamps.
     *
     * @param int      $company_id  Company identifier
     * @param string   $entity_type Entity type
     * @param string   $external_id External entity identifier
     * @param string   $field       Timestamp field
     * @param int|null $timestamp   Timestamp value
     *
     * @return bool
     */
    private function updateTimestamp($company_id, $entity_type, $external_id, $field, $timestamp)
    {
        return (bool) $this->database->query(
            'UPDATE ?:?p SET ?f = ?i WHERE company_id = ?i AND entity_type = ?s AND external_id = ?s',
            self::TABLE_NAME,
            $field,
            $timestamp === null ? TIME : $timestamp,
            $company_id,
            $entity_type,
            $external_id
        );
    }
}
