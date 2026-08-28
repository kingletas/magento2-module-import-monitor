<?php
/**
 * SupplierSkuTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class SupplierSkuTest extends TestCase
{
    public function testItCarriesBothStatusCodesAndTheQuantity(): void
    {
        $row = new SupplierSku('SKU-1', 'A', 'ACTIVE', 12.0);

        $this->assertSame('SKU-1', $row->sku);
        $this->assertSame('A', $row->siteStatus);
        $this->assertSame('ACTIVE', $row->masterStatus);
        $this->assertSame(12.0, $row->quantity);
    }

    /**
     * The two statuses are separate fields, because the supplier can disagree
     * with itself.
     */
    public function testTheSiteAndMasterStatusesAreIndependent(): void
    {
        $row = new SupplierSku('SKU-1', 'W', 'ACTIVE', 0.0);

        $this->assertNotSame($row->siteStatus, $row->masterStatus);
    }

    /**
     * Quantities are floats, because anything sold by weight or length has
     * fractional stock.
     */
    public function testTheQuantityIsAFloat(): void
    {
        $this->assertSame(2.5, (new SupplierSku('SKU-1', 'A', 'ACTIVE', 2.5))->quantity);
    }

    public function testItIsImmutable(): void
    {
        foreach (['sku', 'siteStatus', 'masterStatus', 'quantity'] as $property) {
            $this->assertTrue(
                (new ReflectionProperty(SupplierSku::class, $property))->isReadOnly(),
                sprintf('%s must be read-only.', $property)
            );
        }
    }
}
