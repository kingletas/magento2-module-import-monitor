<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Unbound;

use Commerce\ImportMonitor\Api\SalableQuantityProviderInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * The placeholder that stands in until a store binds its own inventory source.
 */
class UnboundSalableQuantityProvider implements SalableQuantityProviderInterface
{
    public const MESSAGE = 'No salable quantity provider is bound. Bind '
        . 'Commerce\\ImportMonitor\\Api\\SalableQuantityProviderInterface to an implementation '
        . 'backed by this store\'s inventory.';

    private bool $warned = false;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string[] $skus
     *
     * @return array<string, float>
     *
     * @throws LocalizedException Always: this is a placeholder, not a provider.
     */
    public function getSalableQuantities(array $skus, ?int $websiteId = null): array
    {
        throw $this->refuse();
    }

    /**
     * @param string[] $skus
     *
     * @return array<string, bool>
     *
     * @throws LocalizedException Always: this is a placeholder, not a provider.
     */
    public function getSalabilityStatuses(array $skus, ?int $websiteId = null): array
    {
        throw $this->refuse();
    }

    private function refuse(): LocalizedException
    {
        if (!$this->warned) {
            $this->warned = true;
            $this->logger->warning('Import monitor: ' . self::MESSAGE);
        }

        return new LocalizedException(
            __(
                'No salable quantity provider is bound. Bind '
                . 'Commerce\\ImportMonitor\\Api\\SalableQuantityProviderInterface to an implementation '
                . 'backed by this store\'s inventory.'
            )
        );
    }
}
