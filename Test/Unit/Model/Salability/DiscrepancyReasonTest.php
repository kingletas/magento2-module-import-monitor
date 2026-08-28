<?php
/**
 * DiscrepancyReasonTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use PHPUnit\Framework\TestCase;

class DiscrepancyReasonTest extends TestCase
{
    /**
     * The value is written into the alert table and the CSV, so it is a stored
     * format.
     */
    public function testTheStoredValuesAreStable(): void
    {
        self::assertSame(
            ['MISSING', 'NOT_SIMPLE', 'DISABLED', 'OUT_OF_STOCK', 'STATUS_MISMATCH'],
            array_column(DiscrepancyReason::cases(), 'value')
        );
    }

    /**
     * Ordered worst-first: absence is a harder failure than a disabled product,
     * which is harder than out of stock.
     */
    public function testTheCasesAreOrderedWorstFirst(): void
    {
        self::assertSame(DiscrepancyReason::Missing, DiscrepancyReason::cases()[0]);
        self::assertSame(DiscrepancyReason::StatusMismatch, array_slice(DiscrepancyReason::cases(), -1)[0]);
    }

    public function testEveryReasonHasALabelAnOperatorCanRead(): void
    {
        foreach (DiscrepancyReason::cases() as $reason) {
            self::assertNotSame('', $reason->label());
            self::assertNotSame($reason->value, $reason->label());
        }
    }

    /**
     * A missing or non-simple product needs a catalogue change rather than an
     * automated repair.
     */
    public function testACatalogueChangeIsNeverAutoFixed(): void
    {
        self::assertFalse(DiscrepancyReason::Missing->isAutoFixable());
        self::assertFalse(DiscrepancyReason::NotSimple->isAutoFixable());
    }

    /**
     * Everything the module can put right on its own - enabling a product,
     * pushing stock, correcting a status code - is fixable.
     */
    public function testTheRepairableFaultsAreAutoFixable(): void
    {
        self::assertTrue(DiscrepancyReason::Disabled->isAutoFixable());
        self::assertTrue(DiscrepancyReason::OutOfStock->isAutoFixable());
        self::assertTrue(DiscrepancyReason::StatusMismatch->isAutoFixable());
    }

    /**
     * The match is exhaustive, so adding a case is a decision rather than a
     * silent no.
     */
    public function testEveryCaseAnswersBothQuestions(): void
    {
        foreach (DiscrepancyReason::cases() as $reason) {
            self::assertIsBool($reason->isAutoFixable());
            self::assertIsString($reason->label());
        }
    }

    public function testAStoredValueMapsBackToItsCase(): void
    {
        self::assertSame(DiscrepancyReason::OutOfStock, DiscrepancyReason::from('OUT_OF_STOCK'));
        self::assertNull(DiscrepancyReason::tryFrom('SOMETHING_ELSE'));
    }
}
