<?php
/**
 * NotificationDispatcherTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Notification;

use Commerce\ImportMonitor\Api\AlertChannelInterface;
use Commerce\ImportMonitor\Model\Alert\AlertSigner;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use Commerce\ImportMonitor\Model\Notification\NotificationDispatcher;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Commerce\ImportMonitor\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use DateTimeImmutable;
use InvalidArgumentException;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class NotificationDispatcherTest extends TestCase
{
    /** @var array<string, array<int, AlertMessage>> Channel code => messages it received. */
    private array $delivered = [];

    /** Whether the alert row behind a fingerprint has been written yet. */
    private bool $alertRowExists = true;

    /** @var array<int, array{route: string, params: array<string, mixed>}> */
    private array $urls = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->delivered = [];
        $this->urls = [];
        $this->logger = new RecordingLogger();
        $this->alertRowExists = true;
    }

    public function testEveryEnabledChannelIsHandedTheMessage(): void
    {
        $this->dispatcher([
            'email' => $this->channel(),
            'slack' => $this->channel(),
        ])->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);

        self::assertCount(1, $this->delivered['email']);
        self::assertCount(1, $this->delivered['slack']);
    }

    public function testADisabledChannelIsSkipped(): void
    {
        $this->dispatcher([
            'email' => $this->channel(),
            'slack' => $this->channel(enabled: false),
        ])->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);

        self::assertCount(1, $this->delivered['email']);
        self::assertArrayNotHasKey('slack', $this->delivered);
    }

    /**
     * A message with no items is nothing to say; sending it would page someone
     * with an empty alert.
     */
    public function testNothingIsSentWhenThereAreNoFailures(): void
    {
        $this->dispatcher(['email' => $this->channel()])->dispatchRaised([]);

        self::assertSame([], $this->delivered);
    }

    /**
     * A healthy result carries no fingerprint or message, and no empty
     * notification is sent.
     */
    public function testAResultWithNothingToReportIsSkipped(): void
    {
        $this->dispatcher(['email' => $this->channel()])
            ->dispatchRaised([new CheckResult(true, 'feed_file')]);

        self::assertSame([], $this->delivered);
    }

    public function testTheSubjectCountsTheFailures(): void
    {
        $dispatcher = $this->dispatcher(['email' => $this->channel()]);

        $dispatcher->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);
        self::assertSame('Import monitor: a check has failed', $this->delivered['email'][0]->subject);

        $this->delivered = [];
        $dispatcher->dispatchRaised([
            $this->failure('feed_file', 'fp-feed', 'No feed file for today.'),
            $this->failure('import_task_run', 'fp-task', 'Nightly import is stuck.'),
        ]);
        self::assertSame('Import monitor: 2 checks have failed', $this->delivered['email'][0]->subject);
    }

    /**
     * Store timezone, not PHP's default, so timestamps line up with the admin
     * grid.
     */
    public function testTheTimestampIsRenderedInTheStoreTimezone(): void
    {
        $this->dispatcher(['email' => $this->channel()])
            ->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);

        self::assertSame('2026-08-26 06:00:00 UTC', $this->delivered['email'][0]->occurredAt);
    }

    /**
     * Off unless the store asked for it: the hostname publishes internal naming
     * to a workspace.
     */
    public function testTheHostnameIsWithheldUnlessTheStoreAsksForIt(): void
    {
        $this->dispatcher(['email' => $this->channel()])
            ->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);
        self::assertNull($this->delivered['email'][0]->hostname);

        $this->delivered = [];
        $this->dispatcher(['email' => $this->channel()], includeHostname: true)
            ->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);
        self::assertNotNull($this->delivered['email'][0]->hostname);
    }

    /**
     * The signature is what stops anyone walking sequential ids to silence
     * every alert in the system.
     */
    public function testTheAcknowledgeLinkIsSignedAndSecure(): void
    {
        $this->dispatcher(['email' => $this->channel()])
            ->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);

        self::assertSame('importmonitor/alerts/acknowledge', $this->urls[0]['route']);
        self::assertSame(9, $this->urls[0]['params']['id']);
        self::assertSame('signature-for-9', $this->urls[0]['params']['token']);
        self::assertTrue($this->urls[0]['params']['_secure']);
    }

    /**
     * A failure whose alert row is not there is still reported, without a link
     * that would 404.
     */
    public function testAFailureWithNoStoredAlertIsStillReportedWithoutALink(): void
    {
        $this->alertRowExists = false;

        $this->dispatcher(['email' => $this->channel()])
            ->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);

        $items = $this->delivered['email'][0]->items;
        self::assertCount(1, $items);
        self::assertNull($items[0]['acknowledge_url']);
    }

    /**
     * One unreachable destination must not stop the others: an alert that
     * reached email is worth more than a run that aborted on Slack.
     */
    public function testAFailingChannelIsLoggedAndTheOthersStillReceive(): void
    {
        $this->dispatcher([
            'slack' => $this->channel(throws: new RuntimeException('Connection timed out')),
            'email' => $this->channel(),
        ])->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);

        self::assertCount(1, $this->delivered['email']);
        self::assertCount(1, $this->logger->errors);
        self::assertStringContainsString('slack', $this->logger->errors[0]);
    }

    /**
     * A channel reporting a non-delivery has not thrown, so the warning is the
     * only record.
     */
    public function testAChannelThatReportsNonDeliveryIsWarnedAbout(): void
    {
        $this->dispatcher(['slack' => $this->channel(delivers: false)])
            ->dispatchRaised([$this->failure('feed_file', 'fp-feed', 'No feed file for today.')]);

        self::assertCount(1, $this->logger->warnings);
        self::assertStringContainsString('did not deliver', $this->logger->warnings[0]);
    }

    /**
     * The channel list is a di.xml array, so a typo binds something that is not
     * a channel at all.
     */
    public function testSomethingThatIsNotAChannelIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('slack');

        $this->dispatcher(['slack' => new stdClass()]);
    }

    private function failure(string $code, string $seed, string $message): CheckResult
    {
        return new CheckResult(false, $code, $seed, $message);
    }

    private function channel(
        bool $enabled = true,
        bool $delivers = true,
        ?\Throwable $throws = null
    ): AlertChannelInterface&MockObject {
        $channel = $this->createMock(AlertChannelInterface::class);
        $channel->method('isEnabled')->willReturn($enabled);
        $channel->method('send')->willReturnCallback(
            function (AlertMessage $message) use ($throws, $delivers): bool {
                if ($throws !== null) {
                    throw $throws;
                }

                $this->delivered[$this->codeFor($message)][] = $message;

                return $delivers;
            }
        );

        return $channel;
    }

    /**
     * The dispatcher does not tell a channel its own code, so the tests key on
     * the channel map.
     */
    private string $currentChannelCode = '';

    private function codeFor(AlertMessage $message): string
    {
        return $this->currentChannelCode;
    }

    /**
     * @param array<string, mixed> $channels
     */
    private function dispatcher(array $channels, bool $includeHostname = false): NotificationDispatcher
    {
        $wrapped = [];

        foreach ($channels as $code => $channel) {
            $wrapped[$code] = $channel instanceof MockObject && $channel instanceof AlertChannelInterface
                ? $this->tagged($code, $channel)
                : $channel;
        }

        $alertResource = $this->createMock(AlertResource::class);
        $alertResource->method('loadByFingerprint')->willReturnCallback(
            fn (string $fingerprint): ?array => $this->alertRowExists ? ['alert_id' => 9] : null
        );

        $signer = $this->createMock(AlertSigner::class);
        $signer->method('sign')->willReturnCallback(
            static fn (int $alertId): string => 'signature-for-' . $alertId
        );

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            function (string $route, ?array $params = null): string {
                $this->urls[] = ['route' => $route, 'params' => (array) $params];

                return 'https://shop.test/' . $route;
            }
        );

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new DateTimeImmutable('2026-08-26 06:00:00', new \DateTimeZone('UTC')));

        $config = new Config(
            new ArrayScopeConfig([
                'test_importmonitor/notification/include_hostname' => $includeHostname ? '1' : '0',
            ]),
            'test_importmonitor',
            $this->createMock(EncryptorInterface::class)
        );

        return new NotificationDispatcher(
            $alertResource,
            $signer,
            $urlBuilder,
            $timezone,
            $config,
            $this->logger,
            $wrapped
        );
    }

    private function tagged(string $code, AlertChannelInterface&MockObject $channel): AlertChannelInterface
    {
        return new class ($code, $channel, $this) implements AlertChannelInterface {
            public function __construct(
                private readonly string $code,
                private readonly AlertChannelInterface $inner,
                private readonly NotificationDispatcherTest $test
            ) {
            }

            public function getCode(): string
            {
                return $this->code;
            }

            public function isEnabled(): bool
            {
                return $this->inner->isEnabled();
            }

            public function send(AlertMessage $message): bool
            {
                $this->test->useCode($this->code);

                return $this->inner->send($message);
            }
        };
    }

    public function useCode(string $code): void
    {
        $this->currentChannelCode = $code;
    }
}
