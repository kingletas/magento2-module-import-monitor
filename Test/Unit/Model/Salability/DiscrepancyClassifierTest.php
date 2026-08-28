<?php
/**
 * DiscrepancyClassifierTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\DiscrepancyClassifier;
use Commerce\ImportMonitor\Model\Salability\DiscrepancyDescriber;
use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use Commerce\ImportMonitor\Model\Salability\Magento\ProductStateMapper;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use PHPUnit\Framework\TestCase;

class DiscrepancyClassifierTest extends TestCase
{
    private DiscrepancyClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new DiscrepancyClassifier(new DiscrepancyDescriber());
    }

    public function testAgreementProducesNoDiscrepancy(): void
    {
        $result = $this->classifier->classify(
            new SupplierSku('SKU-1', 'A', 'M', 10.0),
            $this->state(
                ['status' => 1, 'type_id' => 'simple', 'site_status' => 'A', 'master_status' => 'M'],
                true,
                10.0
            )
        );

        $this->assertNull($result);
    }

    public function testAMissingProductIsFlagged(): void
    {
        $result = $this->classifier->classify(
            new SupplierSku('SKU-1', 'A', 'M', 10.0),
            new ProductState(exists: false)
        );

        $this->assertNotNull($result);
        $this->assertSame(DiscrepancyReason::Missing, $result->reason);
        $this->assertFalse($result->reason->isAutoFixable());
    }

    public function testADisabledProductIsFlaggedAndIsAutoFixable(): void
    {
        $result = $this->classifier->classify(
            new SupplierSku('SKU-1', 'A', 'M', 10.0),
            $this->state(
                ['status' => 2, 'type_id' => 'simple', 'site_status' => 'A', 'master_status' => 'M'],
                false,
                0.0
            )
        );

        $this->assertNotNull($result);
        $this->assertSame(DiscrepancyReason::Disabled, $result->reason);
        $this->assertTrue($result->reason->isAutoFixable());
    }

    public function testASellableProductWithMismatchedStatusCodesIsFlagged(): void
    {
        $result = $this->classifier->classify(
            new SupplierSku('SKU-1', 'A', 'M', 10.0),
            $this->state(
                ['status' => 1, 'type_id' => 'simple', 'site_status' => 'B', 'master_status' => 'M'],
                true,
                10.0
            )
        );

        $this->assertNotNull($result);
        $this->assertSame(DiscrepancyReason::StatusMismatch, $result->reason);
    }

    /**
     * The feed and the catalogue are maintained by different people; casing and
     * padding differ far more often than meaning does.
     */
    public function testStatusComparisonIgnoresCaseAndSurroundingWhitespace(): void
    {
        $result = $this->classifier->classify(
            new SupplierSku('SKU-1', 'a', ' M ', 10.0),
            $this->state(
                ['status' => 1, 'type_id' => 'simple', 'site_status' => 'A', 'master_status' => 'm'],
                true,
                10.0
            )
        );

        $this->assertNull($result);
    }

    /**
     * A store may legitimately sell its own stock of something the supplier has
     * stopped listing.
     */
    public function testSalableInMagentoButNotAtTheSupplierIsNotADiscrepancy(): void
    {
        // Nothing to classify: such SKUs never reach the classifier, because
        // the feed reader only yields supplier-sellable rows.
        $state = $this->state(
            ['status' => 1, 'type_id' => 'simple', 'site_status' => 'A', 'master_status' => 'M'],
            true,
            50.0
        );

        $this->assertTrue($state->permitsSale());
        $this->assertNull($state->salabilityFailure());
    }

    public function testTheWorstFailureIsReportedFirst(): void
    {
        // Disabled AND out of stock: "disabled" is the actionable one.
        $result = $this->classifier->classify(
            new SupplierSku('SKU-1', 'A', 'M', 10.0),
            $this->state(
                ['status' => 2, 'type_id' => 'simple', 'site_status' => 'A', 'master_status' => 'M'],
                false,
                0.0
            )
        );

        $this->assertNotNull($result);
        $this->assertSame(DiscrepancyReason::Disabled, $result->reason);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function state(array $row, bool $salable, float $quantity): ProductState
    {
        return (new ProductStateMapper())->fromRow($row, $salable, $quantity);
    }
}
