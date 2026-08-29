<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Notification;

use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use PHPUnit\Framework\TestCase;

class AlertMessageTest extends TestCase
{
    public function testItCountsWhatItIsAbout(): void
    {
        $this->assertSame(2, $this->message()->count());
        $this->assertSame(0, (new AlertMessage('All clear', [], '2026-08-26 06:00:00'))->count());
    }

    public function testThePlainTextRenderingLeadsWithTheSubjectAndTheTime(): void
    {
        $lines = explode("\n", $this->message()->toPlainText());

        $this->assertStringContainsString('Import monitor: 2 failing checks', $lines[0]);
        $this->assertStringContainsString('2026-08-26 06:00:00', $lines[0]);
    }

    public function testEveryItemBecomesItsOwnLine(): void
    {
        $lines = explode("\n", $this->message()->toPlainText());

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('No feed file for today.', $lines[1]);
        $this->assertStringContainsString('Nightly import is stuck.', $lines[2]);
    }

    /**
     * The acknowledge link is what makes an alert actionable from a phone at
     * two in the morning; an item that has one must carry it into the text.
     */
    public function testAnItemWithAnAcknowledgeLinkCarriesIt(): void
    {
        $this->assertStringContainsString(
            'https://shop.test/ack/1',
            $this->message()->toPlainText()
        );
    }

    public function testAnItemWithoutALinkIsRenderedWithoutOne(): void
    {
        $message = new AlertMessage(
            'One failing check',
            [['message' => 'No feed file for today.', 'acknowledge_url' => null]],
            '2026-08-26 06:00:00'
        );

        $this->assertStringNotContainsString('acknowledge:', $message->toPlainText());
    }

    /**
     * Off by default, because `gethostname()` publishes internal host naming to
     * a chat workspace.
     */
    public function testTheHostnameAppearsOnlyWhenOneWasGiven(): void
    {
        $this->assertStringNotContainsString('[', $this->message()->toPlainText());
        $this->assertStringContainsString('[web-01]', $this->message('web-01')->toPlainText());
    }

    /**
     * The context travels alongside for channels that can use it, without
     * entering the body.
     */
    public function testTheContextIsCarriedButNotRendered(): void
    {
        $message = new AlertMessage(
            'One failing check',
            [['message' => 'No feed file for today.', 'acknowledge_url' => null]],
            '2026-08-26 06:00:00',
            null,
            ['store_id' => 2]
        );

        $this->assertSame(['store_id' => 2], $message->context);
        $this->assertStringNotContainsString('store_id', $message->toPlainText());
    }
    private function message(?string $hostname = null): AlertMessage
    {
        return new AlertMessage(
            'Import monitor: 2 failing checks',
            [
                ['message' => 'No feed file for today.', 'acknowledge_url' => 'https://shop.test/ack/1'],
                ['message' => 'Nightly import is stuck.', 'acknowledge_url' => null],
            ],
            '2026-08-26 06:00:00',
            $hostname
        );
    }
}
