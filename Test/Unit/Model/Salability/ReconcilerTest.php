<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability;

use Commerce\ImportMonitor\Model\Salability\DiscrepancyClassifier;
use Commerce\ImportMonitor\Model\Salability\DiscrepancyDescriber;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use Commerce\ImportMonitor\Model\Salability\Reconciler;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Commerce\ImportMonitor\Test\Unit\Fake\ScriptedFeedReader;
use Commerce\ImportMonitor\Test\Unit\Fake\ScriptedStateLoader;
use PHPUnit\Framework\TestCase;

/**
 * A chunk that will not load is the interesting case here.
 */
class ReconcilerTest extends TestCase
{
    public function testEverySkuComparedCountsAsExaminedAndNoneAsSkipped(): void
    {
        $result = $this->reconciler(
            $this->feed(5),
            new ScriptedStateLoader([$this->salableStates(5)])
        )->reconcile('feed.csv');

        $this->assertSame(5, $result->read);
        $this->assertSame(5, $result->examined);
        $this->assertSame(0, $result->skipped);
        $this->assertTrue($result->isClean());
    }

    /**
     * The regression this exists for: every chunk fails, so nothing is compared
     * and nothing disagrees.
     */
    public function testAChunkThatWillNotLoadIsCountedAsSkippedNotExamined(): void
    {
        $result = $this->reconciler(
            $this->feed(5),
            new ScriptedStateLoader([null])
        )->reconcile('feed.csv');

        $this->assertSame(5, $result->read);
        $this->assertSame(0, $result->examined);
        $this->assertSame(5, $result->skipped);
        $this->assertFalse($result->isClean());
        $this->assertTrue($result->isInconclusive());
    }

    /**
     * A partial failure has to leave both numbers standing, or a shortfall in a
     * long run is invisible behind the SKUs that did work.
     */
    public function testAPartialFailureReportsBothCounts(): void
    {
        // Chunk size 2 over 5 SKUs: chunks of 2, 2, 1. The middle one fails.
        $result = $this->reconciler(
            $this->feed(5),
            new ScriptedStateLoader([$this->salableStates(5), null, $this->salableStates(5)]),
            chunkSize: 2
        )->reconcile('feed.csv');

        $this->assertSame(5, $result->read);
        $this->assertSame(3, $result->examined);
        $this->assertSame(2, $result->skipped);
        $this->assertTrue($result->isInconclusive());
    }

    public function testTheFailureIsLoggedWithTheChunkSize(): void
    {
        $logger = new RecordingLogger();

        $this->reconciler($this->feed(3), new ScriptedStateLoader([null]), logger: $logger)
            ->reconcile('feed.csv');

        $this->assertCount(1, $logger->errors);
        $this->assertStringContainsString('could not load Magento state', $logger->errors[0]);
    }

    /**
     * One bad chunk must not abandon the run - that is the whole reason the
     * catch is there.
     */
    public function testAFailedChunkDoesNotStopTheLaterOnes(): void
    {
        $loader = new ScriptedStateLoader([null, $this->salableStates(5), $this->salableStates(5)]);

        $result = $this->reconciler($this->feed(5), $loader, chunkSize: 2)->reconcile('feed.csv');

        $this->assertSame(3, $loader->calls, 'All three chunks should have been attempted.');
        $this->assertSame(3, $result->examined);
    }

    public function testAnEmptyFeedIsReportedAsInconclusiveRatherThanAgreement(): void
    {
        $result = $this->reconciler(new ScriptedFeedReader([]), new ScriptedStateLoader())
            ->reconcile('feed.csv');

        $this->assertSame(0, $result->read);
        $this->assertFalse($result->isClean());
        $this->assertTrue($result->isInconclusive());
    }

    private function reconciler(
        ScriptedFeedReader $feed,
        ScriptedStateLoader $loader,
        ?RecordingLogger $logger = null,
        int $chunkSize = 100
    ): Reconciler {
        return new Reconciler(
            $feed,
            $loader,
            new DiscrepancyClassifier(new DiscrepancyDescriber()),
            $logger ?? new RecordingLogger(),
            $chunkSize
        );
    }

    private function feed(int $count): ScriptedFeedReader
    {
        $skus = [];

        for ($i = 1; $i <= $count; $i++) {
            $skus[] = new SupplierSku('SKU-' . $i, 'A', 'A', 5.0);
        }

        return new ScriptedFeedReader($skus);
    }

    /**
     * @return array<string, ProductState>
     */
    private function salableStates(int $count): array
    {
        $states = [];

        for ($i = 1; $i <= $count; $i++) {
            $states['SKU-' . $i] = new ProductState(
                exists: true,
                isSimple: true,
                isEnabled: true,
                isSalable: true,
                siteStatus: 'A',
                masterStatus: 'A',
                quantity: 5.0
            );
        }

        return $states;
    }
}
