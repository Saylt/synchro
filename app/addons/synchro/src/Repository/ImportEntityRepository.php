<?php

namespace Tygh\Addons\Synchro\Repository;

use Throwable;
use Tygh\Database\Connection;

/**
 * Stores normalized external API entities before they are imported into CS-Cart.
 */
class ImportEntityRepository
{
    const IMPORTS_TABLE_NAME = 'synchro_imports';

    const TABLE_NAME = 'synchro_import_entities';

    const STATUS_PROCESSING = 'P';

    const STATUS_COMPLETED = 'C';

    const STATUS_FAILED = 'F';

    /**
     * @var \Tygh\Database\Connection
     */
    private $database;

    /**
     * @param \Tygh\Database\Connection $database Database connection
     */
    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * Starts a new isolated import run.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Root entity type
     *
     * @return int Import identifier
     */
    public function startImport($company_id, $entity_type)
    {
        $unfinished_import_ids = $this->database->getColumn(
            'SELECT import_id FROM ?:?p WHERE company_id = ?i AND entity_type = ?s AND status != ?s',
            self::IMPORTS_TABLE_NAME,
            $company_id,
            $entity_type,
            self::STATUS_COMPLETED
        );

        if ($unfinished_import_ids) {
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id IN (?n)',
                self::TABLE_NAME,
                $unfinished_import_ids
            );
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id IN (?n)',
                self::IMPORTS_TABLE_NAME,
                $unfinished_import_ids
            );
        }

        return $this->database->replaceInto(self::IMPORTS_TABLE_NAME, [
            'company_id'   => $company_id,
            'entity_type'  => $entity_type,
            'status'       => self::STATUS_PROCESSING,
            'created_at'   => time(),
            'completed_at' => 0,
        ]);
    }

    /**
     * Publishes a completely processed import run and removes superseded runs.
     *
     * @param int $import_id Import identifier
     *
     * @return bool
     *
     * @throws \Throwable When the import cannot be completed.
     */
    public function completeImport($import_id)
    {
        $import = $this->database->getRow(
            'SELECT company_id, entity_type FROM ?:?p WHERE import_id = ?i',
            self::IMPORTS_TABLE_NAME,
            $import_id
        );

        if (!$import) {
            return false;
        }

        $this->database->beginTransaction();

        try {
            $superseded_import_ids = $this->database->getColumn(
                'SELECT import_id FROM ?:?p'
                . ' WHERE company_id = ?i AND entity_type = ?s AND import_id != ?i',
                self::IMPORTS_TABLE_NAME,
                $import['company_id'],
                $import['entity_type'],
                $import_id
            );

            if ($superseded_import_ids) {
                $this->database->query(
                    'DELETE FROM ?:?p WHERE import_id IN (?n)',
                    self::TABLE_NAME,
                    $superseded_import_ids
                );
                $this->database->query(
                    'DELETE FROM ?:?p WHERE import_id IN (?n)',
                    self::IMPORTS_TABLE_NAME,
                    $superseded_import_ids
                );
            }

            $this->database->query(
                'UPDATE ?:?p SET status = ?s, completed_at = ?i WHERE import_id = ?i',
                self::IMPORTS_TABLE_NAME,
                self::STATUS_COMPLETED,
                time(),
                $import_id
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }

        return true;
    }

    /**
     * Marks an import run as failed and removes its staged entities.
     *
     * @param int $import_id Import identifier
     *
     * @return void
     *
     * @throws \Throwable When the failed import cannot be cleaned up.
     */
    public function failImport($import_id)
    {
        $this->database->beginTransaction();

        try {
            $this->database->query(
                'DELETE FROM ?:?p WHERE import_id = ?i',
                self::TABLE_NAME,
                $import_id
            );
            $this->database->query(
                'UPDATE ?:?p SET status = ?s, completed_at = ?i WHERE import_id = ?i',
                self::IMPORTS_TABLE_NAME,
                self::STATUS_FAILED,
                time(),
                $import_id
            );
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }
    }

    /**
     * Finds the latest completed import run.
     *
     * @param int    $company_id  Company identifier
     * @param string $entity_type Root entity type
     *
     * @return int
     */
    public function findLatestCompletedImportId($company_id, $entity_type)
    {
        return (int) $this->database->getField(
            'SELECT import_id FROM ?:?p'
            . ' WHERE company_id = ?i AND entity_type = ?s AND status = ?s'
            . ' ORDER BY import_id DESC LIMIT 1',
            self::IMPORTS_TABLE_NAME,
            $company_id,
            $entity_type,
            self::STATUS_COMPLETED
        );
    }

    /**
     * Finds all DTOs of the requested type from an import run.
     *
     * @param int    $import_id   Import identifier
     * @param string $entity_type Entity type
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto>
     */
    public function findAllByEntityType($import_id, $entity_type)
    {
        if (!$import_id) {
            return [];
        }

        $serialized_entities = $this->database->getColumn(
            'SELECT entity FROM ?:?p WHERE import_id = ?i AND entity_type = ?s ORDER BY entity_id',
            self::TABLE_NAME,
            $import_id,
            $entity_type
        );
        $entities = [];

        foreach ($serialized_entities as $serialized_entity) {
            /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
            $entity = unserialize($serialized_entity);
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Finds stored DTOs by entity type and identifiers.
     *
     * @param int                      $import_id   Import identifier
     * @param string                   $entity_type Entity type
     * @param array<array-key, string> $entity_ids  Entity identifiers
     *
     * @return array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto>
     */
    public function findByEntityIds($import_id, $entity_type, array $entity_ids)
    {
        if (!$entity_ids) {
            return [];
        }

        $serialized_entities = $this->database->getColumn(
            'SELECT entity FROM ?:?p WHERE import_id = ?i AND entity_type = ?s AND entity_id IN (?a)',
            self::TABLE_NAME,
            $import_id,
            $entity_type,
            $entity_ids
        );
        $entities = [];

        foreach ($serialized_entities as $serialized_entity) {
            /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
            $entity = unserialize($serialized_entity);
            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Saves normalized entities in a batch.
     *
     * @param int                                                           $import_id  Import identifier
     * @param int                                                           $company_id Company identifier
     * @param array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto> $entities   Entity DTO instances
     *
     * @return int
     */
    public function batchSave($import_id, $company_id, array $entities)
    {
        $timestamp = time();
        $records = [];

        /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
        foreach ($entities as $entity) {
            $records[] = [
                'import_id'  => $import_id,
                'company_id' => $company_id,
                'entity_id'  => $entity->getEntityId(),
                'entity_type' => $entity->getEntityType(),
                'entity'     => serialize($entity),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if (!$records) {
            return 0;
        }

        return $this->database->replaceInto(
            self::TABLE_NAME,
            $records,
            true,
            ['entity', 'updated_at']
        );
    }
}
