<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Check;

/**
 * One observed run of an import task.
 */
class ImportTask
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_ERROR = 'error';

    public function __construct(
        public readonly string $taskCode,
        public readonly string $status,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?string $message = null
    ) {
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_ERROR], true);
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function isStuck(int $thresholdHours, string $now): bool
    {
        if ($this->isFinished() || $this->startedAt === null) {
            return false;
        }

        $started = strtotime($this->startedAt);
        $current = strtotime($now);

        if ($started === false || $current === false) {
            return false;
        }

        return ($current - $started) > ($thresholdHours * 3600);
    }
}
