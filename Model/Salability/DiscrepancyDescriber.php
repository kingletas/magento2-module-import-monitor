<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

/**
 * Writes the one-line explanation shown in the grid and the CSV report.
 */
class DiscrepancyDescriber
{
    public function describe(
        SupplierSku $supplier,
        ProductState $magento,
        DiscrepancyReason $reason
    ): string {
        return match ($reason) {
            DiscrepancyReason::Missing => sprintf(
                'The supplier lists %s as sellable (%s available) but it does not exist in Magento.',
                $supplier->sku,
                $this->quantity($supplier->quantity)
            ),
            DiscrepancyReason::NotSimple => sprintf(
                '%s is sellable at the supplier but is not a simple product in Magento, so its stock cannot be'
                . ' reconciled directly.',
                $supplier->sku
            ),
            DiscrepancyReason::Disabled => sprintf(
                '%s is sellable at the supplier (%s available) but is disabled in Magento.',
                $supplier->sku,
                $this->quantity($supplier->quantity)
            ),
            DiscrepancyReason::OutOfStock => sprintf(
                '%s has %s available at the supplier but Magento shows %s sellable.',
                $supplier->sku,
                $this->quantity($supplier->quantity),
                $this->quantity($magento->quantity)
            ),
            DiscrepancyReason::StatusMismatch => sprintf(
                '%s is sellable in both, but the status codes disagree: supplier says %s/%s, Magento says %s/%s.',
                $supplier->sku,
                $this->status($supplier->siteStatus),
                $this->status($supplier->masterStatus),
                $this->status($magento->siteStatus),
                $this->status($magento->masterStatus)
            ),
        };
    }

    private function quantity(float $quantity): string
    {
        // Rendered without a trailing ".0" for whole numbers, which is how
        // stock is normally spoken about.
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function status(string $status): string
    {
        return trim($status) === '' ? '(blank)' : trim($status);
    }
}
