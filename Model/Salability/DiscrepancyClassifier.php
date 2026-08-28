<?php
/**
 * DiscrepancyClassifier.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

/**
 * Decides whether a supplier-sellable SKU disagrees with Magento.
 */
class DiscrepancyClassifier
{
    public function __construct(
        private readonly DiscrepancyDescriber $describer
    ) {
    }

    public function classify(SupplierSku $supplier, ProductState $magento): ?Discrepancy
    {
        $reason = $magento->salabilityFailure() ?? $this->statusMismatch($supplier, $magento);

        if ($reason === null) {
            return null;
        }

        return new Discrepancy(
            $supplier,
            $magento,
            $reason,
            $this->describer->describe($supplier, $magento, $reason)
        );
    }

    private function statusMismatch(SupplierSku $supplier, ProductState $magento): ?DiscrepancyReason
    {
        // Compared trimmed and case-insensitively: feed and catalogue differ in
        // casing, not meaning.
        $sameSite = strcasecmp(trim($supplier->siteStatus), trim($magento->siteStatus)) === 0;
        $sameMaster = strcasecmp(trim($supplier->masterStatus), trim($magento->masterStatus)) === 0;

        return $sameSite && $sameMaster ? null : DiscrepancyReason::StatusMismatch;
    }
}
