<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Cron;

use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\ImportMonitor;
use Psr\Log\LoggerInterface;
use Throwable;

class MonitorImports
{
    public function __construct(
        private readonly ImportMonitor $monitor,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $this->monitor->run();
        } catch (Throwable $e) {
            // Monitoring that dies quietly is worse than no monitoring, so this
            // is logged at error level even though the job itself continues.
            $this->logger->error('Import monitor: the scheduled run failed.', ['exception' => $e]);
        }
    }
}
