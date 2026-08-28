<?php
/**
 * EmailChannelTest.php
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
use Commerce\ImportMonitor\Model\Notification\AlertMessageHtmlRenderer;
use Commerce\ImportMonitor\Model\Notification\Channel\EmailChannel;
use Commerce\ImportMonitor\Test\Unit\Fake\RealEscaper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TypeError;

class EmailChannelTest extends TestCase
{
    /** @var array<int, string[]> One entry per addTo() call. */
    private array $addressed = [];

    /** @var array<string, mixed> */
    private array $templateVars = [];

    /** @var string[] */
    private array $translationCalls = [];

    private ?string $templateId = null;

    /** @var array<string, mixed> */
    private array $templateOptions = [];

    private ?string $senderScope = null;
    private int $sent = 0;
    private ?\Throwable $sendFailure = null;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->addressed = [];
        $this->templateVars = [];
        $this->translationCalls = [];
        $this->templateId = null;
        $this->templateOptions = [];
        $this->senderScope = null;
        $this->sent = 0;
        $this->sendFailure = null;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testItAnnouncesItsCode(): void
    {
        $channel = $this->channel();

        $this->assertInstanceOf(AlertChannelInterface::class, $channel);
        $this->assertSame('email', $channel->getCode());
    }

    /**
     * A store with no recipients has not configured email; attempting a send
     * would build a transport with no addresses and fail once per alert.
     */
    public function testTheChannelIsOffUntilRecipientsAreConfigured(): void
    {
        $this->assertFalse($this->channel(recipients: '')->isEnabled());
        $this->assertTrue($this->channel()->isEnabled());
    }

    public function testAnUnconfiguredChannelRefusesToSend(): void
    {
        $this->assertFalse($this->channel(recipients: '')->send($this->message()));
        $this->assertSame(0, $this->sent);
    }

    /**
     * One message, not one per recipient.
     */
    public function testEveryRecipientGoesOnOneMessage(): void
    {
        $this->assertTrue($this->channel(recipients: 'a@example.test,b@example.test')->send($this->message()));

        $this->assertSame(1, $this->sent);
        $this->assertSame([['a@example.test', 'b@example.test']], $this->addressed);
    }

    public function testTheConfiguredTemplateAndSenderAreUsed(): void
    {
        $this->channel()->send($this->message());

        $this->assertSame('commerce_import_monitor_alert', $this->templateId);
        $this->assertSame('general', $this->senderScope);
    }

    /**
     * Rendered in the admin area and the default store, so no store view's
     * theme fallback applies.
     */
    public function testTheTemplateIsRenderedInTheAdminAreaAtDefaultScope(): void
    {
        $this->channel()->send($this->message());

        $this->assertSame(
            ['area' => 'adminhtml', 'store' => Store::DEFAULT_STORE_ID],
            $this->templateOptions
        );
    }

    /**
     * Both renderings go to the template, so a store can use whichever its own
     * layout wants without the channel deciding for it.
     */
    public function testBothRenderingsOfTheMessageReachTheTemplate(): void
    {
        $this->channel()->send($this->message());

        $this->assertStringContainsString('<ul>', $this->templateVars['body_html']);
        $this->assertStringContainsString('No feed file for today.', $this->templateVars['body_text']);
        $this->assertSame(1, $this->templateVars['count']);
        $this->assertSame('2026-08-26 06:00:00', $this->templateVars['occurred_at']);
    }

    /**
     * A mail server refusing the message must not take the rest of the check
     * run - or the other channels - with it.
     */
    public function testAFailedSendIsReportedAndLoggedRatherThanThrown(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->sendFailure = new RuntimeException('SMTP connection refused');

        $this->assertFalse($this->channel()->send($this->message()));
    }

    public function testInlineTranslationIsSuspendedForTheRender(): void
    {
        $this->channel()->send($this->message());

        $this->assertSame(['suspend', 'resume'], $this->translationCalls);
    }

    /**
     * Restored in a `finally`, not in a `catch (Exception)`.
     */
    public function testInlineTranslationIsRestoredEvenAfterAnError(): void
    {
        $this->sendFailure = new TypeError('Template variable is not a string');

        $this->assertFalse($this->channel()->send($this->message()));
        $this->assertSame(['suspend', 'resume'], $this->translationCalls);
    }

    private function message(): AlertMessage
    {
        return new AlertMessage(
            'Import monitor: a check has failed',
            [['message' => 'No feed file for today.', 'acknowledge_url' => 'https://shop.test/ack/1']],
            '2026-08-26 06:00:00'
        );
    }

    private function channel(string $recipients = 'ops@example.test'): EmailChannel
    {
        $config = new Config(
            $this->scopeConfig(['test_importmonitor/notification/recipients' => $recipients]),
            'test_importmonitor',
            $this->createMock(EncryptorInterface::class)
        );

        $inlineTranslation = $this->createMock(StateInterface::class);
        $inlineTranslation->method('suspend')->willReturnCallback(function (): void {
            $this->translationCalls[] = 'suspend';
        });
        $inlineTranslation->method('resume')->willReturnCallback(function (): void {
            $this->translationCalls[] = 'resume';
        });

        return new EmailChannel(
            $this->transportBuilder(),
            new AlertMessageHtmlRenderer(RealEscaper::create()),
            $inlineTranslation,
            $config,
            $this->logger
        );
    }

    private function transportBuilder(): TransportBuilder&MockObject
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function (): void {
            if ($this->sendFailure !== null) {
                throw $this->sendFailure;
            }

            $this->sent++;
        });

        $builder = $this->createMock(TransportBuilder::class);
        $builder->method('setTemplateIdentifier')->willReturnCallback(
            function (string $id) use (&$builder): TransportBuilder {
                $this->templateId = $id;

                return $builder;
            }
        );
        $builder->method('setTemplateOptions')->willReturnCallback(
            function (array $options) use (&$builder): TransportBuilder {
                $this->templateOptions = $options;

                return $builder;
            }
        );
        $builder->method('setTemplateVars')->willReturnCallback(
            function (array $vars) use (&$builder): TransportBuilder {
                $this->templateVars = $vars;

                return $builder;
            }
        );
        $builder->method('setFromByScope')->willReturnCallback(
            function ($from, $scope = null) use (&$builder): TransportBuilder {
                $this->senderScope = (string) $from;

                return $builder;
            }
        );
        $builder->method('addTo')->willReturnCallback(
            function ($to, $name = '') use (&$builder): TransportBuilder {
                $this->addressed[] = (array) $to;

                return $builder;
            }
        );
        $builder->method('getTransport')->willReturn($transport);

        return $builder;
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
