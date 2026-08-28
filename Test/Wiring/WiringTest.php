<?php
/**
 * WiringTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Wiring;

use Commerce\Foundation\Test\Support\ModuleWiringTestCase;

/**
 * This module's `etc/` against the code it names.
 */
class WiringTest extends ModuleWiringTestCase
{
    protected function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @inheritDoc
     */
    protected function settingsWithNoDefault(): array
    {
        return [
            // Who gets told, and where.
            'commerce_import_monitor/notification/recipients',
            'commerce_import_monitor/slack/channel',
        ];
    }
}
