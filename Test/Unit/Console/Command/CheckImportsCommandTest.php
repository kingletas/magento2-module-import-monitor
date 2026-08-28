<?php
/**
 * CheckImportsCommandTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Console\Command;

use Commerce\ImportMonitor\Console\Command\CheckImportsCommand;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\ImportMonitor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CheckImportsCommandTest extends TestCase
{
    /** @var CheckResult[] */
    private array $results = [];

    private ImportMonitor&MockObject $monitor;

    protected function setUp(): void
    {
        $this->results = [
            new CheckResult(true, 'feed_file'),
            new CheckResult(false, 'import_task_run', 'nightly:stuck', 'Nightly import is stuck.'),
        ];

        $this->monitor = $this->createMock(ImportMonitor::class);
        $this->monitor->method('check')->willReturnCallback(fn (): array => $this->results);
        $this->monitor->method('run')->willReturnCallback(fn (): array => $this->results);
    }

    /**
     * Running the checks to see what they say must not page whoever is on call.
     */
    public function testItDoesNotAlertByDefault(): void
    {
        $this->monitor->expects($this->once())->method('check')->willReturn($this->results);
        $this->monitor->expects($this->never())->method('run');

        $this->tester()->execute([]);
    }

    public function testAlertingIsOptIn(): void
    {
        $this->monitor->expects($this->once())->method('run')->willReturn($this->results);
        $this->monitor->expects($this->never())->method('check');

        $this->tester()->execute(['--alert' => true]);
    }

    public function testEveryCheckIsListedWithItsOutcome(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('feed_file', $tester->getDisplay());
        $this->assertStringContainsString('import_task_run', $tester->getDisplay());
        $this->assertStringContainsString('Nightly import is stuck.', $tester->getDisplay());
    }

    public function testTheTotalsAreReported(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('2 check(s) run, 1 failing.', $tester->getDisplay());
    }

    /**
     * A non-zero exit lets an external scheduler notice a failing check without
     * parsing the output.
     */
    public function testAFailingCheckExitsNonZero(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester()->execute([]));
    }

    public function testAHealthyRunExitsZero(): void
    {
        $this->results = [new CheckResult(true, 'feed_file')];

        $this->assertSame(Command::SUCCESS, $this->tester()->execute([]));
    }

    /**
     * A store with nothing registered is not failing, but it is worth saying
     * so: an empty report otherwise reads as a clean bill of health.
     */
    public function testAStoreWithNoRegisteredChecksSaysSo(): void
    {
        $this->results = [];

        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No checks are registered', $tester->getDisplay());
    }

    /**
     * A check list that cannot be built is a failure rather than everything
     * passing.
     */
    public function testARunThatCannotStartIsReportedAsAFailure(): void
    {
        $this->monitor = $this->createMock(ImportMonitor::class);
        $this->monitor->method('check')->willThrowException(new RuntimeException('no task source is bound'));

        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('could not run', $tester->getDisplay());
        $this->assertStringContainsString('no task source is bound', $tester->getDisplay());
    }

    private function command(): CheckImportsCommand
    {
        return new CheckImportsCommand($this->monitor);
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->command());
    }
}
