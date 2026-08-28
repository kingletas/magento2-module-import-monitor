<?php
/**
 * ImportCheckInterface.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Api;

use Commerce\ImportMonitor\Model\Check\CheckResult;

/**
 * One health check over an import.
 */
interface ImportCheckInterface
{
    public function getCode(): string;

    public function getLabel(): string;

    /**
     * Run the check.
     */
    public function run(): CheckResult;
}
