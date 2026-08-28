<?php
/**
 * PurgeResolvedAlertsTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Cron;

use Commerce\ImportMonitor\Cron\PurgeResolvedAlerts;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PurgeResolvedAlertsTest extends TestCase
{
    /** @var string[] */
    private array $cutoffs = [];

    /** @var array<int, int> Timestamps the cutoff was formatted from. */
    private array $cutoffTimestamps = [];

    private RecordingLogger $logger;
    private AlertResource&MockObject $alertResource;
    private int $removed = 4;

    protected function setUp(): void
    {
        $this->cutoffs = [];
        $this->cutoffTimestamps = [];
        $this->removed = 4;
        $this->logger = new RecordingLogger();

        $this->alertResource = $this->createMock(AlertResource::class);
        $this->alertResource->method('purgeResolvedBefore')->willReturnCallback(
            function (string $cutoff): int {
                $this->cutoffs[] = $cutoff;

                return $this->removed;
            }
        );
    }

    public function testResolvedAlertsPastTheWindowAreRemoved(): void
    {
        $this->cron()->execute();

        $this->assertCount(1, $this->cutoffs);
    }

    /**
     * The cutoff is the configured retention window back from now.
     */
    public function testTheCutoffIsTheConfiguredRetentionWindow(): void
    {
        $before = time();
        $this->cron(retentionDays: 7)->execute();

        $this->assertCount(1, $this->cutoffTimestamps);
        $this->assertEqualsWithDelta($before - 7 * 86400, $this->cutoffTimestamps[0], 5);
    }

    public function testAnUnconfiguredStoreGetsTheDefaultWindow(): void
    {
        $before = time();
        $this->cron()->execute();

        $this->assertEqualsWithDelta(
            $before - Config::DEFAULT_RETENTION_DAYS * 86400,
            $this->cutoffTimestamps[0],
            5
        );
    }

    /**
     * Cron output is read after the fact, so a sweep that removed something has
     * to leave a trace of how much.
     */
    public function testARemovalIsReportedWithItsCount(): void
    {
        $this->cron()->execute();

        $this->assertCount(1, $this->logger->infos);
        $this->assertStringContainsString('4', $this->logger->infos[0]);
    }

    /**
     * The sweep runs on a schedule and usually finds nothing; a line per run
     * would bury the runs that did something.
     */
    public function testAnEmptySweepSaysNothing(): void
    {
        $this->removed = 0;

        $this->cron()->execute();

        $this->assertSame([], $this->logger->infos);
    }

    public function testAFailingSweepIsLoggedRatherThanThrownAtCron(): void
    {
        $this->alertResource = $this->createMock(AlertResource::class);
        $this->alertResource->method('purgeResolvedBefore')
            ->willThrowException(new RuntimeException('lock wait timeout'));

        $this->cron()->execute();

        $this->assertCount(1, $this->logger->errors);
        $this->assertStringContainsString('cleanup failed', $this->logger->errors[0]);
        $this->assertSame([], $this->logger->infos);
    }

    private function cron(?int $retentionDays = null): PurgeResolvedAlerts
    {
        $values = [];

        if ($retentionDays !== null) {
            $values['test_importmonitor/general/retention_days'] = (string) $retentionDays;
        }

        $config = new Config(
            $this->scopeConfig($values),
            'test_importmonitor',
            $this->createMock(EncryptorInterface::class)
        );

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturnCallback(
            function (string $format = 'Y-m-d H:i:s', $input = null): string {
                $this->cutoffTimestamps[] = (int) $input;

                return gmdate($format, (int) $input);
            }
        );

        return new PurgeResolvedAlerts($this->alertResource, $config, $dateTime, $this->logger);
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
