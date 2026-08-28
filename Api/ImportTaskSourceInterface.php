<?php
/**
 * ImportTaskSourceInterface.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Api;

use Commerce\ImportMonitor\Model\Check\ImportTask;

/**
 * Where the monitor learns what imports ran and how they went.
 */
interface ImportTaskSourceInterface
{
    /**
     * The most recent run of each named task within the window.
     *
     * @param string[] $taskCodes
     * @param string   $since     UTC datetime; runs older than this are ignored.
     *
     * @return array<string, ImportTask> Keyed by task code; codes with no run omitted.
     */
    public function getLatestRuns(array $taskCodes, string $since): array;
}
