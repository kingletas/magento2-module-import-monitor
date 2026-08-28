<?php
/**
 * ProductStateTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use PHPUnit\Framework\TestCase;

class ProductStateTest extends TestCase
{
    public function testASellableProductHasNoFailure(): void
    {
        $state = $this->state();

        self::assertNull($state->salabilityFailure());
        self::assertTrue($state->permitsSale());
    }

    /**
     * `exists: false` is a different fault from a row whose fields happen to be
     * zero.
     */
    public function testAnAbsentProductIsMissingRatherThanUnsellable(): void
    {
        $state = new ProductState(false);

        self::assertSame(DiscrepancyReason::Missing, $state->salabilityFailure());
        self::assertFalse($state->permitsSale());
    }

    public function testAPresentButUnsellableProductIsNotReportedAsMissing(): void
    {
        $state = $this->state(isEnabled: false);

        self::assertSame(DiscrepancyReason::Disabled, $state->salabilityFailure());
    }

    public function testANonSimpleProductIsReportedAsSuch(): void
    {
        self::assertSame(DiscrepancyReason::NotSimple, $this->state(isSimple: false)->salabilityFailure());
    }

    public function testAProductWithNoSellableStockIsOutOfStock(): void
    {
        self::assertSame(DiscrepancyReason::OutOfStock, $this->state(isSalable: false)->salabilityFailure());
    }

    /**
     * Worst first: a product that is both missing and unsellable is reported as
     * missing, because creating it is the step that has to happen first.
     */
    public function testTheFirstAndWorstFailureIsTheOneReported(): void
    {
        $everythingWrong = new ProductState(false, false, false, false);

        self::assertSame(DiscrepancyReason::Missing, $everythingWrong->salabilityFailure());
        self::assertSame(
            DiscrepancyReason::NotSimple,
            (new ProductState(true, false, false, false))->salabilityFailure()
        );
        self::assertSame(
            DiscrepancyReason::Disabled,
            (new ProductState(true, true, false, false))->salabilityFailure()
        );
    }

    /**
     * A disagreement in the status codes is a separate check - the product is
     * sellable, so nothing here reports a failure over it.
     */
    public function testAStatusMismatchIsNotASalabilityFailureOnItsOwn(): void
    {
        $state = $this->state(siteStatus: 'W', masterStatus: 'WITHDRAWN');

        self::assertNull($state->salabilityFailure());
        self::assertTrue($state->permitsSale());
    }

    /**
     * Everything but `exists` defaults to the unsellable answer.
     */
    public function testTheDefaultsAreTheUnsellableOnes(): void
    {
        $state = new ProductState(true);

        self::assertFalse($state->permitsSale());
        self::assertSame('', $state->siteStatus);
        self::assertSame(0.0, $state->quantity);
    }

    private function state(
        bool $isSimple = true,
        bool $isEnabled = true,
        bool $isSalable = true,
        string $siteStatus = 'A',
        string $masterStatus = 'ACTIVE',
        float $quantity = 12.0
    ): ProductState {
        return new ProductState(true, $isSimple, $isEnabled, $isSalable, $siteStatus, $masterStatus, $quantity);
    }
}
