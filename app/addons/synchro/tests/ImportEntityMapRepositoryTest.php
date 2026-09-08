<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Database\Connection;
use Tygh\Tests\Unit\ATestCase;

class ImportEntityMapRepositoryTest extends ATestCase
{
    public function testSavesMappingWithoutOverwritingTimestamps()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('replaceInto')
            ->with(
                ImportEntityMapRepository::TABLE_NAME,
                [
                    'company_id'  => 1,
                    'entity_type' => 'products',
                    'external_id' => '1478',
                    'local_id'    => 57,
                    'entity_name' => 'Product',
                ],
                false,
                ['local_id', 'entity_name']
            )
            ->willReturn(57);

        $this->assertSame(
            57,
            (new ImportEntityMapRepository($database))->save(1, 'products', '1478', 57, 'Product')
        );
    }

    public function testSavesMappingsInOneBatchWithoutOverwritingTimestamps()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('replaceInto')
            ->with(
                ImportEntityMapRepository::TABLE_NAME,
                [
                    [
                        'company_id'  => 1,
                        'entity_type' => 'feature_variants',
                        'external_id' => '10#red',
                        'local_id'    => 57,
                        'entity_name' => 'Red',
                    ],
                    [
                        'company_id'  => 1,
                        'entity_type' => 'feature_variants',
                        'external_id' => '11#red',
                        'local_id'    => 57,
                        'entity_name' => 'Red',
                    ],
                ],
                true,
                ['local_id', 'entity_name']
            )
            ->willReturn(2);

        $this->assertSame(
            2,
            (new ImportEntityMapRepository($database))->saveMany(
                1,
                'feature_variants',
                [
                    '10#red' => ['local_id' => 57, 'entity_name' => 'Red'],
                    '11#red' => ['local_id' => 57, 'entity_name' => 'Red'],
                ]
            )
        );
    }

    public function testStoresIndependentUpdateTimestamps()
    {
        $database = $this->createDatabase();
        $database->expects($this->exactly(2))
            ->method('query')
            ->withConsecutive(
                [
                    'UPDATE ?:?p SET ?f = ?i WHERE company_id = ?i AND entity_type = ?s AND external_id = ?s',
                    ImportEntityMapRepository::TABLE_NAME,
                    'full_updated_timestamp',
                    100,
                    1,
                    'products',
                    '1478',
                ],
                [
                    'UPDATE ?:?p SET ?f = ?i WHERE company_id = ?i AND entity_type = ?s AND external_id = ?s',
                    ImportEntityMapRepository::TABLE_NAME,
                    'actualized_timestamp',
                    200,
                    1,
                    'products',
                    '1478',
                ]
            )
            ->willReturn(1);
        $repository = new ImportEntityMapRepository($database);

        $this->assertTrue($repository->markFullyUpdated(1, 'products', '1478', 100));
        $this->assertTrue($repository->markActualized(1, 'products', '1478', 200));
    }

    public function testSynchronizesArchivingMarkWithCompletedSnapshot()
    {
        $database = $this->createDatabase();
        $database->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE ?:?p AS mappings SET mappings.needs_archiving = IF(EXISTS ('
                . 'SELECT 1 FROM ?:?p AS entities WHERE entities.import_id IN (?n)'
                . ' AND entities.entity_type = ?s AND entities.entity_id = mappings.external_id'
                . '), ?s, ?s)'
                . ' WHERE mappings.company_id = ?i AND mappings.entity_type = ?s',
                ImportEntityMapRepository::TABLE_NAME,
                ImportEntityRepository::TABLE_NAME,
                [11, 12],
                'products',
                'N',
                'Y',
                1,
                'products'
            )
            ->willReturn(1);

        $this->assertTrue((new ImportEntityMapRepository($database))->markMissingForArchiving(
            1,
            'products',
            [11, 12]
        ));
    }

    public function testFindsMappingsForProductBatch()
    {
        $database = $this->createDatabase();
        $mappings = [
            '1478' => [
                'external_id' => '1478',
                'local_id'    => 57,
            ],
        ];
        $database->expects($this->once())
            ->method('getHash')
            ->with(
                'SELECT * FROM ?:?p WHERE company_id = ?i AND entity_type = ?s AND external_id IN (?a)',
                'external_id',
                ImportEntityMapRepository::TABLE_NAME,
                1,
                'products',
                ['1478', '4954']
            )
            ->willReturn($mappings);

        $this->assertSame(
            $mappings,
            (new ImportEntityMapRepository($database))->findByExternalIds(
                1,
                'products',
                ['1478', '4954']
            )
        );
    }

    public function testFindsAllMappingsByEntityType()
    {
        $database = $this->createDatabase();
        $mappings = [
            '1478' => [
                'external_id' => '1478',
                'local_id'    => 57,
            ],
        ];
        $database->expects($this->once())
            ->method('getHash')
            ->with(
                'SELECT * FROM ?:?p WHERE company_id = ?i AND entity_type = ?s',
                'external_id',
                ImportEntityMapRepository::TABLE_NAME,
                1,
                'categories'
            )
            ->willReturn($mappings);

        $this->assertSame(
            $mappings,
            (new ImportEntityMapRepository($database))->findAllByEntityType(1, 'categories')
        );
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|\Tygh\Database\Connection
     */
    private function createDatabase()
    {
        return $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->setMethods(['getHash', 'getRow', 'query', 'replaceInto'])
            ->getMock();
    }
}
