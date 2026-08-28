<?php
/**
 * FeedFilePresenceTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Check;

use Commerce\ImportMonitor\Model\Check\FeedFilePresence;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class FeedFilePresenceTest extends TestCase
{
    public function testItCarriesTheDayItLookedForAndWhatItFound(): void
    {
        $presence = new FeedFilePresence('2026-08-26', ['/feeds/inventory-2026-08-26.csv'], true);

        self::assertSame('2026-08-26', $presence->date);
        self::assertSame(['/feeds/inventory-2026-08-26.csv'], $presence->matches);
        self::assertTrue($presence->hasContent);
        self::assertTrue($presence->wasFound());
    }

    public function testADayWithNoMatchingFileWasNotFound(): void
    {
        self::assertFalse((new FeedFilePresence('2026-08-26', [], false))->wasFound());
    }

    /**
     * A truncated upload is the failure mode that looks like success: the file
     * is there, the glob matches, and the import reads nothing.
     */
    public function testAFileThatIsPresentButEmptyIsFoundAndWithoutContent(): void
    {
        $presence = new FeedFilePresence('2026-08-26', ['/feeds/inventory-2026-08-26.csv'], false);

        self::assertTrue($presence->wasFound());
        self::assertFalse($presence->hasContent);
    }

    /**
     * The alert names every missing part, not just the first.
     */
    public function testEveryMatchIsNamedInTheDescription(): void
    {
        $presence = new FeedFilePresence(
            '2026-08-26',
            ['/feeds/inventory-2026-08-26-a.csv', '/feeds/inventory-2026-08-26-b.csv'],
            true
        );

        self::assertStringContainsString('-a.csv', $presence->describe());
        self::assertStringContainsString('-b.csv', $presence->describe());
    }

    public function testADayWithNoMatchesDescribesItselfAsNothing(): void
    {
        self::assertSame('', (new FeedFilePresence('2026-08-26', [], false))->describe());
    }

    public function testItIsImmutable(): void
    {
        foreach (['date', 'matches', 'hasContent'] as $property) {
            self::assertTrue(
                (new ReflectionProperty(FeedFilePresence::class, $property))->isReadOnly(),
                sprintf('%s must be read-only.', $property)
            );
        }
    }
}
