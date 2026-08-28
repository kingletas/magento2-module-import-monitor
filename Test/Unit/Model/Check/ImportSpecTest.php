<?php
/**
 * ImportSpecTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Check;

use Commerce\ImportMonitor\Model\Check\ImportSpec;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ImportSpecTest extends TestCase
{
    public function testItNamesTheTaskAndWhatIsExpectedOfIt(): void
    {
        $spec = new ImportSpec('supplier_inventory', 'Supplier inventory', 4, 6);

        self::assertSame('supplier_inventory', $spec->taskCode);
        self::assertSame('Supplier inventory', $spec->label);
        self::assertSame(4, $spec->expectedRuns);
        self::assertSame(6, $spec->dueFromHour);
    }

    /**
     * One run a day is the common case, so a spec declared in di.xml only has
     * to state the code and the label.
     */
    public function testAnImportIsExpectedOnceADayByDefault(): void
    {
        self::assertSame(1, (new ImportSpec('supplier_inventory', 'Supplier inventory'))->expectedRuns);
    }

    /**
     * An import inside its window is not late; a zero hour means expected from
     * midnight.
     */
    public function testAnImportIsDueFromMidnightUnlessAWindowIsDeclared(): void
    {
        self::assertSame(0, (new ImportSpec('supplier_inventory', 'Supplier inventory'))->dueFromHour);
    }

    /**
     * The label is what an operator reads in an alert, and it is separate from
     * the code so the code can stay a machine identifier.
     */
    public function testTheLabelIsSeparateFromTheTaskCode(): void
    {
        $spec = new ImportSpec('supplier_inventory', 'Overnight supplier inventory feed');

        self::assertNotSame($spec->taskCode, $spec->label);
    }

    public function testItIsImmutable(): void
    {
        foreach (['taskCode', 'label', 'expectedRuns', 'dueFromHour'] as $property) {
            self::assertTrue(
                (new ReflectionProperty(ImportSpec::class, $property))->isReadOnly(),
                sprintf('%s must be read-only.', $property)
            );
        }
    }
}
