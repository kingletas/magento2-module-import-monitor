<?php
/**
 * MonitorImportsTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Cron;

use Commerce\ImportMonitor\Cron\MonitorImports;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\ImportMonitor;
use Commerce\ImportMonitor\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MonitorImportsTest extends TestCase
{
    private RecordingLogger $logger;
    private ImportMonitor&MockObject $monitor;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->monitor = $this->createMock(ImportMonitor::class);
    }

    public function testTheScheduledRunRaisesAlerts(): void
    {
        $this->monitor->expects($this->once())->method('run')->willReturn([]);
        $this->monitor->expects($this->never())->method('check');

        $this->cron()->execute();
    }

    public function testNothingRunsWhenTheModuleIsDisabled(): void
    {
        $this->monitor->expects($this->never())->method('run');

        $this->cron(enabled: false)->execute();
    }

    /**
     * Monitoring that dies quietly is worse than no monitoring: the store looks
     * healthy because nothing is checking it.
     */
    public function testAFailedRunIsLoggedRatherThanThrownAtCron(): void
    {
        $this->monitor->method('run')->willThrowException(new RuntimeException('feed host unreachable'));

        $this->cron()->execute();

        $this->assertCount(1, $this->logger->errors);
        $this->assertStringContainsString('scheduled run failed', $this->logger->errors[0]);
    }

    public function testASuccessfulRunSaysNothing(): void
    {
        $this->monitor->method('run')->willReturn([]);

        $this->cron()->execute();

        $this->assertSame([], $this->logger->errors);
    }

    private function cron(bool $enabled = true): MonitorImports
    {
        $config = new Config(
            new ArrayScopeConfig(['test_importmonitor/general/enabled' => $enabled ? '1' : '0']),
            'test_importmonitor',
            $this->createMock(EncryptorInterface::class)
        );

        return new MonitorImports($this->monitor, $config, $this->logger);
    }
}
