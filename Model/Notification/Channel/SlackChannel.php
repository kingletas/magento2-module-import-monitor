<?php
/**
 * SlackChannel.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Notification\Channel;

use Commerce\ImportMonitor\Api\AlertChannelInterface;
use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Posts alerts to Slack via the Web API.
 */
class SlackChannel implements AlertChannelInterface
{
    private const string ENDPOINT = 'https://slack.com/api/chat.postMessage';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly string $code = 'slack',
        private readonly int $timeoutSeconds = 10
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isEnabled(): bool
    {
        return $this->config->isSlackEnabled();
    }

    public function send(AlertMessage $message): bool
    {
        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout($this->timeoutSeconds);
            $curl->addHeader('Content-Type', 'application/json; charset=utf-8');
            $curl->addHeader('Authorization', 'Bearer ' . $this->config->getSlackToken());
            $curl->post(self::ENDPOINT, $this->json->serialize([
                'channel' => $this->config->getSlackChannel(),
                'text' => $message->toPlainText(),
            ]));

            return $this->interpret($curl->getStatus(), $curl->getBody());
        } catch (Throwable $e) {
            $this->logger->error('Import monitor: could not post to Slack.', ['exception' => $e]);

            return false;
        }
    }

    private function interpret(int $status, string $body): bool
    {
        if ($status !== 200) {
            $this->logger->error(sprintf('Import monitor: Slack returned HTTP %d.', $status));

            return false;
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (Throwable) {
            $this->logger->error('Import monitor: Slack returned an unparseable response.');

            return false;
        }

        if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            return true;
        }

        $this->logger->error(sprintf(
            'Import monitor: Slack rejected the message (%s).',
            is_array($decoded) ? (string) ($decoded['error'] ?? 'unknown') : 'unknown'
        ));

        return false;
    }
}
