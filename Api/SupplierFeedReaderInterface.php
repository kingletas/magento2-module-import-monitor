<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Api;

use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use Generator;

/**
 * Reads sellable SKUs out of a supplier feed.
 */
interface SupplierFeedReaderInterface
{
    /**
     * @param string $path Absolute path to the feed file.
     *
     * @return Generator<int, SupplierSku>
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function read(string $path): Generator;
}
