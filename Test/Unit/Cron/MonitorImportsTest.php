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
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MonitorImportsTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private ImportMonitor&MockObject $monitor;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
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
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('scheduled run failed'));

        $this->monitor->method('run')->willThrowException(new RuntimeException('feed host unreachable'));

        $this->cron()->execute();
    }

    public function testASuccessfulRunSaysNothing(): void
    {
        $this->logger->expects($this->never())->method('error');

        $this->monitor->method('run')->willReturn([]);

        $this->cron()->execute();
    }

    private function cron(bool $enabled = true): MonitorImports
    {
        $config = new Config(
            $this->scopeConfig(['test_importmonitor/general/enabled' => $enabled ? '1' : '0']),
            'test_importmonitor',
            $this->createMock(EncryptorInterface::class)
        );

        return new MonitorImports($this->monitor, $config, $this->logger);
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
