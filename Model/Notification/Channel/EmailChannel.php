<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Notification\Channel;

use Commerce\ImportMonitor\Api\AlertChannelInterface;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use Commerce\ImportMonitor\Model\Notification\AlertMessageHtmlRenderer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Emails alerts to the configured recipients.
 */
class EmailChannel implements AlertChannelInterface
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly AlertMessageHtmlRenderer $htmlRenderer,
        private readonly StateInterface $inlineTranslation,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly string $code = 'email'
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isEnabled(): bool
    {
        return $this->config->getRecipients() !== [];
    }

    public function send(AlertMessage $message): bool
    {
        $recipients = $this->config->getRecipients();

        if ($recipients === []) {
            return false;
        }

        try {
            $this->inlineTranslation->suspend();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getAlertTemplate())
                ->setTemplateOptions(['area' => 'adminhtml', 'store' => Store::DEFAULT_STORE_ID])
                ->setTemplateVars([
                    'subject' => $message->subject,
                    'body_html' => $this->htmlRenderer->render($message),
                    'body_text' => $message->toPlainText(),
                    'occurred_at' => $message->occurredAt,
                    'hostname' => $message->hostname,
                    'count' => $message->count(),
                ])
                ->setFromByScope($this->config->getSenderIdentity())
                // Everyone on one message rather than one message each.
                ->addTo($recipients)
                ->getTransport();

            $transport->sendMessage();

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Import monitor: could not send the alert email.', ['exception' => $e]);

            return false;
        } finally {
            // Always, including after an Error.
            $this->inlineTranslation->resume();
        }
    }
}
