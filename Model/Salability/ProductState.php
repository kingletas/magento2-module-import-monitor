<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

/**
 * What Magento knows about one SKU, as far as selling it is concerned.
 */
class ProductState
{
    public function __construct(
        public readonly bool $exists,
        public readonly bool $isSimple = false,
        public readonly bool $isEnabled = false,
        public readonly bool $isSalable = false,
        public readonly string $siteStatus = '',
        public readonly string $masterStatus = '',
        public readonly float $quantity = 0.0
    ) {
    }

    public function salabilityFailure(): ?DiscrepancyReason
    {
        return match (true) {
            !$this->exists => DiscrepancyReason::Missing,
            !$this->isSimple => DiscrepancyReason::NotSimple,
            !$this->isEnabled => DiscrepancyReason::Disabled,
            !$this->isSalable => DiscrepancyReason::OutOfStock,
            default => null,
        };
    }

    public function permitsSale(): bool
    {
        return $this->salabilityFailure() === null;
    }
}
