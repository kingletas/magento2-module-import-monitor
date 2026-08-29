<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Check;

use Commerce\ImportMonitor\Model\Check\ImportTask;
use PHPUnit\Framework\TestCase;

class ImportTaskTest extends TestCase
{
    private const NOW = '2026-08-26 12:00:00';

    public function testAFinishedRunIsFinishedWhicheverWayItEnded(): void
    {
        $this->assertTrue($this->task(ImportTask::STATUS_SUCCESS)->isFinished());
        $this->assertTrue($this->task(ImportTask::STATUS_ERROR)->isFinished());
        $this->assertFalse($this->task(ImportTask::STATUS_RUNNING)->isFinished());
        $this->assertFalse($this->task(ImportTask::STATUS_PENDING)->isFinished());
    }

    public function testOnlyAnErroredRunHasFailed(): void
    {
        $this->assertTrue($this->task(ImportTask::STATUS_ERROR)->hasFailed());
        $this->assertFalse($this->task(ImportTask::STATUS_SUCCESS)->hasFailed());
        $this->assertFalse($this->task(ImportTask::STATUS_RUNNING)->hasFailed());
    }

    /**
     * An import that starts and never finishes holds its lock and skips every
     * later run.
     */
    public function testARunInFlightPastTheThresholdIsStuck(): void
    {
        $task = $this->task(ImportTask::STATUS_RUNNING, startedAt: '2026-08-26 06:00:00');

        $this->assertTrue($task->isStuck(4, self::NOW));
        $this->assertFalse($task->isStuck(8, self::NOW));
    }

    /**
     * Strictly past the threshold, so a run exactly at its limit is still
     * within it.
     */
    public function testARunExactlyAtTheThresholdIsNotYetStuck(): void
    {
        $task = $this->task(ImportTask::STATUS_RUNNING, startedAt: '2026-08-26 08:00:00');

        $this->assertFalse($task->isStuck(4, self::NOW));
    }

    /**
     * A run that finished cannot be stuck, however long it took.
     */
    public function testAFinishedRunIsNeverStuck(): void
    {
        $task = $this->task(ImportTask::STATUS_SUCCESS, startedAt: '2026-08-20 06:00:00');

        $this->assertFalse($task->isStuck(1, self::NOW));
    }

    /**
     * A pending run has not started, so there is no elapsed time to measure and
     * nothing to report.
     */
    public function testARunThatHasNotStartedIsNotStuck(): void
    {
        $this->assertFalse($this->task(ImportTask::STATUS_PENDING)->isStuck(1, self::NOW));
    }

    /**
     * `strtotime` answers false for an unreadable timestamp, and false compares
     * as 0.
     */
    public function testAnUnreadableTimestampIsNotReportedAsStuck(): void
    {
        $task = $this->task(ImportTask::STATUS_RUNNING, startedAt: 'not a timestamp');

        $this->assertFalse($task->isStuck(1, self::NOW));
        $this->assertFalse($this->task(ImportTask::STATUS_RUNNING, startedAt: '2026-08-26 06:00:00')
            ->isStuck(1, 'not a timestamp'));
    }

    public function testTheRunCarriesItsTimestampsAndMessage(): void
    {
        $task = new ImportTask(
            'supplier_inventory',
            ImportTask::STATUS_ERROR,
            '2026-08-26 06:00:00',
            '2026-08-26 06:05:00',
            'Feed unreachable'
        );

        $this->assertSame('supplier_inventory', $task->taskCode);
        $this->assertSame('2026-08-26 06:00:00', $task->startedAt);
        $this->assertSame('2026-08-26 06:05:00', $task->finishedAt);
        $this->assertSame('Feed unreachable', $task->message);
    }

    /**
     * A task source that knows only the status - a queue with no timing columns
     * - still produces a usable task.
     */
    public function testTheTimestampsAndMessageAreOptional(): void
    {
        $task = new ImportTask('supplier_inventory', ImportTask::STATUS_SUCCESS);

        $this->assertNull($task->startedAt);
        $this->assertNull($task->finishedAt);
        $this->assertNull($task->message);
    }

    private function task(string $status, ?string $startedAt = null): ImportTask
    {
        return new ImportTask('supplier_inventory', $status, $startedAt);
    }
}
