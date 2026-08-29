<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Notification;

use Magento\Framework\Escaper;

/**
 * Renders an alert as the HTML body of an email.
 */
class AlertMessageHtmlRenderer
{
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    public function render(AlertMessage $message): string
    {
        $rows = [];

        foreach ($message->items as $item) {
            $row = $this->escaper->escapeHtml($item['message']);

            if (!empty($item['acknowledge_url'])) {
                $row .= sprintf(
                    ' | <a href="%s">%s</a>',
                    $this->escaper->escapeUrl($item['acknowledge_url']),
                    $this->escaper->escapeHtml(__('Acknowledge'))
                );
            }

            $rows[] = '<li>' . $row . '</li>';
        }

        return '<ul>' . implode('', $rows) . '</ul>';
    }
}
