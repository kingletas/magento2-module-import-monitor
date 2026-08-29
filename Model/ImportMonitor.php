<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model;

use Commerce\ImportMonitor\Model\Alert\AlertManager;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Check\CheckRunner;

/**
 * Runs the checks, and optionally raises alerts for what they find.
 */
class ImportMonitor
{
    public function __construct(
        private readonly CheckRunner $checkRunner,
        private readonly AlertManager $alertManager
    ) {
    }

    /**
     * Run every check without raising anything.
     *
     * @return CheckResult[]
     */
    public function check(): array
    {
        return $this->checkRunner->runAll();
    }

    /**
     * Run every check and raise alerts for new failures.
     *
     * @return CheckResult[] Every result, so the caller can report healthy
     *                       checks too. Returning only errors makes "the
     *                       monitor ran and found nothing" indistinguishable
     *                       from "the monitor did not run".
     */
    public function run(): array
    {
        $results = $this->check();
        $this->alertManager->process($results);

        return $results;
    }
}
