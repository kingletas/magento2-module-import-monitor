<?php
/**
 * AlertingCostTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Performance;

use Commerce\Foundation\Test\Support\BudgetAssertions;
use Commerce\ImportMonitor\Api\AlertChannelInterface;
use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\Alert\AlertManager;
use Commerce\ImportMonitor\Model\Alert\AlertSigner;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use Commerce\ImportMonitor\Model\Notification\NotificationDispatcher;
use Commerce\ImportMonitor\Test\Behaviour\Fake\InMemoryAlerts;
use Commerce\ImportMonitor\Test\Performance\Fake\CountingAlerts;
use Commerce\ImportMonitor\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/**
 * What being told costs.
 */
class AlertingCostTest extends TestCase
{
    use BudgetAssertions;

    private const SECTION = 'commerce_import_monitor';
    private const NOW = '2026-08-27 09:00:00';

    private InMemoryAlerts $alerts;
    private RecordingLogger $logger;

    private int $rowLookups = 0;
    private int $signatures = 0;
    private int $sends = 0;

    /** @var string[] */
    private array $channelCodes = ['email'];

    protected function setUp(): void
    {
        $this->alerts = new InMemoryAlerts();
        $this->logger = new RecordingLogger();
        $this->rowLookups = 0;
        $this->signatures = 0;
        $this->sends = 0;
        $this->channelCodes = ['email'];
    }

    /**
     * Twelve checks reporting healthy resolve in one statement rather than
     * twelve queries.
     */
    public function testAHealthyPassCostsOneStatementWhateverIsBeingWatched(): void
    {
        $this->assertConstantCost(
            'alert-table statements for a healthy pass',
            function (int $checks): int {
                $this->alerts = new CountingAlerts($this);
                $this->rowLookups = 0;

                $results = [];

                for ($i = 0; $i < $checks; $i++) {
                    $results[] = new CheckResult(true, 'check_' . $i);
                }

                $this->manager()->process($results);

                return $this->rowLookups;
            },
            [1, 50]
        );
    }

    /**
     * A broken import is broken for hours.
     */
    public function testAPersistentFaultCostsNothingAfterTheFirstPass(): void
    {
        $failing = [new CheckResult(false, 'feed_file', 'nightly_feed', 'The nightly feed is missing.')];
        $manager = $this->manager();

        $manager->process($failing);
        $afterFirst = ['sends' => $this->sends, 'signatures' => $this->signatures];

        for ($pass = 0; $pass < 24; $pass++) {
            $manager->process($failing);
        }

        $this->assertSame($afterFirst['sends'], $this->sends, 'Two hours of a broken feed is one message.');
        $this->assertSame($afterFirst['signatures'], $this->signatures, 'And one acknowledge link.');
    }

    /**
     * Twelve imports failing at once is one email listing twelve things.
     */
    public function testManyFaultsInOnePassAreOneMessage(): void
    {
        $this->assertConstantCost(
            'messages sent for one pass',
            function (int $faults): int {
                $this->alerts = new InMemoryAlerts();
                $this->sends = 0;

                $results = [];

                for ($i = 0; $i < $faults; $i++) {
                    $results[] = new CheckResult(false, 'check_' . $i, 'seed_' . $i, 'Check ' . $i . ' failed.');
                }

                $this->manager()->process($results);

                return $this->sends;
            },
            [1, 12]
        );
    }

    /**
     * Each link costs a row lookup and an HMAC.
     */
    public function testAnAcknowledgeLinkIsBuiltOncePerFaultRatherThanPerChannel(): void
    {
        $this->channelCodes = ['email', 'slack', 'webhook'];

        $this->manager()->process([
            new CheckResult(false, 'feed_file', 'nightly_feed', 'The nightly feed is missing.'),
            new CheckResult(false, 'task_run', 'price_import', 'The price import is stuck.'),
        ]);

        $this->assertSame(3, $this->sends, 'All three channels were used.');
        $this->assertCostAtMost('acknowledge links built for two faults across three channels', 2, $this->signatures);
    }

    /**
     * A store that has switched this off should not be paying a query every
     * five minutes to be told so.
     */
    public function testWithMonitoringOffTheAlertTableIsNotTouched(): void
    {
        $this->alerts = new CountingAlerts($this);

        $manager = $this->manager(enabled: false);
        $manager->process([new CheckResult(false, 'feed_file', 'nightly_feed', 'The nightly feed is missing.')]);

        $this->assertSame(0, $this->rowLookups);
        $this->assertSame(0, $this->sends);
    }

    /**
     * @internal Called by the counting alert table.
     */
    public function recordRowStatement(): void
    {
        $this->rowLookups++;
    }

    /**
     * @internal Called by the signer double.
     */
    public function recordSignature(): void
    {
        $this->signatures++;
    }

    /**
     * @internal Called by the channel doubles.
     */
    public function recordSend(): void
    {
        $this->sends++;
    }

    private function manager(bool $enabled = true): AlertManager
    {
        return new AlertManager(
            $this->alerts,
            $this->dispatcher(),
            $this->config($enabled),
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
            $this->channels()
        );
    }

    /**
     * @return array<string, AlertChannelInterface>
     */
    private function channels(): array
    {
        $channels = [];

        foreach ($this->channelCodes as $code) {
            $channels[$code] = new class ($code, $this) implements AlertChannelInterface {
                public function __construct(
                    private readonly string $code,
                    private readonly AlertingCostTest $test
                ) {
                }

                public function getCode(): string
                {
                    return $this->code;
                }

                public function isEnabled(): bool
                {
                    return true;
                }

                public function send(AlertMessage $message): bool
                {
                    $this->test->recordSend();

                    return true;
                }
            };
        }

        return $channels;
    }

    /**
     * A real signer, with its work counted.
     */
    private function signer(): AlertSigner
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn('a-crypt-key-for-this-installation');

        return new class ($deploymentConfig, $this) extends AlertSigner {
            public function __construct(
                DeploymentConfig $deploymentConfig,
                private readonly AlertingCostTest $test
            ) {
                parent::__construct($deploymentConfig);
            }

            public function sign(int $alertId): string
            {
                $this->test->recordSignature();

                return parent::sign($alertId);
            }
        };
    }

    private function config(bool $enabled = true): Config
    {
        return new Config(
            new ArrayScopeConfig([
                self::SECTION . '/general/enabled' => $enabled ? '1' : '0',
                self::SECTION . '/notification/recipients' => 'ops@example.test',
            ]),
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
}
