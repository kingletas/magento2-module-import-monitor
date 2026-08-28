<?php
/**
 * AlertTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\Alert;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class AlertTest extends TestCase
{
    /**
     * Read off the declared name, because `getResourceName()` answers with
     * whatever was injected.
     */
    public function testTheEntityDeclaresItsOwnResourceModel(): void
    {
        $declared = (new ReflectionProperty(Alert::class, '_resourceName'))->getValue($this->entity());

        $this->assertSame(AlertResource::class, $declared);
    }

    public function testTheEntityIsKeyedOnTheAlertId(): void
    {
        $this->assertSame(AlertInterface::ALERT_ID, $this->entity()->getIdFieldName());
    }

    public function testEveryFieldRoundTripsThroughItsSetter(): void
    {
        $alert = $this->entity()
            ->setAlertId(9)
            ->setFingerprint('abc123')
            ->setMessage('Supplier feed missing.')
            ->setStatus(AlertInterface::STATUS_ACKNOWLEDGED)
            ->setOccurrences(4)
            ->setFirstSeenAt('2026-08-20 06:00:00')
            ->setLastSeenAt('2026-08-26 06:00:00')
            ->setAcknowledgedAt('2026-08-26 07:00:00')
            ->setResolvedAt('2026-08-26 08:00:00');

        $this->assertSame(9, $alert->getAlertId());
        $this->assertSame('abc123', $alert->getFingerprint());
        $this->assertSame('Supplier feed missing.', $alert->getMessage());
        $this->assertSame(AlertInterface::STATUS_ACKNOWLEDGED, $alert->getStatus());
        $this->assertSame(4, $alert->getOccurrences());
        $this->assertSame('2026-08-20 06:00:00', $alert->getFirstSeenAt());
        $this->assertSame('2026-08-26 06:00:00', $alert->getLastSeenAt());
        $this->assertSame('2026-08-26 07:00:00', $alert->getAcknowledgedAt());
        $this->assertSame('2026-08-26 08:00:00', $alert->getResolvedAt());
    }

    /**
     * A newly raised alert is open.
     */
    public function testAnAlertWithNoStoredStatusIsOpen(): void
    {
        $alert = $this->entity();

        $this->assertSame(AlertInterface::STATUS_OPEN, $alert->getStatus());
        $this->assertTrue($alert->isOpen());
    }

    public function testOnlyAnOpenAlertIsOpen(): void
    {
        $this->assertFalse($this->entity()->setStatus(AlertInterface::STATUS_ACKNOWLEDGED)->isOpen());
        $this->assertFalse($this->entity()->setStatus(AlertInterface::STATUS_RESOLVED)->isOpen());
        $this->assertTrue($this->entity()->setStatus(AlertInterface::STATUS_OPEN)->isOpen());
    }

    /**
     * The database hands back strings, and the occurrence count is compared and
     * incremented as a number every time the same fault is seen again.
     */
    public function testTheNumericGettersCoerceWhatTheDatabaseHandsBack(): void
    {
        $alert = $this->entity();
        $alert->setData(AlertInterface::ALERT_ID, '9');
        $alert->setData(AlertInterface::OCCURRENCES, '4');

        $this->assertSame(9, $alert->getAlertId());
        $this->assertSame(4, $alert->getOccurrences());
    }

    /**
     * Never acknowledged and acknowledged at an empty string read the same.
     */
    public function testAnUnsetTimestampIsNullWhicheverWayItWasStored(): void
    {
        $alert = $this->entity();

        $this->assertNull($alert->getAcknowledgedAt());
        $this->assertNull($alert->getResolvedAt());
        $this->assertNull($alert->getFirstSeenAt());

        $alert->setData(AlertInterface::ACKNOWLEDGED_AT, '');
        $this->assertNull($alert->getAcknowledgedAt());
    }

    public function testAnUnsavedAlertHasNoIdRatherThanZero(): void
    {
        $this->assertNull($this->entity()->getAlertId());
    }

    public function testAFreshAlertHasSeenTheFaultNoTimesAndCarriesNoText(): void
    {
        $alert = $this->entity();

        $this->assertSame(0, $alert->getOccurrences());
        $this->assertSame('', $alert->getFingerprint());
        $this->assertSame('', $alert->getMessage());
    }

    private function entity(): Alert
    {
        $resource = $this->createMock(AlertResource::class);
        $resource->method('getIdFieldName')->willReturn(AlertInterface::ALERT_ID);

        return new Alert(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $resource
        );
    }
}
