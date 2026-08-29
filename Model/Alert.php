<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
class Alert extends AbstractModel implements AlertInterface
{
    protected function _construct(): void
    {
        $this->_init(AlertResource::class);
    }

    public function getAlertId(): ?int
    {
        $value = $this->getData(self::ALERT_ID);

        return $value === null ? null : (int) $value;
    }

    public function setAlertId(?int $alertId): AlertInterface
    {
        return $this->setData(self::ALERT_ID, $alertId);
    }

    public function getFingerprint(): string
    {
        return (string) $this->getData(self::FINGERPRINT);
    }

    public function setFingerprint(string $fingerprint): AlertInterface
    {
        return $this->setData(self::FINGERPRINT, $fingerprint);
    }

    public function getMessage(): string
    {
        return (string) $this->getData(self::MESSAGE);
    }

    public function setMessage(string $message): AlertInterface
    {
        return $this->setData(self::MESSAGE, $message);
    }

    public function getStatus(): string
    {
        return (string) ($this->getData(self::STATUS) ?: self::STATUS_OPEN);
    }

    public function setStatus(string $status): AlertInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getOccurrences(): int
    {
        return (int) $this->getData(self::OCCURRENCES);
    }

    public function setOccurrences(int $occurrences): AlertInterface
    {
        return $this->setData(self::OCCURRENCES, $occurrences);
    }

    public function getFirstSeenAt(): ?string
    {
        return $this->nullableString(self::FIRST_SEEN_AT);
    }

    public function setFirstSeenAt(?string $firstSeenAt): AlertInterface
    {
        return $this->setData(self::FIRST_SEEN_AT, $firstSeenAt);
    }

    public function getLastSeenAt(): ?string
    {
        return $this->nullableString(self::LAST_SEEN_AT);
    }

    public function setLastSeenAt(?string $lastSeenAt): AlertInterface
    {
        return $this->setData(self::LAST_SEEN_AT, $lastSeenAt);
    }

    public function getAcknowledgedAt(): ?string
    {
        return $this->nullableString(self::ACKNOWLEDGED_AT);
    }

    public function setAcknowledgedAt(?string $acknowledgedAt): AlertInterface
    {
        return $this->setData(self::ACKNOWLEDGED_AT, $acknowledgedAt);
    }

    public function getResolvedAt(): ?string
    {
        return $this->nullableString(self::RESOLVED_AT);
    }

    public function setResolvedAt(?string $resolvedAt): AlertInterface
    {
        return $this->setData(self::RESOLVED_AT, $resolvedAt);
    }

    public function isOpen(): bool
    {
        return $this->getStatus() === self::STATUS_OPEN;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->getData($key);

        return $value === null || $value === '' ? null : (string) $value;
    }
}
