<?php
/**
 * SlackChannelTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Notification\Channel;

use Commerce\ImportMonitor\Api\AlertChannelInterface;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use Commerce\ImportMonitor\Model\Notification\Channel\SlackChannel;
use Commerce\ImportMonitor\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\ImportMonitor\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SlackChannelTest extends TestCase
{
    /** @var array<int, array{url: string, body: string}> */
    private array $posts = [];

    /** @var array<string, string> */
    private array $headers = [];

    private ?int $timeout = null;
    private int $status = 200;
    private string $body = '{"ok":true}';
    private ?\Throwable $postFailure = null;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->posts = [];
        $this->headers = [];
        $this->timeout = null;
        $this->status = 200;
        $this->body = '{"ok":true}';
        $this->postFailure = null;
        $this->logger = new RecordingLogger();
    }

    public function testItAnnouncesItsCode(): void
    {
        $channel = $this->channel();

        $this->assertInstanceOf(AlertChannelInterface::class, $channel);
        $this->assertSame('slack', $channel->getCode());
    }

    /**
     * A half-configured integration would attempt a post that cannot succeed on
     * every alert, and log the failure each time.
     */
    public function testTheChannelIsOffUntilSlackIsFullyConfigured(): void
    {
        $this->assertTrue($this->channel()->isEnabled());
        $this->assertFalse($this->channel(token: '')->isEnabled());
        $this->assertFalse($this->channel(channel: '')->isEnabled());
        $this->assertFalse($this->channel(enabled: false)->isEnabled());
    }

    public function testTheMessageIsPostedToTheChatApi(): void
    {
        $this->assertTrue($this->channel()->send($this->message()));

        $this->assertCount(1, $this->posts);
        $this->assertSame('https://slack.com/api/chat.postMessage', $this->posts[0]['url']);
    }

    public function testThePayloadCarriesTheChannelAndThePlainTextRendering(): void
    {
        $this->channel()->send($this->message());

        $payload = (array) (new Json())->unserialize($this->posts[0]['body']);

        $this->assertSame('ops-alerts', $payload['channel']);
        $this->assertStringContainsString('No feed file for today.', $payload['text']);
    }

    /**
     * The token is a bearer credential; it belongs in the Authorization header
     * rather than in a query string that proxies and access logs record.
     */
    public function testTheTokenTravelsAsABearerHeader(): void
    {
        $this->channel()->send($this->message());

        $this->assertSame('Bearer xoxb-real-token', $this->headers['Authorization']); // pragma: allowlist secret
        $this->assertStringNotContainsString('xoxb', $this->posts[0]['url']);
        $this->assertStringNotContainsString('xoxb', $this->posts[0]['body']);
    }

    public function testTheRequestDeclaresItsJsonBody(): void
    {
        $this->channel()->send($this->message());

        $this->assertStringContainsString('application/json', $this->headers['Content-Type']);
    }

    /**
     * Alerts are posted from a cron run; an unresponsive Slack must not hold
     * the run open indefinitely.
     */
    public function testTheRequestIsBounded(): void
    {
        $this->channel()->send($this->message());

        $this->assertNotNull($this->timeout);
        $this->assertGreaterThan(0, $this->timeout);
    }

    /**
     * Slack answers HTTP 200 for most failures, with `ok: false` in the body.
     */
    public function testATwoHundredWithOkFalseIsNotADelivery(): void
    {
        $this->body = '{"ok":false,"error":"channel_not_found"}';

        $this->assertFalse($this->channel()->send($this->message()));
        $this->assertCount(1, $this->logger->errors);
        $this->assertStringContainsString('channel_not_found', $this->logger->errors[0]);
    }

    public function testAnUnexpectedStatusIsReportedWithItsCode(): void
    {
        $this->status = 503;

        $this->assertFalse($this->channel()->send($this->message()));
        $this->assertStringContainsString('503', $this->logger->errors[0]);
    }

    /**
     * A decode error must not escape as an exception from a notification
     * channel.
     */
    public function testAnUnparseableResponseIsReportedRatherThanThrown(): void
    {
        $this->body = '<html>Gateway timeout</html>';

        $this->assertFalse($this->channel()->send($this->message()));
        $this->assertStringContainsString('unparseable', $this->logger->errors[0]);
    }

    public function testAResponseThatIsNotAnObjectIsRejected(): void
    {
        $this->body = '"ok"';

        $this->assertFalse($this->channel()->send($this->message()));
        $this->assertCount(1, $this->logger->errors);
    }

    /**
     * One unreachable destination must not stop the check run or the other
     * channels.
     */
    public function testATransportFailureIsContainedAndLogged(): void
    {
        $this->postFailure = new RuntimeException('Connection timed out');

        $this->assertFalse($this->channel()->send($this->message()));
        $this->assertCount(1, $this->logger->errors);
    }

    private function message(): AlertMessage
    {
        return new AlertMessage(
            'Import monitor: a check has failed',
            [['message' => 'No feed file for today.', 'acknowledge_url' => null]],
            '2026-08-26 06:00:00'
        );
    }

    private function channel(
        bool $enabled = true,
        string $token = 'encrypted:xoxb-real-token', // pragma: allowlist secret
        string $channel = '#ops-alerts'
    ): SlackChannel {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $value): string => str_starts_with($value, 'encrypted:')
                ? substr($value, strlen('encrypted:'))
                : $value
        );

        $config = new Config(
            new ArrayScopeConfig([
                'test_importmonitor/slack/enabled' => $enabled ? '1' : '0',
                'test_importmonitor/slack/token' => $token,
                'test_importmonitor/slack/channel' => $channel,
            ]),
            'test_importmonitor',
            $encryptor
        );

        return new SlackChannel($this->curlFactory(), new Json(), $config, $this->logger);
    }

    private function curlFactory(): CurlFactory&MockObject
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('setTimeout')->willReturnCallback(function (int $seconds): void {
            $this->timeout = $seconds;
        });
        $curl->method('addHeader')->willReturnCallback(function (string $name, $value): void {
            $this->headers[$name] = (string) $value;
        });
        $curl->method('post')->willReturnCallback(function (string $url, $body): void {
            if ($this->postFailure !== null) {
                throw $this->postFailure;
            }

            $this->posts[] = ['url' => $url, 'body' => (string) $body];
        });
        $curl->method('getStatus')->willReturnCallback(fn (): int => $this->status);
        $curl->method('getBody')->willReturnCallback(fn (): string => $this->body);

        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        return $factory;
    }
}
