<?php
/**
 * ProductStateMapperTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability\Magento;

use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use Commerce\ImportMonitor\Model\Salability\Magento\ProductStateMapper;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use PHPUnit\Framework\TestCase;

class ProductStateMapperTest extends TestCase
{
    private ProductStateMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ProductStateMapper();
    }

    public function testAMappedRowAlwaysExists(): void
    {
        $state = $this->mapper->fromRow(
            ['type_id' => 'simple', 'status' => 1, 'site_status' => 'A', 'master_status' => 'M'],
            true,
            5.0
        );

        self::assertTrue($state->exists);
        self::assertTrue($state->isSimple);
        self::assertTrue($state->isEnabled);
        self::assertTrue($state->isSalable);
        self::assertSame('A', $state->siteStatus);
        self::assertSame('M', $state->masterStatus);
        self::assertSame(5.0, $state->quantity);
        self::assertTrue($state->permitsSale());
    }

    public function testSalabilityAndQuantityComeFromTheProviderNotTheRow(): void
    {
        $state = $this->mapper->fromRow(
            ['type_id' => 'simple', 'status' => 1, 'is_salable' => 1, 'quantity' => 99],
            false,
            0.0
        );

        self::assertFalse($state->isSalable);
        self::assertSame(0.0, $state->quantity);
    }

    public function testAnEmptyRowStillExistsButCannotBeSold(): void
    {
        $state = $this->mapper->fromRow([], false, 0.0);

        self::assertTrue($state->exists);
        self::assertFalse($state->isSimple);
        self::assertSame(DiscrepancyReason::NotSimple, $state->salabilityFailure());
    }

    /**
     * A mapped row is a product that cannot be sold; an absent one is a product
     * that is not there.
     */
    public function testAMappedRowIsNeverConfusedWithAnAbsentProduct(): void
    {
        $mapped = $this->mapper->fromRow([], false, 0.0);
        $absent = new ProductState(exists: false);

        self::assertNotSame($absent->salabilityFailure(), $mapped->salabilityFailure());
        self::assertSame(DiscrepancyReason::Missing, $absent->salabilityFailure());
    }

    public function testAnyStatusOtherThanEnabledReadsAsDisabled(): void
    {
        $state = $this->mapper->fromRow(['type_id' => 'simple', 'status' => 2], true, 1.0);

        self::assertFalse($state->isEnabled);
        self::assertSame(DiscrepancyReason::Disabled, $state->salabilityFailure());
    }
}
