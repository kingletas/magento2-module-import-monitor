<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Notification;

use Commerce\ImportMonitor\Api\AlertChannelInterface;
use Commerce\ImportMonitor\Model\Alert\AlertSigner;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use InvalidArgumentException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the notification and hands it to every enabled channel.
 */
class NotificationDispatcher
{
    /**
     * @param array<string, AlertChannelInterface> $channels
     */
    public function __construct(
        private readonly AlertResource $alertResource,
        private readonly AlertSigner $signer,
        private readonly UrlInterface $urlBuilder,
        private readonly TimezoneInterface $timezone,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly array $channels = []
    ) {
        foreach ($this->channels as $code => $channel) {
            if (!$channel instanceof AlertChannelInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Alert channel "%s" must implement %s, got %s.',
                    $code,
                    AlertChannelInterface::class,
                    get_debug_type($channel)
                ));
            }
        }
    }

    /**
     * @param CheckResult[] $failures
     */
    public function dispatchRaised(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        $items = [];

        foreach ($failures as $failure) {
            if ($failure->fingerprint === null || $failure->message === null) {
                continue;
            }

            $items[] = [
                'message' => $failure->message,
                'acknowledge_url' => $this->buildAcknowledgeUrl($failure->fingerprint),
            ];
        }

        if ($items === []) {
            return;
        }

        $this->dispatch(new AlertMessage(
            count($items) === 1
                ? 'Import monitor: a check has failed'
                : sprintf('Import monitor: %d checks have failed', count($items)),
            $items,
            // Store timezone, not PHP's default: bare `date()` gives timestamps
            // that disagree with every other one in the admin.
            $this->timezone->date()->format('Y-m-d H:i:s T'),
            $this->config->shouldIncludeHostname() ? (gethostname() ?: null) : null
        ));
    }

    public function dispatch(AlertMessage $message): void
    {
        foreach ($this->channels as $code => $channel) {
            try {
                if (!$channel->isEnabled()) {
                    continue;
                }

                if (!$channel->send($message)) {
                    $this->logger->warning(sprintf('Import monitor: channel "%s" did not deliver.', $code));
                }
            } catch (Throwable $e) {
                // One unreachable destination must not stop the others.
                $this->logger->error(
                    sprintf('Import monitor: channel "%s" failed.', $code),
                    ['exception' => $e]
                );
            }
        }
    }

    private function buildAcknowledgeUrl(string $fingerprint): ?string
    {
        $row = $this->alertResource->loadByFingerprint($fingerprint);
        $alertId = (int) ($row['alert_id'] ?? 0);

        if ($alertId === 0) {
            return null;
        }

        // The signature is what stops anyone walking sequential ids to silence
        // every alert in the system.
        return $this->urlBuilder->getUrl('importmonitor/alerts/acknowledge', [
            'id' => $alertId,
            'token' => $this->signer->sign($alertId),
            '_secure' => true,
        ]);
    }
}
