<?php
/**
 * ImportSpec.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Check;

/**
 * What is expected of one monitored import.
 */
class ImportSpec
{
    public function __construct(
        public readonly string $taskCode,
        public readonly string $label,
        public readonly int $expectedRuns = 1,
        /**
         * Hour of day (store timezone) before which this import is not yet due,
         * so its absence is not a fault.
         */
        public readonly int $dueFromHour = 0
    ) {
    }
}
