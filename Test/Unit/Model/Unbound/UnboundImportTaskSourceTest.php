<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Unbound;

use Commerce\ImportMonitor\Api\ImportTaskSourceInterface;
use Commerce\ImportMonitor\Model\Unbound\UnboundImportTaskSource;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The placeholder that keeps the module constructable without an import source.
 */
class UnboundImportTaskSourceTest extends TestCase
{
    public function testItSatisfiesTheInterfaceItStandsInFor(): void
    {
        $this->assertInstanceOf(
            ImportTaskSourceInterface::class,
            new UnboundImportTaskSource($this->createMock(LoggerInterface::class))
        );
    }

    public function testItThrowsRatherThanReturningNothing(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No import task source is bound');

        $source = new UnboundImportTaskSource($this->createMock(LoggerInterface::class));

        $source->getLatestRuns(['nightly'], '2026-01-01 00:00:00');
    }

    /**
     * The message has to name the interface.
     */
    public function testTheMessageNamesTheInterfaceToBind(): void
    {
        $this->assertStringContainsString(
            ImportTaskSourceInterface::class,
            UnboundImportTaskSource::MESSAGE
        );
    }

    public function testItWarnsOncePerProcessRatherThanPerCall(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $source = new UnboundImportTaskSource($logger);

        foreach (range(1, 5) as $ignored) {
            try {
                $source->getLatestRuns(['nightly'], '2026-01-01 00:00:00');
            } catch (LocalizedException) {
                // The throw is the subject of another test; here it is noise.
            }
        }

    }
}
