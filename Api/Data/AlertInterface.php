<?php
/**
 * AlertInterface.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Api\Data;

/**
 * One raised monitoring alert.
 */
interface AlertInterface
{
    public const string ALERT_ID = 'alert_id';
    public const string FINGERPRINT = 'fingerprint';
    public const string MESSAGE = 'message';
    public const string STATUS = 'status';
    public const string OCCURRENCES = 'occurrences';
    public const string FIRST_SEEN_AT = 'first_seen_at';
    public const string LAST_SEEN_AT = 'last_seen_at';
    public const string ACKNOWLEDGED_AT = 'acknowledged_at';
    public const string RESOLVED_AT = 'resolved_at';

    public const string STATUS_OPEN = 'open';
    public const string STATUS_ACKNOWLEDGED = 'acknowledged';
    public const string STATUS_RESOLVED = 'resolved';

    public function getAlertId(): ?int;

    public function setAlertId(?int $alertId): self;

    /**
     * Stable hash of the alert's message, used to recognise a repeat.
     */
    public function getFingerprint(): string;

    public function setFingerprint(string $fingerprint): self;

    public function getMessage(): string;

    public function setMessage(string $message): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getOccurrences(): int;

    public function setOccurrences(int $occurrences): self;

    public function getFirstSeenAt(): ?string;

    public function setFirstSeenAt(?string $firstSeenAt): self;

    public function getLastSeenAt(): ?string;

    public function setLastSeenAt(?string $lastSeenAt): self;

    public function getAcknowledgedAt(): ?string;

    public function setAcknowledgedAt(?string $acknowledgedAt): self;

    public function getResolvedAt(): ?string;

    public function setResolvedAt(?string $resolvedAt): self;

    public function isOpen(): bool;
}
