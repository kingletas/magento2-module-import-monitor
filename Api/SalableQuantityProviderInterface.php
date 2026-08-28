<?php
/**
 * SalableQuantityProviderInterface.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Api;

/**
 * Answers "how much of this can Magento actually sell right now?".
 */
interface SalableQuantityProviderInterface
{
    /**
     * @param string[] $skus
     *
     * @return array<string, float> SKU => salable quantity; unknown SKUs omitted.
     */
    public function getSalableQuantities(array $skus, ?int $websiteId = null): array;

    /**
     * @param string[] $skus
     *
     * @return array<string, bool> SKU => whether it is currently sellable.
     */
    public function getSalabilityStatuses(array $skus, ?int $websiteId = null): array;
}
