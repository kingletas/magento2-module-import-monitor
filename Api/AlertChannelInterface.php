<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Api;

use Commerce\ImportMonitor\Model\Notification\AlertMessage;

/**
 * Somewhere an alert can be delivered.
 */
interface AlertChannelInterface
{
    public function getCode(): string;

    public function isEnabled(): bool;

    /**
     * Deliver the message.
     *
     * @return bool Whether delivery succeeded.
     */
    public function send(AlertMessage $message): bool;
}
