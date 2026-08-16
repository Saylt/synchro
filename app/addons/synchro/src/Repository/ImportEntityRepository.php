<?php

namespace Tygh\Addons\Synchro\Repository;

use Tygh\Database\Connection;

/**
 * Stores normalized external API entities before they are imported into CS-Cart.
 */
class ImportEntityRepository
{
    const TABLE_NAME = 'synchro_import_entities';

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
     * Saves normalized entities in a batch.
     *
     * @param int                                                           $company_id Company identifier
     * @param array<array-key, \Tygh\Addons\Synchro\Dto\RepresentEntityDto> $entities   Entity DTO instances
     *
     * @return int
     */
    public function batchSave($company_id, array $entities)
    {
        $timestamp = time();
        $records = [];

        /** @var \Tygh\Addons\Synchro\Dto\RepresentEntityDto $entity */
        foreach ($entities as $entity) {
            $records[] = [
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
