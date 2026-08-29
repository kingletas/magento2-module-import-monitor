<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

/**
 * Why a supplier-sellable SKU cannot be sold in Magento.
 */
enum DiscrepancyReason: string
{
    case Missing = 'MISSING';
    case NotSimple = 'NOT_SIMPLE';
    case Disabled = 'DISABLED';
    case OutOfStock = 'OUT_OF_STOCK';
    case StatusMismatch = 'STATUS_MISMATCH';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Missing from Magento',
            self::NotSimple => 'Not a simple product in Magento',
            self::Disabled => 'Disabled in Magento',
            self::OutOfStock => 'No sellable stock in Magento',
            self::StatusMismatch => 'Supplier and Magento status codes disagree',
        };
    }

    /**
     * Whether this module can repair the discrepancy itself.
     */
    public function isAutoFixable(): bool
    {
        return match ($this) {
            self::Disabled, self::OutOfStock, self::StatusMismatch => true,
            self::Missing, self::NotSimple => false,
        };
    }
}
