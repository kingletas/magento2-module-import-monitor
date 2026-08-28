<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\Discrepancy;
use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use Commerce\ImportMonitor\Model\Salability\ReconciliationResult;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use PHPUnit\Framework\TestCase;

/**
 * Agreement, an empty feed and a run that could not look all produce an empty
 * list, and differ.
 */
class ReconciliationResultTest extends TestCase
{
    public function testARunThatComparedEverythingAndFoundNothingIsClean(): void
    {
        $result = new ReconciliationResult(examined: 40, discrepancies: [], countsByReason: [], read: 40, skipped: 0);

        $this->assertTrue($result->isClean());
        $this->assertFalse($result->isInconclusive());
        $this->assertStringContainsString('Magento agrees on all of them', $result->summarise());
    }

    public function testAnEmptyFeedIsNotAgreement(): void
    {
        $result = new ReconciliationResult(examined: 0, discrepancies: [], countsByReason: [], read: 0, skipped: 0);

        $this->assertFalse($result->isClean());
        $this->assertTrue($result->isInconclusive());
        $this->assertStringContainsString('No sellable SKUs were found', $result->summarise());
        $this->assertStringNotContainsString('agrees', $result->summarise());
    }

    /**
     * Every chunk failed to load.
     */
    public function testARunWhereNothingCouldBeExaminedIsNotAgreement(): void
    {
        $result = new ReconciliationResult(examined: 0, discrepancies: [], countsByReason: [], read: 500, skipped: 500);

        $this->assertFalse($result->isClean());
        $this->assertTrue($result->isInconclusive());
        $this->assertStringContainsString('None of the 500', $result->summarise());
        $this->assertStringNotContainsString('agrees', $result->summarise());
    }

    /**
     * A partial run cannot report the catalogue as consistent, however many
     * SKUs it did manage: the answer for the rest is unknown, not "fine".
     */
    public function testAPartialRunWithNoDisagreementIsStillInconclusive(): void
    {
        $result = new ReconciliationResult(
            examined: 400,
            discrepancies: [],
            countsByReason: [],
            read: 500,
            skipped: 100
        );

        $this->assertFalse($result->isClean());
        $this->assertTrue($result->isInconclusive());
        $this->assertStringContainsString('100 could not be examined', $result->summarise());
    }

    /**
     * Discrepancies plus a shortfall: the summary has to carry both numbers, or
     * the reader takes the discrepancy count for the whole story.
     */
    public function testAPartialRunWithDisagreementReportsBoth(): void
    {
        $result = new ReconciliationResult(
            examined: 400,
            discrepancies: [$this->discrepancy()],
            countsByReason: [DiscrepancyReason::Missing->value => 1],
            read: 500,
            skipped: 100
        );

        $summary = $result->summarise();

        $this->assertFalse($result->isClean());
        $this->assertStringContainsString('Examined 400', $summary);
        $this->assertStringContainsString('1 disagreed', $summary);
        $this->assertStringContainsString('100 further SKU(s) could not be examined', $summary);
    }

    public function testDisagreementOnACompleteRunIsNotInconclusive(): void
    {
        $result = new ReconciliationResult(
            examined: 40,
            discrepancies: [$this->discrepancy()],
            countsByReason: [DiscrepancyReason::Missing->value => 1],
            read: 40,
            skipped: 0
        );

        $this->assertFalse($result->isClean());
        $this->assertFalse($result->isInconclusive());
    }

    private function discrepancy(): Discrepancy
    {
        return new Discrepancy(
            new SupplierSku('SKU-1', 'A', 'A', 5.0),
            new ProductState(exists: false),
            DiscrepancyReason::Missing,
            'SKU-1 is not in Magento.'
        );
    }
}
