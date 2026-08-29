<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability\Feed;

use Commerce\ImportMonitor\Api\SupplierFeedReaderInterface;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use Generator;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

/**
 * Streams sellable SKUs out of a CSV feed.
 */
class CsvFeedReader implements SupplierFeedReaderInterface
{
    /**
     * @param string   $skuColumn          Column holding the SKU.
     * @param string   $siteStatusColumn   Column holding the site status code.
     * @param string   $masterStatusColumn Column holding the master status code.
     * @param string   $quantityColumn     Column holding available quantity.
     * @param string[] $sellableStatuses   Site-status values that mean "sellable".
     * @param bool     $requirePositiveQty Whether quantity must exceed zero to count as sellable.
     */
    public function __construct(
        private readonly FileDriver $fileDriver,
        private readonly string $skuColumn = 'sku',
        private readonly string $siteStatusColumn = 'site_status',
        private readonly string $masterStatusColumn = 'master_status',
        private readonly string $quantityColumn = 'quantity',
        private readonly array $sellableStatuses = ['A'],
        private readonly bool $requirePositiveQty = true,
        private readonly string $delimiter = ',',
        private readonly string $enclosure = '"'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(string $path): Generator
    {
        if (!$this->fileDriver->isExists($path) || !$this->fileDriver->isFile($path)) {
            throw new FileSystemException(__('Feed file "%1" does not exist.', $path));
        }

        $handle = $this->fileDriver->fileOpen($path, 'r');

        try {
            $header = $this->readHeader($handle, $path);

            while (($row = $this->fileDriver->fileGetCsv($handle, 0, $this->delimiter, $this->enclosure)) !== false) {
                // A blank trailing line reads as [null]; skip it rather than
                // reporting a malformed row for every file.
                if ($row === [null] || $row === []) {
                    continue;
                }

                $sellable = $this->toSupplierSku($header, $row);

                if ($sellable !== null) {
                    yield $sellable;
                }
            }
        } finally {
            // Always closed, including when the consumer abandons the generator
            // part-way through — which the admin preview does.
            $this->fileDriver->fileClose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array<string, int> Column name => index.
     *
     * @throws FileSystemException
     */
    private function readHeader($handle, string $path): array
    {
        $header = $this->fileDriver->fileGetCsv($handle, 0, $this->delimiter, $this->enclosure);

        if ($header === false || $header === [null]) {
            throw new FileSystemException(__('Feed file "%1" is empty.', $path));
        }

        $indexed = [];

        foreach ($header as $index => $name) {
            // Trimmed and lower-cased: suppliers vary the casing and add a BOM
            // often enough that matching the raw value fails intermittently.
            $indexed[mb_strtolower(trim((string) $name, " \t\n\r\0\x0B\u{FEFF}"))] = $index;
        }

        // The quantity column is required only when quantity decides
        // sellability - which is exactly when its absence is invisible.
        $required = [$this->skuColumn, $this->siteStatusColumn];

        if ($this->requirePositiveQty) {
            $required[] = $this->quantityColumn;
        }

        foreach ($required as $column) {
            if (!isset($indexed[mb_strtolower($column)])) {
                throw new FileSystemException(
                    __('Feed file "%1" has no "%2" column.', $path, $column)
                );
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, int> $header
     * @param array<int, mixed>  $row
     */
    private function toSupplierSku(array $header, array $row): ?SupplierSku
    {
        $sku = trim((string) $this->value($header, $row, $this->skuColumn));

        if ($sku === '') {
            return null;
        }

        $siteStatus = trim((string) $this->value($header, $row, $this->siteStatusColumn));
        $quantity = (float) $this->value($header, $row, $this->quantityColumn);

        if (!$this->isSellable($siteStatus, $quantity)) {
            return null;
        }

        return new SupplierSku(
            $sku,
            $siteStatus,
            trim((string) $this->value($header, $row, $this->masterStatusColumn)),
            $quantity
        );
    }

    private function isSellable(string $siteStatus, float $quantity): bool
    {
        $statusOk = $this->sellableStatuses === []
            || in_array(mb_strtoupper($siteStatus), array_map('mb_strtoupper', $this->sellableStatuses), true);

        return $statusOk && (!$this->requirePositiveQty || $quantity > 0);
    }

    /**
     * @param array<string, int> $header
     * @param array<int, mixed>  $row
     */
    private function value(array $header, array $row, string $column): mixed
    {
        $index = $header[mb_strtolower($column)] ?? null;

        return $index === null ? null : ($row[$index] ?? null);
    }
}
