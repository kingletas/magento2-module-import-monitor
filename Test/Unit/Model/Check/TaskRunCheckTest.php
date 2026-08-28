<?php
/**
 * TaskRunCheckTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Check;

use Commerce\ImportMonitor\Api\ImportCheckInterface;
use Commerce\ImportMonitor\Api\ImportTaskSourceInterface;
use Commerce\ImportMonitor\Model\Check\ImportSpec;
use Commerce\ImportMonitor\Model\Check\ImportTask;
use Commerce\ImportMonitor\Model\Check\TaskRunCheck;
use Commerce\ImportMonitor\Model\Config;
use DateTimeImmutable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TaskRunCheckTest extends TestCase
{
    private const NOW = '2026-08-26 12:00:00';

    /** @var array<string, ImportTask> */
    private array $runs = [];

    private int $currentHour = 12;
    private ?\Throwable $sourceFailure = null;

    /** @var array<int, string> */
    private array $sinceValues = [];

    protected function setUp(): void
    {
        $this->runs = [];
        $this->currentHour = 12;
        $this->sourceFailure = null;
        $this->sinceValues = [];
    }

    public function testItAnnouncesItsCodeAndLabel(): void
    {
        $check = $this->check();

        $this->assertInstanceOf(ImportCheckInterface::class, $check);
        $this->assertSame('import_task_run', $check->getCode());
        $this->assertSame('Import task runs', $check->getLabel());
    }

    public function testTheCodeAndLabelAreConfigurable(): void
    {
        $check = new TaskRunCheck(
            $this->taskSource(),
            $this->config(),
            $this->clock(),
            $this->timezone(),
            [],
            'acme_task_run',
            'Acme import runs'
        );

        $this->assertSame('acme_task_run', $check->getCode());
        $this->assertSame('Acme import runs', $check->getLabel());
    }

    /**
     * A store that has declared no imports has nothing to be unhealthy about,
     * and asking the task source anyway is a query for an empty list.
     */
    public function testAStoreWithNoDeclaredImportsIsHealthyWithoutQuerying(): void
    {
        $result = $this->check()->run();

        $this->assertTrue($result->isHealthy);
        $this->assertSame([], $this->sinceValues);
    }

    public function testAnImportThatRanAndFinishedIsHealthy(): void
    {
        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_SUCCESS, '2026-08-26 06:00:00')];

        $this->assertTrue($this->check([$this->spec()])->run()->isHealthy);
    }

    public function testAnImportThatNeverRanIsReported(): void
    {
        $result = $this->check([$this->spec()])->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString('has not run', (string) $result->message);
    }

    /**
     * An import inside its window is not late and is not alerted on.
     */
    public function testAnImportThatIsNotDueYetIsNotAFault(): void
    {
        $this->currentHour = 4;

        $this->assertTrue($this->check([$this->spec(dueFromHour: 6)])->run()->isHealthy);
    }

    public function testAnImportPastItsDueHourIsReportedAsMissing(): void
    {
        $this->currentHour = 7;

        $this->assertFalse($this->check([$this->spec(dueFromHour: 6)])->run()->isHealthy);
    }

    public function testAFailedImportIsReportedWithItsOwnMessage(): void
    {
        $this->runs = ['nightly' => new ImportTask(
            'nightly',
            ImportTask::STATUS_ERROR,
            '2026-08-26 06:00:00',
            '2026-08-26 06:05:00',
            'Feed host unreachable'
        )];

        $result = $this->check([$this->spec()])->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString('finished with an error', (string) $result->message);
        $this->assertStringContainsString('Feed host unreachable', (string) $result->message);
    }

    public function testAFailedImportWithNoMessageIsStillReported(): void
    {
        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_ERROR, '2026-08-26 06:00:00')];

        $result = $this->check([$this->spec()])->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringEndsWith('error.', (string) $result->message);
    }

    /**
     * An import that starts and never finishes holds its lock, and nothing else
     * raises an error.
     */
    public function testAnImportStuckPastTheThresholdIsReported(): void
    {
        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_RUNNING, '2026-08-26 06:00:00')];

        $result = $this->check([$this->spec()], stuckThresholdHours: 2)->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString('has been running since', (string) $result->message);
        $this->assertStringContainsString('2 hour threshold', (string) $result->message);
    }

    public function testAnImportRunningWithinItsThresholdIsHealthy(): void
    {
        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_RUNNING, '2026-08-26 11:30:00')];

        $this->assertTrue($this->check([$this->spec()], stuckThresholdHours: 2)->run()->isHealthy);
    }

    public function testEveryFaultyImportIsNamedInOneResult(): void
    {
        $this->runs = ['other' => new ImportTask('other', ImportTask::STATUS_ERROR, '2026-08-26 06:00:00')];

        $result = $this->check([
            $this->spec(),
            new ImportSpec('other', 'Hourly stock feed'),
        ])->run();

        $this->assertStringContainsString('Nightly catalogue import', (string) $result->message);
        $this->assertStringContainsString('Hourly stock feed', (string) $result->message);
    }

    /**
     * The fingerprint identifies the fault, never the sighting.
     */
    public function testTwoSightingsOfOneFaultShareAFingerprint(): void
    {
        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_RUNNING, '2026-08-26 06:00:00')];
        $first = $this->check([$this->spec()])->run();

        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_RUNNING, '2026-08-26 06:00:00')];
        $second = $this->check([$this->spec()])->run();

        $this->assertSame($first->fingerprint, $second->fingerprint);
    }

    /**
     * A different fault on the same import is a different alert: an operator
     * who acknowledged "stuck" has not been told about "errored".
     */
    public function testADifferentFaultOnTheSameImportGetsItsOwnFingerprint(): void
    {
        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_RUNNING, '2026-08-26 06:00:00')];
        $stuck = $this->check([$this->spec()])->run();

        $this->runs = ['nightly' => new ImportTask('nightly', ImportTask::STATUS_ERROR, '2026-08-26 06:00:00')];
        $errored = $this->check([$this->spec()])->run();

        $this->assertNotSame($stuck->fingerprint, $errored->fingerprint);
    }

    /**
     * A task source that cannot answer is its own fault, not every import being
     * missing.
     */
    public function testATaskSourceThatCannotAnswerIsItsOwnFault(): void
    {
        $this->sourceFailure = new LocalizedException(__('No import task source is bound.'));

        $result = $this->check([$this->spec(), new ImportSpec('other', 'Hourly stock feed')])->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString('could not read import history', (string) $result->message);
        $this->assertStringNotContainsString('has not run', (string) $result->message);
    }

    /**
     * A day and a half of history: enough to see yesterday's overnight run
     * without dragging in a week of rows on every cron tick.
     */
    public function testTheHistoryWindowLooksBackBeyondYesterdaysRun(): void
    {
        $this->check([$this->spec()])->run();

        $this->assertCount(1, $this->sinceValues);
        // Exact, now that both ends come from the same clock.
        $this->assertSame(
            gmdate('Y-m-d H:i:s', strtotime(self::NOW . ' UTC') - 36 * 3600),
            $this->sinceValues[0]
        );
    }

    private function spec(int $dueFromHour = 0): ImportSpec
    {
        return new ImportSpec('nightly', 'Nightly catalogue import', 1, $dueFromHour);
    }

    /**
     * @param ImportSpec[] $specs
     */
    private function check(array $specs = [], int $stuckThresholdHours = 2): TaskRunCheck
    {
        return new TaskRunCheck(
            $this->taskSource(),
            $this->config($stuckThresholdHours),
            $this->clock(),
            $this->timezone(),
            $specs
        );
    }

    private function taskSource(): ImportTaskSourceInterface&MockObject
    {
        $source = $this->createMock(ImportTaskSourceInterface::class);
        $source->method('getLatestRuns')->willReturnCallback(
            function (array $taskCodes, string $since): array {
                $this->sinceValues[] = $since;

                if ($this->sourceFailure !== null) {
                    throw $this->sourceFailure;
                }

                return $this->runs;
            }
        );

        return $source;
    }

    private function config(int $stuckThresholdHours = 2): Config
    {
        return new Config(
            $this->scopeConfig([
                'test_importmonitor/general/stuck_threshold_hours' => (string) $stuckThresholdHours,
            ]),
            'test_importmonitor',
            $this->createMock(EncryptorInterface::class)
        );
    }

    private function clock(): DateTime&MockObject
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn ($format = 'Y-m-d H:i:s', $input = null): string
                => $input === null ? self::NOW : gmdate((string) ($format ?? 'Y-m-d H:i:s'), (int) $input)
        );
        // The stubbed clock has to answer for "now" as well as for formatting.
        $dateTime->method('gmtTimestamp')->willReturn(strtotime(self::NOW . ' UTC'));

        return $dateTime;
    }

    private function timezone(): TimezoneInterface&MockObject
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            fn (): DateTimeImmutable => new DateTimeImmutable(sprintf('2026-08-26 %02d:00:00', $this->currentHour))
        );

        return $timezone;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
