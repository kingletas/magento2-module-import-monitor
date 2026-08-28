<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability\Feed;

use Commerce\ImportMonitor\Model\Salability\Feed\CsvFeedReader;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

/**
 * Reads a real file through the real driver.
 */
final class CsvFeedReaderTest extends TestCase
{
    /** @var string[] */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->paths = [];
    }

    public function testSellableRowsAreYielded(): void
    {
        $skus = $this->read(
            "sku,site_status,master_status,quantity\n"
            . "SKU-1,A,A,5\n"
            . "SKU-2,A,A,1\n"
        );

        self::assertCount(2, $skus);
        self::assertSame('SKU-1', $skus[0]->sku);
        self::assertSame(5.0, $skus[0]->quantity);
    }

    public function testRowsWithANonSellableStatusAreSkipped(): void
    {
        $skus = $this->read(
            "sku,site_status,master_status,quantity\n"
            . "SKU-1,A,A,5\n"
            . "SKU-2,D,A,5\n"
        );

        self::assertCount(1, $skus);
        self::assertSame('SKU-1', $skus[0]->sku);
    }

    public function testRowsWithNoQuantityAreSkippedWhenQuantityDecidesSellability(): void
    {
        $skus = $this->read(
            "sku,site_status,master_status,quantity\n"
            . "SKU-1,A,A,0\n"
            . "SKU-2,A,A,3\n"
        );

        self::assertCount(1, $skus);
        self::assertSame('SKU-2', $skus[0]->sku);
    }

    /**
     * The regression this exists for.
     */
    public function testAMissingQuantityColumnIsRejectedRatherThanReadAsZero(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('has no "quantity" column');

        $this->read("sku,site_status,master_status,qty\nSKU-1,A,A,5\n");
    }

    /**
     * Only when quantity is what decides.
     */
    public function testTheQuantityColumnIsNotRequiredWhenQuantityDoesNotDecide(): void
    {
        $skus = $this->read(
            "sku,site_status,master_status\nSKU-1,A,A\n",
            requirePositiveQty: false
        );

        self::assertCount(1, $skus);
        self::assertSame(0.0, $skus[0]->quantity);
    }

    public function testAMissingSkuColumnIsRejected(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('has no "sku" column');

        $this->read("code,site_status,master_status,quantity\nSKU-1,A,A,5\n");
    }

    public function testAnEmptyFileIsRejectedRatherThanReadAsAnEmptyFeed(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('is empty');

        $this->read('');
    }

    public function testAMissingFileIsRejected(): void
    {
        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('does not exist');

        iterator_to_array((new CsvFeedReader(new FileDriver()))->read('/nonexistent/feed.csv'));
    }

    /**
     * Headers are matched after trimming, case-folding and stripping a UTF-8
     * BOM.
     */
    public function testHeadersAreMatchedIgnoringCaseSpacingAndABom(): void
    {
        $skus = $this->read(
            "\u{FEFF} SKU ,Site_Status,MASTER_STATUS, quantity \n"
            . "SKU-1,A,A,5\n"
        );

        self::assertCount(1, $skus);
        self::assertSame('SKU-1', $skus[0]->sku);
    }

    /**
     * @return SupplierSku[]
     */
    private function read(string $contents, bool $requirePositiveQty = true): array
    {
        $path = tempnam(sys_get_temp_dir(), 'feed') . '.csv';
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        $reader = new CsvFeedReader(
            new FileDriver(),
            requirePositiveQty: $requirePositiveQty
        );

        return array_values(iterator_to_array($reader->read($path), false));
    }
}
