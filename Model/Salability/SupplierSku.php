<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

/**
 * One row of the supplier feed, as far as salability is concerned.
 */
class SupplierSku
{
    public function __construct(
        public readonly string $sku,
        public readonly string $siteStatus,
        public readonly string $masterStatus,
        public readonly float $quantity
    ) {
    }
}
