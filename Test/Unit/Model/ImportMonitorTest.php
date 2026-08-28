<?php
/**
 * ImportMonitorTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model;

use Commerce\ImportMonitor\Model\Alert\AlertManager;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Check\CheckRunner;
use Commerce\ImportMonitor\Model\ImportMonitor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImportMonitorTest extends TestCase
{
    /** @var CheckResult[] */
    private array $results = [];

    /** @var array<int, CheckResult[]> */
    private array $processed = [];

    private CheckRunner&MockObject $checkRunner;

    protected function setUp(): void
    {
        $this->processed = [];
        $this->results = [
            new CheckResult(true, 'supplier_inventory'),
            new CheckResult(false, 'supplier_feed', 'feed:missing', 'No feed file for today.'),
        ];

        $this->checkRunner = $this->createMock(CheckRunner::class);
        $this->checkRunner->method('runAll')->willReturnCallback(fn (): array => $this->results);
    }

    /**
     * Looking at what the checks say is a routine thing to do while
     * investigating something; it must not raise an alert each time somebody
     * looks.
     */
    public function testCheckingRaisesNothing(): void
    {
        $this->assertSame($this->results, $this->monitor()->check());
        $this->assertSame([], $this->processed);
    }

    public function testRunningRaisesAlertsForWhatTheChecksFound(): void
    {
        $this->monitor()->run();

        $this->assertSame([$this->results], $this->processed);
    }

    /**
     * Every result comes back, healthy ones included.
     */
    public function testRunningReturnsHealthyChecksAsWellAsFailures(): void
    {
        $returned = $this->monitor()->run();

        $this->assertCount(2, $returned);
        $this->assertSame($this->results, $returned);
    }

    public function testARunWithNothingWrongStillReportsTheChecksItRan(): void
    {
        $this->results = [new CheckResult(true, 'supplier_inventory')];

        $this->assertCount(1, $this->monitor()->run());
        $this->assertSame([$this->results], $this->processed);
    }

    /**
     * The alert manager is handed the full set, because it resolves what has
     * stopped failing.
     */
    public function testTheAlertManagerSeesEveryResultRatherThanOnlyTheFailures(): void
    {
        $this->monitor()->run();

        $this->assertSame(
            ['supplier_inventory', 'supplier_feed'],
            array_map(static fn (CheckResult $r): string => $r->checkCode, $this->processed[0])
        );
    }

    /**
     * The cron job, the console command and the admin button all go through
     * here, so the checks run the same way for all three.
     */
    public function testTheChecksAreRunThroughOneSharedRunner(): void
    {
        $this->checkRunner = $this->createMock(CheckRunner::class);
        $this->checkRunner->expects($this->exactly(2))->method('runAll')->willReturn([]);

        $monitor = $this->monitor();
        $monitor->check();
        $monitor->run();
    }

    private function monitor(): ImportMonitor
    {
        $alertManager = $this->createMock(AlertManager::class);
        $alertManager->method('process')->willReturnCallback(
            function (array $results): int {
                $this->processed[] = $results;

                return 0;
            }
        );

        return new ImportMonitor($this->checkRunner, $alertManager);
    }
}
