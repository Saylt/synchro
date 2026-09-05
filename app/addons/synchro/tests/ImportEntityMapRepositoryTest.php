<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

use Tygh\Addons\Synchro\Repository\ImportEntityMapRepository;
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
