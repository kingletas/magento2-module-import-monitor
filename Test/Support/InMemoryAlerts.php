<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Support;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;

/**
 * The alert table, in an array, with the de-duplication rule intact.
 *
 * @SuppressWarnings(PHPMD.MissingConstructor)
 */
class InMemoryAlerts extends AlertResource
{
    /** @var array<int, array<string, mixed>> Rows, keyed by alert id. */
    public array $rows = [];

    /**
     * Protected rather than private: the performance suite subclasses this to
     * count statements, which is a question about the same table.
     */
    protected int $nextId = 1;

    public function __construct()
    {
    }

    public function recordOccurrence(string $fingerprint, string $message, string $now): bool
    {
        foreach ($this->rows as $alertId => $row) {
            if ($row[AlertInterface::FINGERPRINT] !== $fingerprint) {
                continue;
            }

            // A fault that had been resolved and has come back is a new alert
            // again: somebody needs telling a second time.
            if ($row[AlertInterface::STATUS] === AlertInterface::STATUS_RESOLVED) {
                $this->rows[$alertId][AlertInterface::STATUS] = AlertInterface::STATUS_OPEN;
                $this->rows[$alertId][AlertInterface::OCCURRENCES]++;
                $this->rows[$alertId][AlertInterface::LAST_SEEN_AT] = $now;
                $this->rows[$alertId][AlertInterface::RESOLVED_AT] = null;

                return true;
            }

            $this->rows[$alertId][AlertInterface::OCCURRENCES]++;
            $this->rows[$alertId][AlertInterface::MESSAGE] = $message;
            $this->rows[$alertId][AlertInterface::LAST_SEEN_AT] = $now;

            return false;
        }

        $alertId = $this->nextId++;
        $this->rows[$alertId] = [
            AlertInterface::ALERT_ID => $alertId,
            AlertInterface::FINGERPRINT => $fingerprint,
            AlertInterface::MESSAGE => $message,
            AlertInterface::STATUS => AlertInterface::STATUS_OPEN,
            AlertInterface::OCCURRENCES => 1,
            AlertInterface::FIRST_SEEN_AT => $now,
            AlertInterface::LAST_SEEN_AT => $now,
            AlertInterface::ACKNOWLEDGED_AT => null,
            AlertInterface::RESOLVED_AT => null,
        ];

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadByFingerprint(string $fingerprint): ?array
    {
        foreach ($this->rows as $row) {
            if ($row[AlertInterface::FINGERPRINT] === $fingerprint) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param string[] $activeFingerprints
     */
    public function resolveAllExcept(array $activeFingerprints, string $now): int
    {
        $resolved = 0;

        foreach ($this->rows as $alertId => $row) {
            if ($row[AlertInterface::STATUS] === AlertInterface::STATUS_RESOLVED) {
                continue;
            }

            if (in_array($row[AlertInterface::FINGERPRINT], $activeFingerprints, true)) {
                continue;
            }

            $this->rows[$alertId][AlertInterface::STATUS] = AlertInterface::STATUS_RESOLVED;
            $this->rows[$alertId][AlertInterface::RESOLVED_AT] = $now;
            $resolved++;
        }

        return $resolved;
    }

    /**
     * @param int[] $alertIds
     */
    public function setStatus(array $alertIds, string $status, string $now): int
    {
        $changed = 0;

        foreach ($alertIds as $alertId) {
            if (!isset($this->rows[(int) $alertId])) {
                continue;
            }

            $this->rows[(int) $alertId][AlertInterface::STATUS] = $status;

            if ($status === AlertInterface::STATUS_ACKNOWLEDGED) {
                $this->rows[(int) $alertId][AlertInterface::ACKNOWLEDGED_AT] = $now;
            }

            $changed++;
        }

        return $changed;
    }

    public function purgeResolvedBefore(string $cutoff): int
    {
        $purged = 0;

        foreach ($this->rows as $alertId => $row) {
            if ($row[AlertInterface::STATUS] !== AlertInterface::STATUS_RESOLVED) {
                continue;
            }

            if ((string) ($row[AlertInterface::RESOLVED_AT] ?? '') < $cutoff) {
                unset($this->rows[$alertId]);
                $purged++;
            }
        }

        return $purged;
    }

    public function statusOf(string $fingerprint): string
    {
        return (string) ($this->loadByFingerprint($fingerprint)[AlertInterface::STATUS] ?? '');
    }

    public function occurrencesOf(string $fingerprint): int
    {
        return (int) ($this->loadByFingerprint($fingerprint)[AlertInterface::OCCURRENCES] ?? 0);
    }
}
