<?php
/**
 * AlertManagerTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Alert;

use Commerce\ImportMonitor\Model\Alert\AlertManager;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\Notification\NotificationDispatcher;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What turns a failed check into an alert, once.
 */
class AlertManagerTest extends TestCase
{
    private const string SECTION = 'commerce_importmonitor';

    private AlertResource&MockObject $resource;
    private NotificationDispatcher&MockObject $dispatcher;
    private RecordingLogger $logger;

    /** @var array<int, array{fingerprint: string, message: string}> */
    private array $recorded = [];

    /** @var string[] Fingerprints reported as newly raised. */
    private array $newlyRaised = [];

    private int $resolved = 0;

    protected function setUp(): void
    {
        $this->recorded = [];
        $this->newlyRaised = [];
        $this->resolved = 0;
        $this->logger = new RecordingLogger();

        $this->resource = $this->createMock(AlertResource::class);
        $this->resource->method('recordOccurrence')->willReturnCallback(
            function (string $fingerprint, string $message): bool {
                $this->recorded[] = ['fingerprint' => $fingerprint, 'message' => $message];

                return in_array($fingerprint, $this->newlyRaised, true);
            }
        );
        $this->resource->method('resolveAllExcept')->willReturnCallback(fn (): int => $this->resolved);

        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
    }

    public function testADisabledModuleRecordsNothingAtAll(): void
    {
        $this->resource->expects($this->never())->method('recordOccurrence');

        $this->assertSame(0, $this->manager(enabled: false)->process([$this->failure('feed missing')]));
    }

    /**
     * Healthy results are still passed in — the manager needs the full set to
     * know which faults have disappeared — but they are not faults.
     */
    public function testHealthyResultsAreNotRecordedAsFaults(): void
    {
        $this->manager()->process([
            new CheckResult(true, 'feed_file'),
            $this->failure('feed missing'),
        ]);

        $this->assertCount(1, $this->recorded);
        $this->assertSame('feed missing', $this->recorded[0]['message']);
    }

    /**
     * A fault seen again is recorded again but does not alert again.
     */
    public function testOnlyANewlyRaisedFaultIsNotified(): void
    {
        $new = $this->failure('feed missing', 'a');
        $repeat = $this->failure('salability drifted', 'b');
        $this->newlyRaised = [(string) $new->fingerprint];

        $this->dispatcher->expects($this->once())->method('dispatchRaised');

        $this->assertSame(1, $this->manager()->process([$new, $repeat]));
        $this->assertCount(2, $this->recorded);
    }

    public function testNothingNewMeansNoNotification(): void
    {
        $this->dispatcher->expects($this->never())->method('dispatchRaised');

        $this->assertSame(0, $this->manager()->process([$this->failure('feed missing')]));
    }

    /**
     * A fault that stopped being reported is closed off, and that is worth a
     * log line: an alert disappearing without one looks like it was lost.
     */
    public function testFaultsThatStoppedBeingReportedAreResolvedAndLogged(): void
    {
        $this->resolved = 2;

        $this->manager()->process([$this->failure('feed missing')]);

        $this->assertCount(1, $this->logger->infos);
        $this->assertStringContainsString('2 alert(s) resolved', $this->logger->infos[0]);
    }

    private function failure(string $message, string $seed = 'seed'): CheckResult
    {
        return new CheckResult(false, 'feed_file', $seed, $message);
    }

    private function manager(bool $enabled = true): AlertManager
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-08-26 06:00:00');

        $config = new Config(
            $this->scopeConfig([self::SECTION . '/general/enabled' => $enabled ? '1' : '0']),
            self::SECTION,
            $this->createMock(EncryptorInterface::class)
        );

        return new AlertManager($this->resource, $this->dispatcher, $config, $dateTime, $this->logger);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
