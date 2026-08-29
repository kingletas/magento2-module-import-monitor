<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model;

use Commerce\Foundation\Model\Config\ModuleConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Typed access to this module's settings.
 */
class Config extends ModuleConfig
{
    public const int DEFAULT_STUCK_THRESHOLD_HOURS = 2;
    public const int DEFAULT_RETENTION_DAYS = 30;
    public const int DEFAULT_STRICT_HOUR = 21;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        string $section,
        private readonly EncryptorInterface $encryptor
    ) {
        parent::__construct($scopeConfig, $section);
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('general/enabled', $storeId);
    }

    /**
     * How long an import may sit without progressing before it is called stuck.
     */
    public function getStuckThresholdHours(?int $storeId = null): int
    {
        return $this->getPositiveInt(
            'general/stuck_threshold_hours',
            self::DEFAULT_STUCK_THRESHOLD_HOURS,
            $storeId
        );
    }

    /**
     * Hour of day (0-23, store timezone) from which today's feed is required
     * rather than merely expected.
     */
    public function getFeedStrictHour(?int $storeId = null): int
    {
        $value = $this->getInt('general/feed_strict_hour', self::DEFAULT_STRICT_HOUR, $storeId);

        return max(0, min(23, $value));
    }

    public function getRetentionDays(?int $storeId = null): int
    {
        return $this->getPositiveInt('general/retention_days', self::DEFAULT_RETENTION_DAYS, $storeId);
    }

    // ---------------------------------------------------------- Notifications

    /**
     * @return string[]
     */
    public function getRecipients(?int $storeId = null): array
    {
        return $this->getList('notification/recipients', $storeId);
    }

    public function getSenderIdentity(?int $storeId = null): string
    {
        return $this->getString('notification/sender_identity', 'general', $storeId);
    }

    public function getAlertTemplate(?int $storeId = null): string
    {
        return $this->getString('notification/alert_template', 'commerce_import_monitor_alert', $storeId);
    }

    public function getResolvedTemplate(?int $storeId = null): string
    {
        return $this->getString('notification/resolved_template', 'commerce_import_monitor_resolved', $storeId);
    }

    /**
     * Whether alert messages may name the server they came from.
     */
    public function shouldIncludeHostname(?int $storeId = null): bool
    {
        return $this->isSetFlag('notification/include_hostname', $storeId);
    }

    // ----------------------------------------------------------------- Slack

    public function isSlackEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('slack/enabled', $storeId)
            && $this->getSlackToken($storeId) !== ''
            && $this->getSlackChannel($storeId) !== '';
    }

    /**
     * Decrypted Slack bot token.
     */
    public function getSlackToken(?int $storeId = null): string
    {
        $raw = $this->getString('slack/token', '', $storeId);

        return $raw === '' ? '' : $this->encryptor->decrypt($raw);
    }

    public function getSlackChannel(?int $storeId = null): string
    {
        return ltrim($this->getString('slack/channel', '', $storeId), '#');
    }
}
