<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Behaviour;

use Commerce\ImportMonitor\Api\AlertChannelInterface;
use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Api\ImportCheckInterface;
use Commerce\ImportMonitor\Model\Alert\AlertManager;
use Commerce\ImportMonitor\Model\Alert\AlertSigner;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Check\CheckRunner;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\ImportMonitor;
use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use Commerce\ImportMonitor\Model\Notification\NotificationDispatcher;
use Commerce\ImportMonitor\Test\Support\InMemoryAlerts;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An import breaks, somebody is told once, and then it is fixed.
 */
class AlertLifecycleTest extends TestCase
{
    private const SECTION = 'commerce_import_monitor';
    private const NOW = '2026-08-27 09:00:00';

    private InMemoryAlerts $alerts;
    private LoggerInterface&MockObject $logger;

    /** @var array<string, CheckResult> The state of the world, by check code. */
    private array $world = [];

    /** @var array<int, array{channel: string, subject: string, items: int}> */
    private array $sent = [];

    /** @var array<string, bool> Channel code => enabled. */
    private array $channels = ['email' => true];

    private bool $emailFails = false;

    /** @var array<string, string> */
    private array $settings = [];

    protected function setUp(): void
    {
        $this->alerts = new InMemoryAlerts();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->world = [];
        $this->sent = [];
        $this->channels = ['email' => true];
        $this->emailFails = false;
        $this->settings = [
            self::SECTION . '/general/enabled' => '1',
            self::SECTION . '/notification/recipients' => 'ops@example.test',
            self::SECTION . '/general/retention_days' => '30',
        ];
    }

    public function testABrokenImportRaisesAnAlertAndNotifiesOnce(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed has not arrived.');

        $raised = $this->runMonitor();

        $this->assertSame(1, $raised);
        $this->assertCount(1, $this->sent);
        $this->assertSame(AlertInterface::STATUS_OPEN, $this->alerts->statusOf($this->fingerprintOf('feed_file')));
    }

    /**
     * This is the whole reason the fingerprint exists.
     */
    public function testAFaultThatPersistsIsNotReAnnouncedOnEveryRun(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed has not arrived.');

        for ($run = 0; $run < 12; $run++) {
            $this->runMonitor();
        }

        $this->assertCount(1, $this->sent, 'Twelve failing runs, one message.');
        $this->assertSame(12, $this->alerts->occurrencesOf($this->fingerprintOf('feed_file')));
    }

    /**
     * The message carries a timestamp and a count, so it is genuinely different
     * on every run.
     */
    public function testAChangingMessageDoesNotMakeItANewAlert(): void
    {
        foreach (['09:00', '09:05', '09:10'] as $time) {
            $this->world['feed_file'] = $this->failing(
                'feed_file',
                'nightly_feed',
                sprintf('The nightly feed has not arrived (checked at %s).', $time)
            );
            $this->runMonitor();
        }

        $this->assertCount(1, $this->sent);
    }

    /**
     * A store with four broken imports should get one email listing four
     * things, not four emails - the difference between a report and a flood.
     */
    public function testTwoFaultsInOneRunAreOneMessageListingBoth(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');
        $this->world['task_run'] = $this->failing('task_run', 'price_import', 'The price import is stuck.');

        $raised = $this->runMonitor();

        $this->assertSame(2, $raised);
        $this->assertCount(1, $this->sent);
        $this->assertSame(2, $this->sent[0]['items']);
    }

    /**
     * An import that starts working again is the ordinary end of an alert.
     */
    public function testAFaultThatClearsResolvesItsOwnAlert(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');
        $this->runMonitor();

        $this->world['feed_file'] = new CheckResult(true, 'feed_file');
        $this->runMonitor();

        $this->assertSame(AlertInterface::STATUS_RESOLVED, $this->alerts->statusOf($this->fingerprintOf('feed_file')));
    }

