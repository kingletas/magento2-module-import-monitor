<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Notification;

/**
 * One notification, in a form every channel can render.
 */
class AlertMessage
{
    /**
     * @param array<int, array{message: string, acknowledge_url: string|null}> $items
     * @param array<string, mixed>                                             $context
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $items,
        public readonly string $occurredAt,
        public readonly ?string $hostname = null,
        public readonly array $context = []
    ) {
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function toPlainText(): string
    {
        $lines = [sprintf('%s (%s)', $this->subject, $this->occurredAt)];

        if ($this->hostname !== null) {
            $lines[0] .= sprintf(' [%s]', $this->hostname);
        }

        foreach ($this->items as $item) {
            $line = '• ' . $item['message'];

            if (!empty($item['acknowledge_url'])) {
                $line .= ' — acknowledge: ' . $item['acknowledge_url'];
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
