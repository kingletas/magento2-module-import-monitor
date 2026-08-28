<?php
/**
 * PurgeResolvedAlerts.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Cron;

use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes resolved alerts past the retention window.
 */
class PurgeResolvedAlerts
{
    public function __construct(
        private readonly AlertResource $alertResource,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $days = $this->config->getRetentionDays();
        $cutoff = $this->dateTime->gmtDate('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days)));

        try {
            $removed = $this->alertResource->purgeResolvedBefore($cutoff);
        } catch (Throwable $e) {
            $this->logger->error('Import monitor: alert cleanup failed.', ['exception' => $e]);

            return;
        }

        if ($removed > 0) {
            $this->logger->info(sprintf('Import monitor: removed %d resolved alert(s).', $removed));
        }
    }
}