    /**
     * The second notification is the point.
     */
    public function testAFaultThatReturnsIsAnnouncedAgain(): void
    {
        $failing = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');
        $healthy = new CheckResult(true, 'feed_file');

        $this->world['feed_file'] = $failing;
        $this->runMonitor();

        $this->world['feed_file'] = $healthy;
        $this->runMonitor();

        $this->world['feed_file'] = $failing;
        $this->runMonitor();

        $this->assertCount(2, $this->sent);
    }

    /**
     * The checks are bound by the store, so one of them is somebody else's code
     * reaching somebody else's system.
     */
    public function testACheckThatThrowsDoesNotStopTheOtherChecks(): void
    {
        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->world['broken_check'] = null;
        $this->world['task_run'] = $this->failing('task_run', 'price_import', 'The price import is stuck.');

        $this->runMonitor();

        $this->assertSame(AlertInterface::STATUS_OPEN, $this->alerts->statusOf($this->fingerprintOf('task_run')));
    }

    /**
     * The row is written before anything is dispatched, so a Slack outage costs
     * only the notification.
     */
    public function testAChannelThatFailsDoesNotLoseTheAlert(): void
    {
        $this->emailFails = true;
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');

        $this->runMonitor();

        $this->assertSame(AlertInterface::STATUS_OPEN, $this->alerts->statusOf($this->fingerprintOf('feed_file')));
    }

    public function testOneUnreachableChannelDoesNotStopTheRest(): void
    {
        $this->channels = ['email' => true, 'slack' => true];
        $this->emailFails = true;
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');

        $this->runMonitor();

        $this->assertSame(['slack'], array_column($this->sent, 'channel'));
    }

    public function testADisabledChannelIsNotAsked(): void
    {
        $this->channels = ['email' => true, 'slack' => false];
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');

        $this->runMonitor();

        $this->assertSame(['email'], array_column($this->sent, 'channel'));
    }

