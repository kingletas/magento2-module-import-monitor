<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Check;

use Commerce\ImportMonitor\Api\ImportCheckInterface;
use Commerce\ImportMonitor\Api\ImportTaskSourceInterface;
use Commerce\ImportMonitor\Model\Config;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Throwable;

/**
 * Checks that each expected import ran, finished, and did not error.
 */
class TaskRunCheck implements ImportCheckInterface
{
    /**
     * A day and a half: enough to see yesterday's overnight run without
     * dragging in a week of rows on every cron tick.
     */
    private const int HISTORY_WINDOW_HOURS = 36;

    /**
     * @param ImportSpec[] $specs
     */
    public function __construct(
        private readonly ImportTaskSourceInterface $taskSource,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly TimezoneInterface $timezone,
        private readonly array $specs = [],
        private readonly string $code = 'import_task_run',
        private readonly string $label = 'Import task runs'
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function run(): CheckResult
    {
        if ($this->specs === []) {
            return new CheckResult(isHealthy: true, checkCode: $this->code);
        }

        $now = $this->dateTime->gmtDate();
        // The window is measured from the injected clock's idea of now, not
        // from `strtotime()`.
        $since = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - self::HISTORY_WINDOW_HOURS * 3600
        );

        try {
            $runs = $this->taskSource->getLatestRuns(
                array_map(static fn (ImportSpec $spec): string => $spec->taskCode, $this->specs),
                $since
            );
        } catch (Throwable $e) {
            return new CheckResult(
                isHealthy: false,
                checkCode: $this->code,
                fingerprintSeed: 'task-source-unavailable',
                message: sprintf('%s: could not read import history (%s).', $this->label, $e->getMessage())
            );
        }

        $problems = [];
        $seeds = [];
        $currentHour = (int) $this->timezone->date()->format('G');

        foreach ($this->specs as $spec) {
            $problem = $this->evaluate($spec, $runs[$spec->taskCode] ?? null, $currentHour, $now);

            if ($problem !== null) {
                $problems[] = $problem['message'];
                $seeds[] = $problem['seed'];
            }
        }

        if ($problems === []) {
            return new CheckResult(isHealthy: true, checkCode: $this->code);
        }

        // The fingerprint is built from which tasks are faulty and how, never
        // from the message, which carries timestamps that change every run.
        return new CheckResult(
            isHealthy: false,
            checkCode: $this->code,
            fingerprintSeed: implode(',', $seeds),
            message: implode(' ', $problems)
        );
    }

    /**
     * @return array{message: string, seed: string}|null
     */
    private function evaluate(ImportSpec $spec, ?ImportTask $task, int $currentHour, string $now): ?array
    {
        if ($task === null) {
            // Not due yet is not a fault.
            if ($currentHour < $spec->dueFromHour) {
                return null;
            }

            return [
                'message' => sprintf('%s has not run.', $spec->label),
                'seed' => $spec->taskCode . ':missing',
            ];
        }

        if ($task->hasFailed()) {
            return [
                'message' => sprintf(
                    '%s finished with an error%s.',
                    $spec->label,
                    $task->message !== null ? ': ' . $task->message : ''
                ),
                'seed' => $spec->taskCode . ':error',
            ];
        }

        if ($task->isStuck($this->config->getStuckThresholdHours(), $now)) {
            return [
                'message' => sprintf(
                    '%s has been running since %s, past the %d hour threshold.',
                    $spec->label,
                    (string) $task->startedAt,
                    $this->config->getStuckThresholdHours()
                ),
                'seed' => $spec->taskCode . ':stuck',
            ];
        }

        return null;
    }
}
