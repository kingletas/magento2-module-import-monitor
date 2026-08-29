<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\ResourceModel;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\ResourceModel\Alert;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class AlertTest extends TestCase
{
    private const NOW = '2026-08-26 12:00:00';

    /** @var array<int, array{rows: array<string, mixed>, update: array<string, mixed>}> */
    private array $upserts = [];

    /** @var array<int, array{values: array<string, mixed>, where: mixed}> */
    private array $updates = [];

    /** @var array<int, mixed> */
    private array $deletes = [];

    /** @var array<int, array{condition: string, value: mixed}> */
    private array $conditions = [];

    /** @var array<string, mixed>|false */
    private array|false $row = false;

    private int $rowCount = 1;
    private Mysql&MockObject $connection;

    protected function setUp(): void
    {
        $this->upserts = [];
        $this->updates = [];
        $this->deletes = [];
        $this->conditions = [];
        $this->row = false;
        $this->rowCount = 1;

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use (&$select): Select {
                $this->conditions[] = ['condition' => $condition, 'value' => $value];

                return $select;
            }
        );

        $this->connection = $this->createMock(Mysql::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturnCallback(fn () => $this->row);
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn ($id): string => '`' . $id . '`');
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace(
                '?',
                is_array($value) ? "'" . implode("','", $value) . "'" : "'" . $value . "'",
                $text
            )
        );
        // MySQL counts 1 affected row for an insert and 2 for an update, and
        // insertOnDuplicate() returns that count.
        $this->connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows, array $update = []): int {
                $this->upserts[] = ['rows' => $rows, 'update' => $update];

                return $this->rowCount;
            }
        );
        $this->connection->method('update')->willReturnCallback(
            function (string $table, array $values, $where = ''): int {
                $this->updates[] = ['values' => $values, 'where' => $where];

                return 3;
            }
        );
        $this->connection->method('delete')->willReturnCallback(
            function (string $table, $where = ''): int {
                $this->deletes[] = $where;

                return 2;
            }
        );
    }

    public function testTheResourceIsWiredToItsTableAndKey(): void
    {
        $resource = (new ReflectionClass(Alert::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($resource, '_construct'))->invoke($resource);

        $this->assertSame(
            Alert::TABLE_NAME,
            (new ReflectionProperty(Alert::class, '_mainTable'))->getValue($resource)
        );
        $this->assertSame(AlertInterface::ALERT_ID, $resource->getIdFieldName());
    }

    /**
     * One statement.
     */
    public function testASightingIsRecordedInASingleStatement(): void
    {
        $this->resource()->recordOccurrence('fp-1', 'Nightly import is stuck.', self::NOW);

        $this->assertCount(1, $this->upserts);
        $this->assertSame('fp-1', $this->upserts[0]['rows'][AlertInterface::FINGERPRINT]);
        $this->assertSame(AlertInterface::STATUS_OPEN, $this->upserts[0]['rows'][AlertInterface::STATUS]);
    }

    /**
     * MySQL reports one affected row for an insert and two for an update.
     */
    public function testANewFaultIsReportedAsNewlyRaised(): void
    {
        $this->rowCount = 1;

        $this->assertTrue($this->resource()->recordOccurrence('fp-1', 'Nightly import is stuck.', self::NOW));
    }

    public function testARepeatedFaultIsNotReportedAsNewlyRaised(): void
    {
        $this->rowCount = 2;

        $this->assertFalse($this->resource()->recordOccurrence('fp-1', 'Nightly import is stuck.', self::NOW));
    }

    /**
     * The sighting count and latest message are updated; the first-seen
     * timestamp is not.
     */
    public function testARepeatSightingUpdatesTheCountAndMessageButNotTheFirstSeenTime(): void
    {
        $this->resource()->recordOccurrence('fp-1', 'Nightly import is stuck.', self::NOW);

        $update = $this->upserts[0]['update'];
        $this->assertArrayHasKey(AlertInterface::OCCURRENCES, $update);
        $this->assertArrayHasKey(AlertInterface::MESSAGE, $update);
        $this->assertArrayHasKey(AlertInterface::LAST_SEEN_AT, $update);
        $this->assertArrayNotHasKey(AlertInterface::FIRST_SEEN_AT, $update);
        $this->assertArrayNotHasKey(AlertInterface::STATUS, $update);
    }

    public function testAnAlertIsLookedUpByItsFingerprint(): void
    {
        $this->row = ['alert_id' => 9, 'fingerprint' => 'fp-1'];

        $this->assertSame(['alert_id' => 9, 'fingerprint' => 'fp-1'], $this->resource()->loadByFingerprint('fp-1'));
        $this->assertSame(
            [['condition' => AlertInterface::FINGERPRINT . ' = ?', 'value' => 'fp-1']],
            $this->conditions
        );
    }

    /**
     * `fetchRow` answers false for no match; passing that on gives every caller
     * a boolean where an array-or-null was declared.
     */
    public function testAnUnknownFingerprintIsNullRatherThanFalse(): void
    {
        $this->assertNull($this->resource()->loadByFingerprint('fp-unknown'));
    }

    /**
     * Alerts for problems that have fixed themselves resolve automatically.
     */
    public function testAlertsWhoseFaultHasStoppedAreResolved(): void
    {
        $this->assertSame(3, $this->resource()->resolveAllExcept(['fp-1'], self::NOW));

        $this->assertSame(AlertInterface::STATUS_RESOLVED, $this->updates[0]['values'][AlertInterface::STATUS]);
        $this->assertSame(self::NOW, $this->updates[0]['values'][AlertInterface::RESOLVED_AT]);
        $this->assertStringContainsString('NOT IN', $this->updates[0]['where']);
        $this->assertStringContainsString('fp-1', $this->updates[0]['where']);
    }

    /**
     * An already-resolved alert is not resolved again, which would move its
     * timestamp.
     */
    public function testOnlyOpenAndAcknowledgedAlertsAreResolved(): void
    {
        $this->resource()->resolveAllExcept([], self::NOW);

        $this->assertStringContainsString(AlertInterface::STATUS_OPEN, $this->updates[0]['where']);
        $this->assertStringContainsString(AlertInterface::STATUS_ACKNOWLEDGED, $this->updates[0]['where']);
        $this->assertStringNotContainsString('NOT IN', $this->updates[0]['where']);
    }

    public function testDuplicateActiveFingerprintsAreCollapsed(): void
    {
        $this->resource()->resolveAllExcept(['fp-1', 'fp-1', 'fp-2'], self::NOW);

        $this->assertSame(1, substr_count($this->updates[0]['where'], "'fp-1'"));
    }

    public function testAcknowledgingStampsTheAcknowledgementTime(): void
    {
        $this->resource()->setStatus([9], AlertInterface::STATUS_ACKNOWLEDGED, self::NOW);

        $this->assertSame(self::NOW, $this->updates[0]['values'][AlertInterface::ACKNOWLEDGED_AT]);
        $this->assertArrayNotHasKey(AlertInterface::RESOLVED_AT, $this->updates[0]['values']);
    }

    public function testResolvingStampsTheResolutionTime(): void
    {
        $this->resource()->setStatus([9], AlertInterface::STATUS_RESOLVED, self::NOW);

        $this->assertSame(self::NOW, $this->updates[0]['values'][AlertInterface::RESOLVED_AT]);
        $this->assertArrayNotHasKey(AlertInterface::ACKNOWLEDGED_AT, $this->updates[0]['values']);
    }

    /**
     * Reopening an alert stamps neither timestamp.
     */
    public function testReopeningStampsNeitherTimestamp(): void
    {
        $this->resource()->setStatus([9], AlertInterface::STATUS_OPEN, self::NOW);

        $this->assertSame([AlertInterface::STATUS => AlertInterface::STATUS_OPEN], $this->updates[0]['values']);
    }

    /**
     * `IN ()` is a syntax error on MySQL, and an empty mass action on the grid
     * is an ordinary misclick.
     */
    public function testAnEmptyIdSetIsANoOp(): void
    {
        $this->assertSame(0, $this->resource()->setStatus([], AlertInterface::STATUS_RESOLVED, self::NOW));
        $this->assertSame([], $this->updates);
    }

    /**
     * Ids arrive from a grid mass action as strings, so they are cast before
     * they reach the statement.
     */
    public function testIdsAreCoercedAndDeduplicated(): void
    {
        $this->resource()->setStatus(['9', 9, '10'], AlertInterface::STATUS_RESOLVED, self::NOW);

        $this->assertStringContainsString("'9','10'", $this->updates[0]['where']);
    }

    /**
     * Only resolved alerts are purged: an open one is still somebody's problem,
     * however old it is.
     */
    public function testOnlyResolvedAlertsArePurged(): void
    {
        $this->assertSame(2, $this->resource()->purgeResolvedBefore('2026-07-27 12:00:00'));

        $where = implode(' ', (array) $this->deletes[0]);
        $this->assertStringContainsString(AlertInterface::STATUS_RESOLVED, $where);
        $this->assertStringContainsString('2026-07-27 12:00:00', $where);
    }

    private function resource(): Alert&MockObject
    {
        $resource = $this->getMockBuilder(Alert::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn(Alert::TABLE_NAME);

        return $resource;
    }
}
