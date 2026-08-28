<?php
/**
 * ReconcileSalabilityCommandTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Console\Command;

use Commerce\ImportMonitor\Console\Command\ReconcileSalabilityCommand;
use Commerce\ImportMonitor\Model\Salability\Discrepancy;
use Commerce\ImportMonitor\Model\Salability\DiscrepancyReason;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use Commerce\ImportMonitor\Model\Salability\Reconciler;
use Commerce\ImportMonitor\Model\Salability\ReconciliationResult;
use Commerce\ImportMonitor\Model\Salability\SupplierSku;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReconcileSalabilityCommandTest extends TestCase
{
    /** @var Discrepancy[] */
    private array $discrepancies = [];

    /** @var array<int, array{file: string, websiteId: int|null}> */
    private array $runs = [];

    /** @var string[] */
    private array $areaCalls = [];

    private bool $areaAlreadySet = false;
    private int $examined = 10;
    private int $read = 10;
    private int $skipped = 0;
    private ?\Throwable $reconcileFailure = null;

    protected function setUp(): void
    {
        $this->discrepancies = [];
        $this->runs = [];
        $this->areaCalls = [];
        $this->areaAlreadySet = false;
        $this->examined = 10;
        $this->read = 10;
        $this->skipped = 0;
        $this->reconcileFailure = null;
    }

    /**
     * There is no sensible default feed path, and reconciling the wrong file
     * produces a report about a catalogue nobody asked about.
     */
    public function testTheFeedPathIsRequired(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute([]));
        $this->assertStringContainsString('--file is required', $tester->getDisplay());
        $this->assertSame([], $this->runs);
    }

    public function testTheFeedIsReconciledForTheRequestedWebsite(): void
    {
        $this->tester()->execute(['--file' => '/feeds/today.csv', '--website-id' => '2']);

        $this->assertSame([['file' => '/feeds/today.csv', 'websiteId' => 2]], $this->runs);
    }

    /**
     * No website means the provider's own default, which is not the same as
     * website 0.
     */
    public function testNoWebsiteIsLeftUnspecifiedRatherThanBecomingZero(): void
    {
        $this->tester()->execute(['--file' => '/feeds/today.csv']);

        $this->assertNull($this->runs[0]['websiteId']);
    }

    /**
     * Reconciling a whole catalogue takes minutes; a command that prints
     * nothing until the end looks hung.
     */
    public function testFindingsAreStreamedAsTheyAreFound(): void
    {
        $this->discrepancies = [$this->discrepancy('SKU-1'), $this->discrepancy('SKU-2')];

        $tester = $this->tester();
        $tester->execute(['--file' => '/feeds/today.csv']);

        $this->assertStringContainsString('SKU-1', $tester->getDisplay());
        $this->assertStringContainsString('SKU-2', $tester->getDisplay());
        $this->assertStringContainsString(DiscrepancyReason::OutOfStock->value, $tester->getDisplay());
    }

    /**
     * A truncated list that does not say it is truncated reads as the whole
     * picture, and the tail is where the second problem hides.
     */
    public function testATruncatedListSaysHowManyItWithheld(): void
    {
        $this->discrepancies = array_map(
            fn (int $i): Discrepancy => $this->discrepancy('SKU-' . $i),
            range(1, 10)
        );

        $tester = $this->tester();
        $tester->execute(['--file' => '/feeds/today.csv', '--limit' => '3']);

        $this->assertStringContainsString('and 7 more', $tester->getDisplay());
        $this->assertStringContainsString('--limit', $tester->getDisplay());
    }

    public function testAZeroLimitPrintsEverything(): void
    {
        $this->discrepancies = array_map(
            fn (int $i): Discrepancy => $this->discrepancy('SKU-' . $i),
            range(1, 10)
        );

        $tester = $this->tester();
        $tester->execute(['--file' => '/feeds/today.csv', '--limit' => '0']);

        $this->assertStringContainsString('SKU-10', $tester->getDisplay());
        $this->assertStringNotContainsString('more (raise', $tester->getDisplay());
    }

    public function testACleanRunExitsZeroAndSaysWhatItExamined(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--file' => '/feeds/today.csv']));
        $this->assertStringContainsString('Magento agrees on all of them', $tester->getDisplay());
    }

    public function testADisagreeingRunExitsNonZero(): void
    {
        $this->discrepancies = [$this->discrepancy('SKU-1')];

        $this->assertSame(Command::FAILURE, $this->tester()->execute(['--file' => '/feeds/today.csv']));
    }

    /**
     * Three exit codes: found no disagreement and could not look must not both
     * be zero.
     */
    public function testARunThatCouldNotLookIsNotReportedAsAgreement(): void
    {
        $this->read = 10;
        $this->examined = 0;

        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--file' => '/feeds/today.csv']));
        $this->assertStringContainsString('nothing was reconciled', $tester->getDisplay());
        $this->assertStringNotContainsString('agrees on all of them', $tester->getDisplay());
    }

    public function testAnEmptyFeedIsInconclusiveRatherThanClean(): void
    {
        $this->read = 0;
        $this->examined = 0;

        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--file' => '/feeds/today.csv']));
        $this->assertStringContainsString('No sellable SKUs were found in the feed', $tester->getDisplay());
    }

    /**
     * A run that both disagreed and fell short reports the disagreement, with
     * the shortfall appended - the disagreement is the more useful half.
     */
    public function testAPartialRunReportsBothTheDisagreementAndTheShortfall(): void
    {
        $this->discrepancies = [$this->discrepancy('SKU-1')];
        $this->skipped = 4;

        $tester = $this->tester();
        $tester->execute(['--file' => '/feeds/today.csv']);

        $this->assertStringContainsString('1 disagreed', $tester->getDisplay());
        $this->assertStringContainsString('4 further SKU(s) could not be examined', $tester->getDisplay());
    }

    public function testAFailedRunIsReportedAndExitsNonZero(): void
    {
        $this->reconcileFailure = new RuntimeException('feed file is not readable');

        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--file' => '/feeds/today.csv']));
        $this->assertStringContainsString('Reconciliation failed', $tester->getDisplay());
        $this->assertStringContainsString('feed file is not readable', $tester->getDisplay());
    }

    /**
     * The reconciler loads products and stock, which need an area to resolve
     * their configuration against.
     */
    public function testAnAreaIsSetWhenTheProcessHasNone(): void
    {
        $this->tester()->execute(['--file' => '/feeds/today.csv']);

        $this->assertSame(['get', 'set:' . Area::AREA_ADMINHTML], $this->areaCalls);
    }

    /**
     * Setting it twice is a fatal, and `bin/magento` has already set one in
     * some contexts.
     */
    public function testAnAreaThatIsAlreadySetIsLeftAlone(): void
    {
        $this->areaAlreadySet = true;

        $this->tester()->execute(['--file' => '/feeds/today.csv']);

        $this->assertSame(['get'], $this->areaCalls);
    }

    private function discrepancy(string $sku): Discrepancy
    {
        return new Discrepancy(
            new SupplierSku($sku, 'A', 'ACTIVE', 12.0),
            new ProductState(true, true, true, false, 'A', 'ACTIVE', 0.0),
            DiscrepancyReason::OutOfStock,
            sprintf('%s has 12 available at the supplier but Magento shows 0 sellable.', $sku)
        );
    }

    private function reconciler(): Reconciler&MockObject
    {
        $reconciler = $this->createMock(Reconciler::class);
        $reconciler->method('reconcile')->willReturnCallback(
            function (
                string $feedPath,
                ?int $websiteId = null,
                ?callable $onDiscrepancy = null
            ): ReconciliationResult {
                $this->runs[] = ['file' => $feedPath, 'websiteId' => $websiteId];

                if ($this->reconcileFailure !== null) {
                    throw $this->reconcileFailure;
                }

                $counts = [];

                foreach ($this->discrepancies as $discrepancy) {
                    $counts[$discrepancy->reason->value] = ($counts[$discrepancy->reason->value] ?? 0) + 1;

                    if ($onDiscrepancy !== null) {
                        $onDiscrepancy($discrepancy);
                    }
                }

                return new ReconciliationResult(
                    $this->examined,
                    $this->discrepancies,
                    $counts,
                    $this->read,
                    $this->skipped
                );
            }
        );

        return $reconciler;
    }

    private function appState(): State&MockObject
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturnCallback(
            function (): string {
                $this->areaCalls[] = 'get';

                if (!$this->areaAlreadySet) {
                    throw new LocalizedException(__('Area code is not set'));
                }

                return Area::AREA_ADMINHTML;
            }
        );
        $state->method('setAreaCode')->willReturnCallback(function (string $code): void {
            $this->areaCalls[] = 'set:' . $code;
        });

        return $state;
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ReconcileSalabilityCommand($this->reconciler(), $this->appState()));
    }
}
