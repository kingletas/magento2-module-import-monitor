<?php
/**
 * Alert.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\ResourceModel;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Zend_Db_Expr;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
class Alert extends AbstractDb
{
    public const string TABLE_NAME = 'commerce_import_monitor_alert';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, AlertInterface::ALERT_ID);
    }

    /**
     * Record a sighting of a fault, returning whether it is newly raised.
     *
     * @return bool True when this call created the row, i.e. the fault is new
     *              and an alert should go out.
     */
    public function recordOccurrence(string $fingerprint, string $message, string $now): bool
    {
        $connection = $this->getConnection();

        $affectedRows = $connection->insertOnDuplicate(
            $this->getMainTable(),
            [
                AlertInterface::FINGERPRINT => $fingerprint,
                AlertInterface::MESSAGE => $message,
                AlertInterface::STATUS => AlertInterface::STATUS_OPEN,
                AlertInterface::OCCURRENCES => 1,
                AlertInterface::FIRST_SEEN_AT => $now,
                AlertInterface::LAST_SEEN_AT => $now,
            ],
            [
                AlertInterface::MESSAGE => new Zend_Db_Expr('VALUES(' . AlertInterface::MESSAGE . ')'),
                AlertInterface::OCCURRENCES => new Zend_Db_Expr(
                    $connection->quoteIdentifier(AlertInterface::OCCURRENCES) . ' + 1'
                ),
                AlertInterface::LAST_SEEN_AT => new Zend_Db_Expr(
                    'VALUES(' . AlertInterface::LAST_SEEN_AT . ')'
                ),
            ]
        );

        // MySQL reports one affected row for an insert and two for an update,
        // which separates the two.
        return $affectedRows === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadByFingerprint(string $fingerprint): ?array
    {
        $connection = $this->getConnection();

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->getMainTable())
                ->where(AlertInterface::FINGERPRINT . ' = ?', $fingerprint)
        );

        return $row === false ? null : $row;
    }

    /**
     * Close alerts whose fault is no longer being reported.
     *
     * @param string[] $activeFingerprints Faults still being reported.
     *
     * @return int Alerts resolved.
     */
    public function resolveAllExcept(array $activeFingerprints, string $now): int
    {
        $connection = $this->getConnection();

        $where = [
            $connection->quoteInto(
                AlertInterface::STATUS . ' IN (?)',
                [AlertInterface::STATUS_OPEN, AlertInterface::STATUS_ACKNOWLEDGED]
            ),
        ];

        if ($activeFingerprints !== []) {
            $where[] = $connection->quoteInto(
                AlertInterface::FINGERPRINT . ' NOT IN (?)',
                array_values(array_unique($activeFingerprints))
            );
        }

        return (int) $connection->update(
            $this->getMainTable(),
            [
                AlertInterface::STATUS => AlertInterface::STATUS_RESOLVED,
                AlertInterface::RESOLVED_AT => $now,
            ],
            implode(' AND ', $where)
        );
    }

    /**
     * @param int[] $alertIds
     */
    public function setStatus(array $alertIds, string $status, string $now): int
    {
        $alertIds = array_values(array_unique(array_map('intval', $alertIds)));

        if ($alertIds === []) {
            return 0;
        }

        $connection = $this->getConnection();
        $values = [AlertInterface::STATUS => $status];

        if ($status === AlertInterface::STATUS_ACKNOWLEDGED) {
            $values[AlertInterface::ACKNOWLEDGED_AT] = $now;
        } elseif ($status === AlertInterface::STATUS_RESOLVED) {
            $values[AlertInterface::RESOLVED_AT] = $now;
        }

        return (int) $connection->update(
            $this->getMainTable(),
            $values,
            $connection->quoteInto(AlertInterface::ALERT_ID . ' IN (?)', $alertIds)
        );
    }

    public function purgeResolvedBefore(string $cutoff): int
    {
        $connection = $this->getConnection();

        return (int) $connection->delete(
            $this->getMainTable(),
            [
                $connection->quoteInto(AlertInterface::STATUS . ' = ?', AlertInterface::STATUS_RESOLVED),
                $connection->quoteInto(AlertInterface::RESOLVED_AT . ' < ?', $cutoff),
            ]
        );
    }
}
