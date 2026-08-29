<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\Discrepancy;
use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use PHPUnit\Framework\TestCase;

class DiscrepancyTest extends TestCase
{
    public function testItCarriesBothSidesOfTheDisagreement(): void
    {
        $discrepancy = $this->discrepancy();

        $this->assertSame('SKU-1', $discrepancy->getSku());
        $this->assertSame(DiscrepancyReason::OutOfStock, $discrepancy->reason);
        $this->assertSame('Magento shows none.', $discrepancy->message);
    }

    /**
     * The report is read by someone deciding what to do, so it has to show what
     * each side said rather than only that they disagreed.
     */
    public function testTheReportRowShowsWhatEachSideClaimed(): void
    {
        $row = $this->discrepancy()->toArray();

        $this->assertSame('A', $row['supplier_site_status']);
        $this->assertSame('ACTIVE', $row['supplier_master_status']);
        $this->assertSame(12.0, $row['supplier_quantity']);
        $this->assertSame('A', $row['magento_site_status']);
        $this->assertSame('ACTIVE', $row['magento_master_status']);
        $this->assertSame(0.0, $row['magento_quantity']);
    }

    /**
     * The row carries the stored value to filter on and the label to read.
     */
    public function testTheRowCarriesBothTheReasonCodeAndItsLabel(): void
    {
        $row = $this->discrepancy()->toArray();

        $this->assertSame(DiscrepancyReason::OutOfStock->value, $row['reason']);
        $this->assertSame(DiscrepancyReason::OutOfStock->label(), $row['reason_label']);
    }

    public function testTheRowIsKeyedTheSameWayForEveryReason(): void
    {
        $keys = null;

        foreach (DiscrepancyReason::cases() as $reason) {
            $row = $this->discrepancy($reason)->toArray();
            $keys ??= array_keys($row);

            $this->assertSame($keys, array_keys($row));
        }
    }

    public function testTheSkuIsTheSupplierRowsSku(): void
    {
        $this->assertSame('SKU-1', $this->discrepancy()->toArray()['sku']);
    }

    /**
     * A missing product still produces a full row, with the Magento side empty.
     */
    public function testAMissingProductStillProducesAFullRow(): void
    {
        $discrepancy = new Discrepancy(
            new SupplierSku('SKU-1', 'A', 'ACTIVE', 12.0),
            new ProductState(false),
            DiscrepancyReason::Missing,
            'It does not exist in Magento.'
        );

        $row = $discrepancy->toArray();

        $this->assertSame('', $row['magento_site_status']);
        $this->assertSame(0.0, $row['magento_quantity']);
    }

    private function discrepancy(DiscrepancyReason $reason = DiscrepancyReason::OutOfStock): Discrepancy
    {
        return new Discrepancy(
            new SupplierSku('SKU-1', 'A', 'ACTIVE', 12.0),
            new ProductState(true, true, true, false, 'A', 'ACTIVE', 0.0),
            $reason,
            'Magento shows none.'
        );
    }
}
