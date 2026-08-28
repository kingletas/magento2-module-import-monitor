<?php
/**
 * Discrepancy.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

/**
 * A flagged SKU together with both sides of the disagreement.
 */
class Discrepancy
{
    public function __construct(
        public readonly SupplierSku $supplier,
        public readonly ProductState $magento,
        public readonly DiscrepancyReason $reason,
        public readonly string $message
    ) {
    }

    public function getSku(): string
    {
        return $this->supplier->sku;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->supplier->sku,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'message' => $this->message,
            'supplier_site_status' => $this->supplier->siteStatus,
            'supplier_master_status' => $this->supplier->masterStatus,
            'supplier_quantity' => $this->supplier->quantity,
            'magento_site_status' => $this->magento->siteStatus,
            'magento_master_status' => $this->magento->masterStatus,
            'magento_quantity' => $this->magento->quantity,
        ];
    }
}
