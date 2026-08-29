<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability\Magento;

use Commerce\ImportMonitor\Model\Salability\ProductState;
use Magento\Catalog\Model\Product\Type as ProductType;

/**
 * Turns one `catalog_product_entity` row into a {@see ProductState}.
 */
class ProductStateMapper
{
    /**
     * @param array<string, mixed> $row       As returned by the loader's query.
     * @param bool                 $isSalable From the salable-quantity provider,
     *                                        not from the row.
     */
    public function fromRow(array $row, bool $isSalable, float $quantity): ProductState
    {
        return new ProductState(
            exists: true,
            isSimple: (string) ($row['type_id'] ?? '') === ProductType::TYPE_SIMPLE,
            isEnabled: (int) ($row['status'] ?? 0) === 1,
            isSalable: $isSalable,
            siteStatus: (string) ($row['site_status'] ?? ''),
            masterStatus: (string) ($row['master_status'] ?? ''),
            quantity: $quantity
        );
    }
}
