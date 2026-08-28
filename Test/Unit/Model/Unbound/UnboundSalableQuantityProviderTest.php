<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Unbound;

use Commerce\ImportMonitor\Api\SalableQuantityProviderInterface;
use Commerce\ImportMonitor\Model\Unbound\UnboundSalableQuantityProvider;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * The placeholder that keeps the module constructable without a stock source.
 */
final class UnboundSalableQuantityProviderTest extends TestCase
{
    public function testItSatisfiesTheInterfaceItStandsInFor(): void
    {
        self::assertInstanceOf(
            SalableQuantityProviderInterface::class,
            new UnboundSalableQuantityProvider(new RecordingLogger())
        );
    }

    public function testBothOfItsMethodsRefuse(): void
    {
        $provider = new UnboundSalableQuantityProvider(new RecordingLogger());
        $thrown = 0;

        foreach (['getSalableQuantities', 'getSalabilityStatuses'] as $method) {
            try {
                $provider->{$method}(['SKU-1']);
            } catch (LocalizedException $e) {
                $thrown++;
                self::assertStringContainsString('No salable quantity provider is bound', $e->getMessage());
            }
        }

        self::assertSame(2, $thrown, 'Both methods must refuse, not just the one the reconciler happens to call.');
    }

    /**
     * The message has to name the interface.
     */
    public function testTheMessageNamesTheInterfaceToBind(): void
    {
        self::assertStringContainsString(
            SalableQuantityProviderInterface::class,
            UnboundSalableQuantityProvider::MESSAGE
        );
    }

    public function testItWarnsOncePerProcessRatherThanPerCall(): void
    {
        $logger = new RecordingLogger();
        $provider = new UnboundSalableQuantityProvider($logger);

        foreach (range(1, 5) as $ignored) {
            try {
                $provider->getSalableQuantities(['SKU-1']);
            } catch (LocalizedException) {
                // The throw is the subject of another test; here it is noise.
            }
        }

        self::assertCount(1, $logger->warnings);
    }
}
