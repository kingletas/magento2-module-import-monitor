<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Unbound;

use Commerce\ImportMonitor\Api\ImportTaskSourceInterface;
use Commerce\ImportMonitor\Model\Unbound\UnboundImportTaskSource;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * The placeholder that keeps the module constructable without an import source.
 */
final class UnboundImportTaskSourceTest extends TestCase
{
    public function testItSatisfiesTheInterfaceItStandsInFor(): void
    {
        self::assertInstanceOf(
            ImportTaskSourceInterface::class,
            new UnboundImportTaskSource(new RecordingLogger())
        );
    }

    public function testItThrowsRatherThanReturningNothing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No import task source is bound');

        (new UnboundImportTaskSource(new RecordingLogger()))->getLatestRuns(['nightly'], '2026-01-01 00:00:00');
    }

    /**
     * The message has to name the interface.
     */
    public function testTheMessageNamesTheInterfaceToBind(): void
    {
        self::assertStringContainsString(
            ImportTaskSourceInterface::class,
            UnboundImportTaskSource::MESSAGE
        );
    }

    public function testItWarnsOncePerProcessRatherThanPerCall(): void
    {
        $logger = new RecordingLogger();
        $source = new UnboundImportTaskSource($logger);

        foreach (range(1, 5) as $ignored) {
            try {
                $source->getLatestRuns(['nightly'], '2026-01-01 00:00:00');
            } catch (LocalizedException) {
                // The throw is the subject of another test; here it is noise.
            }
        }

        self::assertCount(1, $logger->warnings);
    }
}
