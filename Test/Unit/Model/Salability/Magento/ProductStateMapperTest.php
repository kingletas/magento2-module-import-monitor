<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
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

        $this->assertTrue($state->exists);
        $this->assertTrue($state->isSimple);
        $this->assertTrue($state->isEnabled);
        $this->assertTrue($state->isSalable);
        $this->assertSame('A', $state->siteStatus);
        $this->assertSame('M', $state->masterStatus);
        $this->assertSame(5.0, $state->quantity);
        $this->assertTrue($state->permitsSale());
    }

    public function testSalabilityAndQuantityComeFromTheProviderNotTheRow(): void
    {
        $state = $this->mapper->fromRow(
            ['type_id' => 'simple', 'status' => 1, 'is_salable' => 1, 'quantity' => 99],
            false,
            0.0
        );

        $this->assertFalse($state->isSalable);
        $this->assertSame(0.0, $state->quantity);
    }

    public function testAnEmptyRowStillExistsButCannotBeSold(): void
    {
        $state = $this->mapper->fromRow([], false, 0.0);

        $this->assertTrue($state->exists);
        $this->assertFalse($state->isSimple);
        $this->assertSame(DiscrepancyReason::NotSimple, $state->salabilityFailure());
    }

    /**
     * A mapped row is a product that cannot be sold; an absent one is a product
     * that is not there.
     */
    public function testAMappedRowIsNeverConfusedWithAnAbsentProduct(): void
    {
        $mapped = $this->mapper->fromRow([], false, 0.0);
        $absent = new ProductState(exists: false);

        $this->assertNotSame($absent->salabilityFailure(), $mapped->salabilityFailure());
        $this->assertSame(DiscrepancyReason::Missing, $absent->salabilityFailure());
    }

    public function testAnyStatusOtherThanEnabledReadsAsDisabled(): void
    {
        $state = $this->mapper->fromRow(['type_id' => 'simple', 'status' => 2], true, 1.0);

        $this->assertFalse($state->isEnabled);
        $this->assertSame(DiscrepancyReason::Disabled, $state->salabilityFailure());
    }
}
