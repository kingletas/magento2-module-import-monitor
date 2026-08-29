<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\DiscrepancyDescriber;
use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use PHPUnit\Framework\TestCase;

class DiscrepancyDescriberTest extends TestCase
{
    public function testEveryReasonProducesASentenceNamingTheSku(): void
    {
        foreach (DiscrepancyReason::cases() as $reason) {
            $message = $this->describe($reason);

            $this->assertStringContainsString('SKU-1', $message);
            $this->assertStringEndsWith('.', $message);
        }
    }

    public function testAMissingProductIsDescribedAsAbsentRatherThanUnsellable(): void
    {
        $this->assertStringContainsString(
            'does not exist in Magento',
            $this->describe(DiscrepancyReason::Missing)
        );
    }

    public function testADisabledProductIsDescribedAsDisabled(): void
    {
        $this->assertStringContainsString('disabled in Magento', $this->describe(DiscrepancyReason::Disabled));
    }

    /**
     * The out-of-stock message quotes both quantities, because the size of the
     * gap decides.
     */
    public function testTheOutOfStockMessageQuotesBothQuantities(): void
    {
        $message = $this->describe(DiscrepancyReason::OutOfStock, supplierQuantity: 12.0, magentoQuantity: 0.0);

        $this->assertStringContainsString('12 available', $message);
        $this->assertStringContainsString('0 sellable', $message);
    }

    /**
     * Whole quantities read as whole numbers.
     */
    public function testAWholeQuantityIsRenderedWithoutADecimalTail(): void
    {
        $message = $this->describe(DiscrepancyReason::Missing, supplierQuantity: 12.0);

        $this->assertStringContainsString('12 available', $message);
        $this->assertStringNotContainsString('12.00', $message);
    }

    /**
     * Fractional stock is real for anything sold by weight or length, so it is
     * not rounded.
     */
    public function testAFractionalQuantityKeepsItsDecimals(): void
    {
        $this->assertStringContainsString(
            '2.5 available',
            $this->describe(DiscrepancyReason::Missing, supplierQuantity: 2.5)
        );
    }

    public function testAZeroQuantityIsRenderedAsZeroRatherThanAnEmptyString(): void
    {
        $message = $this->describe(DiscrepancyReason::OutOfStock, supplierQuantity: 0.0, magentoQuantity: 0.0);

        $this->assertStringContainsString('0 available', $message);
        $this->assertStringNotContainsString('available.', str_replace('0 available', '', $message));
    }

    public function testTheStatusMismatchMessageQuotesAllFourCodes(): void
    {
        $message = $this->describe(
            DiscrepancyReason::StatusMismatch,
            supplierSite: 'A',
            supplierMaster: 'ACTIVE',
            magentoSite: 'W',
            magentoMaster: 'WITHDRAWN'
        );

        foreach (['A/ACTIVE', 'W/WITHDRAWN'] as $pair) {
            $this->assertStringContainsString($pair, $message);
        }
    }

    /**
     * A blank status is the usual cause of a mismatch - one side never set the
     * column.
     */
    public function testABlankStatusIsNamedRatherThanLeftAsAGap(): void
    {
        $message = $this->describe(
            DiscrepancyReason::StatusMismatch,
            supplierSite: 'A',
            supplierMaster: 'ACTIVE',
            magentoSite: '',
            magentoMaster: '   '
        );

        $this->assertStringContainsString('(blank)/(blank)', $message);
    }

    public function testANonSimpleProductIsDescribedAsUnreconcilable(): void
    {
        $this->assertStringContainsString(
            'not a simple product',
            $this->describe(DiscrepancyReason::NotSimple)
        );
    }

    private function describe(
        DiscrepancyReason $reason,
        float $supplierQuantity = 12.0,
        float $magentoQuantity = 0.0,
        string $supplierSite = 'A',
        string $supplierMaster = 'ACTIVE',
        string $magentoSite = 'A',
        string $magentoMaster = 'ACTIVE'
    ): string {
        return (new DiscrepancyDescriber())->describe(
            new SupplierSku('SKU-1', $supplierSite, $supplierMaster, $supplierQuantity),
            new ProductState(true, true, true, false, $magentoSite, $magentoMaster, $magentoQuantity),
            $reason
        );
    }
}
