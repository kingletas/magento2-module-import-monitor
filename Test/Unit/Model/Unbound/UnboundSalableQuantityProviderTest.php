<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Unbound;

use Commerce\ImportMonitor\Api\SalableQuantityProviderInterface;
use Commerce\ImportMonitor\Model\Unbound\UnboundSalableQuantityProvider;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The placeholder that keeps the module constructable without a stock source.
 */
class UnboundSalableQuantityProviderTest extends TestCase
{
    public function testItSatisfiesTheInterfaceItStandsInFor(): void
    {
        $this->assertInstanceOf(
            SalableQuantityProviderInterface::class,
            new UnboundSalableQuantityProvider($this->createMock(LoggerInterface::class))
        );
    }

    public function testBothOfItsMethodsRefuse(): void
    {
        $provider = new UnboundSalableQuantityProvider($this->createMock(LoggerInterface::class));
        $thrown = 0;

        foreach (['getSalableQuantities', 'getSalabilityStatuses'] as $method) {
            try {
                $provider->{$method}(['SKU-1']);
            } catch (LocalizedException $e) {
                $thrown++;
                $this->assertStringContainsString('No salable quantity provider is bound', $e->getMessage());
            }
        }

        $this->assertSame(2, $thrown, 'Both methods must refuse, not just the one the reconciler happens to call.');
    }

    /**
     * The message has to name the interface.
     */
    public function testTheMessageNamesTheInterfaceToBind(): void
    {
        $this->assertStringContainsString(
            SalableQuantityProviderInterface::class,
            UnboundSalableQuantityProvider::MESSAGE
        );
    }

    public function testItWarnsOncePerProcessRatherThanPerCall(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $provider = new UnboundSalableQuantityProvider($logger);

        foreach (range(1, 5) as $ignored) {
            try {
                $provider->getSalableQuantities(['SKU-1']);
            } catch (LocalizedException) {
                // The throw is the subject of another test; here it is noise.
            }
        }

    }
}
