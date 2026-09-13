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
     * Initializes the entity mapping repository.
     *
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
     * Finds mappings for a batch of local entities.
     *
     * @param int        $company_id  Company identifier
     * @param string     $entity_type Entity type
     * @param array<int> $local_ids   Local entity identifiers
     *
     * @return array<int, array<string, int|string>> Mappings indexed by local identifier
     */
    public function findByLocalIds($company_id, $entity_type, array $local_ids)
    {
        if (!$local_ids) {
            return [];
        }

        return $this->database->getHash(
            'SELECT * FROM ?:?p WHERE company_id = ?i AND entity_type = ?s AND local_id IN (?n)',
            'local_id',
            self::TABLE_NAME,
            $company_id,
            $entity_type,
            $local_ids
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
     * Finds mapped entities that are absent from the latest complete snapshot.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Entity type
     *
     * @return array<string, array<string, int|string>> Mappings indexed by external identifier
     */
    public function findPendingArchiving($company_id, $entity_type)
    {
        return $this->database->getHash(
            'SELECT * FROM ?:?p WHERE company_id = ?i AND entity_type = ?s'
            . ' AND needs_archiving = ?s AND local_id > ?i',
            'external_id',
            self::TABLE_NAME,
            $company_id,
            $entity_type,
            'Y',
            0
        );
    }

    /**
     * Clears the archiving mark after a mapped entity has been archived.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Entity type
     * @param string $external_id External entity identifier
     *
     * @return bool
     */
    public function clearArchivingMark($company_id, $entity_type, $external_id)
    {
        return (bool) $this->database->query(
            'UPDATE ?:?p SET needs_archiving = ?s WHERE company_id = ?i AND entity_type = ?s AND external_id = ?s',
            self::TABLE_NAME,
            'N',
            $company_id,
            $entity_type,
            $external_id
        );
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
     * Creates or updates mappings in one batch without changing update timestamps.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Entity type
     * @param array  $mappings    Mapping data indexed by external identifier
     *
     * @psalm-param array<string, array{local_id: int, entity_name: string}> $mappings
     *
     * @return int
     */
    public function saveMany($company_id, $entity_type, array $mappings)
    {
        $records = [];

        foreach ($mappings as $external_id => $mapping) {
            $records[] = [
                'company_id'  => $company_id,
                'entity_type' => $entity_type,
                'external_id' => $external_id,
                'local_id'    => $mapping['local_id'],
                'entity_name' => $mapping['entity_name'],
            ];
        }

        return $records
            ? $this->database->replaceInto(
                self::TABLE_NAME,
                $records,
                true,
                ['local_id', 'entity_name']
            )
            : 0;
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
     * Marks a batch of entities as fully updated and not awaiting archiving.
     *
     * @param int           $company_id   Company identifier
     * @param string        $entity_type  Entity type
     * @param array<string> $external_ids External entity identifiers
     * @param int|null      $timestamp    Update timestamp
     *
     * @return bool
     */
    public function markFullyUpdatedMany($company_id, $entity_type, array $external_ids, $timestamp = null)
    {
        return $this->updateTimestampMany(
            $company_id,
            $entity_type,
            $external_ids,
            'full_updated_timestamp',
            $timestamp
        );
    }

    /**
     * Marks a batch of entities as actualized and not awaiting archiving.
     *
     * @param int           $company_id   Company identifier
     * @param string        $entity_type  Entity type
     * @param array<string> $external_ids External entity identifiers
     * @param int|null      $timestamp    Update timestamp
     *
     * @return bool
     */
    public function markActualizedMany($company_id, $entity_type, array $external_ids, $timestamp = null)
    {
        return $this->updateTimestampMany(
            $company_id,
            $entity_type,
            $external_ids,
            'actualized_timestamp',
            $timestamp
        );
    }

    /**
     * Marks mapped entities absent from a completed full import for later archiving.
     *
     * @param int        $company_id  Company identifier
     * @param string     $entity_type Entity type
     * @param array<int> $import_ids  Import identifiers containing the full source snapshot
     *
     * @return bool
     */
    public function markMissingForArchiving($company_id, $entity_type, array $import_ids)
    {
        if (!$import_ids) {
            return false;
        }

        return $this->database->query(
            'UPDATE ?:?p AS mappings SET mappings.needs_archiving = IF(EXISTS ('
            . 'SELECT 1 FROM ?:?p AS entities WHERE entities.import_id IN (?n)'
            . ' AND entities.entity_type = ?s AND entities.entity_id = mappings.external_id'
            . '), ?s, ?s)'
            . ' WHERE mappings.company_id = ?i AND mappings.entity_type = ?s',
            self::TABLE_NAME,
            ImportEntityRepository::TABLE_NAME,
            $import_ids,
            $entity_type,
            'N',
            'Y',
            $company_id,
            $entity_type
        ) !== false;
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

    /**
     * Updates one timestamp for a batch of mappings.
     *
     * @param int           $company_id   Company identifier
     * @param string        $entity_type  Entity type
     * @param array<string> $external_ids External entity identifiers
     * @param string        $field        Timestamp field
     * @param int|null      $timestamp    Timestamp value
     *
     * @return bool
     */
    private function updateTimestampMany(
        $company_id,
        $entity_type,
        array $external_ids,
        $field,
        $timestamp
    ) {
        if (!$external_ids) {
            return true;
        }

        return $this->database->query(
            'UPDATE ?:?p SET ?f = ?i, needs_archiving = ?s'
            . ' WHERE company_id = ?i AND entity_type = ?s AND external_id IN (?a)',
            self::TABLE_NAME,
            $field,
            $timestamp === null ? TIME : $timestamp,
            'N',
            $company_id,
            $entity_type,
            $external_ids
        ) !== false;
    }
}
