<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Fake;

use Commerce\ImportMonitor\Api\SupplierFeedReaderInterface;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use Generator;

/**
 * Yields a fixed list of supplier SKUs, one at a time.
 */
class ScriptedFeedReader implements SupplierFeedReaderInterface
{
    /**
     * @param SupplierSku[] $skus
     */
    public function __construct(private readonly array $skus)
    {
    }

    public function read(string $path): Generator
    {
        yield from $this->skus;
    }
}
