<?php

namespace Tygh\Addons\Synchro\Tests\Unit;

use Tygh\Addons\Synchro\Dto\ProductFeatureDto;
use Tygh\Addons\Synchro\Dto\ProductFeatureVariantDto;
use Tygh\Addons\Synchro\ImportedProductFeatureReader;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Tests\Unit\ATestCase;

class ImportedProductFeatureReaderTest extends ATestCase
{
    public function testMergesFeatureVariantsFromEveryCompletedChild()
    {
        $first = $this->createFeature(7, 'Power', '100');
        $second = $this->createFeature(7, 'Power', '200');
        $repository = $this->getMockBuilder(ImportEntityRepository::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'findLatestMappableParentId',
                'findCompletedChildIds',
                'findAllByEntityTypeFromImports',
            ])
            ->getMock();
        $repository->expects($this->once())
            ->method('findLatestMappableParentId')
            ->with(4, 'products')
            ->willReturn(10);
        $repository->expects($this->once())
            ->method('findCompletedChildIds')
            ->with(10)
            ->willReturn([11, 12]);
        $repository->expects($this->once())
            ->method('findAllByEntityTypeFromImports')
            ->with([11, 12], ProductFeatureDto::ENTITY_TYPE, false)
            ->willReturn([$first, $second]);

        list($parent_import_id, $features) = (new ImportedProductFeatureReader($repository))->readLatest(4);

        $this->assertSame(10, $parent_import_id);
        $this->assertCount(1, $features);
        $this->assertSame(['7#100', '7#200'], array_keys($features[0]->variants));
    }

    public function testReadsFeaturesFromSpecifiedParentImport()
    {
        $repository = $this->getMockBuilder(ImportEntityRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findCompletedChildIds', 'findAllByEntityTypeFromImports'])
            ->getMock();
        $repository->expects($this->once())
            ->method('findCompletedChildIds')
            ->with(20)
            ->willReturn([21, 22]);
        $repository->expects($this->once())
            ->method('findAllByEntityTypeFromImports')
            ->with([21, 22], ProductFeatureDto::ENTITY_TYPE, false)
            ->willReturn([$this->createFeature(7, 'Power', '100')]);

        $features = (new ImportedProductFeatureReader($repository))->readByParentImportId(20);

        $this->assertCount(1, $features);
        $this->assertSame('7', $features[0]->getEntityId());
    }

    public function testReadsFeaturesFromParentWhenItHasNoChildImports()
    {
        $repository = $this->getMockBuilder(ImportEntityRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findCompletedChildIds', 'findAllByEntityTypeFromImports'])
            ->getMock();
        $repository->expects($this->once())
            ->method('findCompletedChildIds')
            ->with(20)
            ->willReturn([]);
        $repository->expects($this->once())
            ->method('findAllByEntityTypeFromImports')
            ->with([20], ProductFeatureDto::ENTITY_TYPE, false)
            ->willReturn([$this->createFeature(7, 'Power', '100')]);

        $features = (new ImportedProductFeatureReader($repository))->readByParentImportId(20);

        $this->assertCount(1, $features);
        $this->assertSame('7', $features[0]->getEntityId());
    }

    /**
     * @param int    $id    Feature identifier
     * @param string $name  Feature name
     * @param string $value Variant value
     *
     * @return \Tygh\Addons\Synchro\Dto\ProductFeatureDto
     */
    private function createFeature($id, $name, $value)
    {
        $feature = new ProductFeatureDto();
        $feature->id = $id;
        $feature->name = $name;
        $variant = new ProductFeatureVariantDto();
        $variant->id = $id . '#' . $value;
        $variant->feature_id = $id;
        $variant->name = $value;
        $variant->value = $value;
        $feature->variants[$variant->getEntityId()] = $variant;

        return $feature;
    }
}