    public function testWithMonitoringOffNothingIsRaised(): void
    {
        $this->settings[self::SECTION . '/general/enabled'] = '0';
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');

        $this->assertSame(0, $this->runMonitor());
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->alerts->rows);
    }

    /**
     * The class the crontab names is exercised here rather than the runner and
     * manager directly.
     */
    public function testTheCronEntryPointRunsTheChecksAndProcessesThem(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');

        $results = (new ImportMonitor($this->checkRunner(), $this->alertManager()))->run();

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isHealthy);
        $this->assertSame(AlertInterface::STATUS_OPEN, $this->alerts->statusOf($this->fingerprintOf('feed_file')));
        $this->assertCount(1, $this->sent);
    }

    public function testACorrectlySignedAcknowledgementSilencesTheAlert(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');
        $this->runMonitor();

        $alertId = (int) array_key_first($this->alerts->rows);
        $signer = $this->signer();

        $this->assertTrue($this->alertManager()->acknowledge($alertId, $signer->sign($alertId), $signer));
        $this->assertSame(
            AlertInterface::STATUS_ACKNOWLEDGED,
            $this->alerts->statusOf($this->fingerprintOf('feed_file'))
        );
    }

    /**
     * The link goes out by email and the id in it is a small integer, so
     * walking the ids is the obvious attack.
     */
    public function testASignatureIssuedForAnotherAlertDoesNotWork(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');
        $this->world['task_run'] = $this->failing('task_run', 'price_import', 'The price import is stuck.');
        $this->runMonitor();

        $ids = array_keys($this->alerts->rows);
        $signer = $this->signer();

        $this->assertFalse($this->alertManager()->acknowledge((int) $ids[0], $signer->sign((int) $ids[1]), $signer));
        $this->assertSame(AlertInterface::STATUS_OPEN, $this->alerts->statusOf($this->fingerprintOf('feed_file')));
    }

    public function testAnAlteredAcknowledgementDoesNothing(): void
    {
        $this->world['feed_file'] = $this->failing('feed_file', 'nightly_feed', 'The nightly feed is missing.');
        $this->runMonitor();

        $alertId = (int) array_key_first($this->alerts->rows);
        $signer = $this->signer();
        $manager = $this->alertManager();

        $this->assertFalse($manager->acknowledge($alertId, '', $signer));
        $this->assertFalse($manager->acknowledge($alertId, 'deadbeef', $signer));
        $this->assertFalse($manager->acknowledge(0, $signer->sign(0), $signer));
    }

    /**
     * One pass of the cron: run every check, then act on what they said.
     *
     * @return int Alerts newly raised by this pass.
     */
    private function runMonitor(): int
    {
        return $this->alertManager()->process($this->checkRunner()->runAll());
    }

    private function checkRunner(): CheckRunner
    {
        $checks = [];

        foreach ($this->world as $code => $result) {
            $checks[$code] = new class ($code, $result) implements ImportCheckInterface {
                public function __construct(
                    private readonly string $code,
                    private readonly ?CheckResult $result
                ) {
                }

                public function getCode(): string
                {
                    return $this->code;
                }

                public function getLabel(): string
                {
                    return ucfirst(str_replace('_', ' ', $this->code));
                }

                public function run(): CheckResult
                {
                    // A null result stands for a check that throws - which is
                    // somebody else's code reaching somebody else's system.
                    return $this->result ?? throw new RuntimeException('the check itself failed');
                }
            };
        }

        return new CheckRunner($this->logger, $checks);
    }

    private function alertManager(): AlertManager
    {
        return new AlertManager(
            $this->alerts,
            $this->dispatcher(),
            $this->config(),
            $this->dateTime(),
            $this->logger
        );
    }

    private function dispatcher(): NotificationDispatcher
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://example.test/importmonitor/alerts/acknowledge');

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime(self::NOW));

        return new NotificationDispatcher(
            $this->alerts,
            $this->signer(),
            $urlBuilder,
            $timezone,
            $this->config(),
            $this->logger,
            $this->alertChannels()
        );
    }

    /**
     * @return array<string, AlertChannelInterface>
     */
    private function alertChannels(): array
    {
        $channels = [];

        foreach ($this->channels as $code => $enabled) {
            $channels[$code] = new class ($code, $enabled, $this) implements AlertChannelInterface {
                public function __construct(
                    private readonly string $code,
                    private readonly bool $enabled,
                    private readonly AlertLifecycleTest $test
                ) {
                }

                public function getCode(): string
                {
                    return $this->code;
                }

                public function isEnabled(): bool
                {
                    return $this->enabled;
                }

                public function send(AlertMessage $message): bool
                {
                    return $this->test->recordSend($this->code, $message);
                }
            };
        }

        return $channels;
    }

    /**
     * @internal Called by the channel doubles above.
     */
    public function recordSend(string $code, AlertMessage $message): bool
    {
        if ($code === 'email' && $this->emailFails) {
            throw new RuntimeException('the mail server is unreachable');
        }

        $this->sent[] = [
            'channel' => $code,
            'subject' => $message->subject,
            'items' => count($message->items),
        ];

        return true;
    }

    private function signer(): AlertSigner
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn('a-crypt-key-for-this-installation');

        return new AlertSigner($deploymentConfig);
    }

    private function config(): Config
    {
        // The encryptor is reached only by the Slack token, which nothing in an
        // alert's lifecycle touches.
        return new Config(
            $this->scopeConfig($this->settings),
            self::SECTION,
            $this->createMock(EncryptorInterface::class)
        );
    }

    private function dateTime(): DateTime
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW);

        return $dateTime;
    }

    private function failing(string $code, string $seed, string $message): CheckResult
    {
        return new CheckResult(false, $code, $seed, $message);
    }

    private function fingerprintOf(string $checkCode): string
    {
        foreach ($this->alerts->rows as $row) {
            if (str_starts_with((string) $row[AlertInterface::MESSAGE], '')) {
                // The row is found by the message its check produced rather
                // than by recomputing the fingerprint.
                if ($this->messageBelongsTo($checkCode, (string) $row[AlertInterface::MESSAGE])) {
                    return (string) $row[AlertInterface::FINGERPRINT];
                }
            }
        }

        return '';
    }

    private function messageBelongsTo(string $checkCode, string $message): bool
    {
        return match ($checkCode) {
            'feed_file' => str_contains($message, 'nightly feed'),
            'task_run' => str_contains($message, 'price import'),
            default => false,
        };
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
