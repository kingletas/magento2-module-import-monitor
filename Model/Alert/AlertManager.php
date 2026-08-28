<?php
/**
 * AlertManager.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Alert;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\Notification\NotificationDispatcher;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns check results into alerts, and alerts into notifications.
 */
class AlertManager
{
    public function __construct(
        private readonly AlertResource $resource,
        private readonly NotificationDispatcher $dispatcher,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param CheckResult[] $results Every result, healthy and failed.
     *
     * @return int Alerts newly raised.
     */
    public function process(array $results): int
    {
        if (!$this->config->isEnabled()) {
            return 0;
        }

        $now = $this->dateTime->gmtDate();
        $failures = array_filter($results, static fn (CheckResult $result): bool => !$result->isHealthy);

        $newlyRaised = [];
        $activeFingerprints = [];

        foreach ($failures as $failure) {
            if ($failure->fingerprint === null || $failure->message === null) {
                continue;
            }

            $activeFingerprints[] = $failure->fingerprint;

            if ($this->resource->recordOccurrence($failure->fingerprint, $failure->message, $now)) {
                $newlyRaised[] = $failure;
            }
        }

        $resolved = $this->resolveDisappeared($activeFingerprints, $now);

        if ($newlyRaised !== []) {
            $this->notifyRaised($newlyRaised);
        }

        if ($resolved > 0) {
            $this->logger->info(sprintf('Import monitor: %d alert(s) resolved automatically.', $resolved));
        }

        return count($newlyRaised);
    }

    /**
     * Acknowledge an alert, verifying the link's signature.
     *
     * @return bool Whether the alert was acknowledged.
     */
    public function acknowledge(int $alertId, string $signature, AlertSigner $signer): bool
    {
        if ($alertId <= 0 || !$signer->verify($alertId, $signature)) {
            return false;
        }

        return $this->resource->setStatus([$alertId], AlertInterface::STATUS_ACKNOWLEDGED, $this->dateTime->gmtDate())
            > 0;
    }

    /**
     * @param string[] $activeFingerprints
     */
    private function resolveDisappeared(array $activeFingerprints, string $now): int
    {
        try {
            return $this->resource->resolveAllExcept($activeFingerprints, $now);
        } catch (Throwable $e) {
            $this->logger->error('Import monitor: could not resolve stale alerts.', ['exception' => $e]);

            return 0;
        }
    }

    /**
     * @param CheckResult[] $failures
     */
    private function notifyRaised(array $failures): void
    {
        try {
            $this->dispatcher->dispatchRaised($failures);
        } catch (Throwable $e) {
            // The alert rows are already written; a notification failure must
            // not lose them.
            $this->logger->error('Import monitor: could not dispatch alert notifications.', ['exception' => $e]);
        }
    }
}
