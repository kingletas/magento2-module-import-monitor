<?php
/**
 * AlertMessageHtmlRendererTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Notification;

use Commerce\ImportMonitor\Model\Notification\AlertMessage;
use Commerce\ImportMonitor\Model\Notification\AlertMessageHtmlRenderer;
use Commerce\ImportMonitor\Test\Support\RealEscaper;
use PHPUnit\Framework\TestCase;

/**
 * A real Escaper, not a double.
 */
class AlertMessageHtmlRendererTest extends TestCase
{
    private AlertMessageHtmlRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AlertMessageHtmlRenderer(RealEscaper::create());
    }

    public function testTheHtmlRenderingIsAListOfItems(): void
    {
        $html = $this->renderer->render($this->message());

        $this->assertStringStartsWith('<ul>', $html);
        $this->assertStringEndsWith('</ul>', $html);
        $this->assertSame(2, substr_count($html, '<li>'));
    }

    /**
     * The message text interpolates file names and supplier data, so it is
     * escaped for the email body.
     */
    public function testTheItemTextIsEscapedIntoTheHtml(): void
    {
        $message = new AlertMessage(
            'One failing check',
            [['message' => 'Feed <script>alert(1)</script> missing.', 'acknowledge_url' => null]],
            '2026-08-26 06:00:00'
        );

        $html = $this->renderer->render($message);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * The acknowledge URL lands in an href, where a quote would close the
     * attribute.
     */
    public function testTheAcknowledgeUrlIsEscapedIntoItsAttribute(): void
    {
        $message = new AlertMessage(
            'One failing check',
            [[
                'message' => 'No feed file for today.',
                'acknowledge_url' => 'https://shop.test/ack/1?t="><img src=x onerror=alert(1)>',
            ]],
            '2026-08-26 06:00:00'
        );

        $html = $this->renderer->render($message);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function testAnEmptyMessageStillProducesAWellFormedList(): void
    {
        $this->assertSame(
            '<ul></ul>',
            $this->renderer->render(new AlertMessage('All clear', [], '2026-08-26 06:00:00'))
        );
    }

    /**
     * The context travels alongside for channels that can use it, without being
     * rendered into the body.
     */
    public function testTheContextIsNotRendered(): void
    {
        $message = new AlertMessage(
            'One failing check',
            [['message' => 'No feed file for today.', 'acknowledge_url' => null]],
            '2026-08-26 06:00:00',
            null,
            ['store_id' => 2]
        );

        $this->assertStringNotContainsString('store_id', $this->renderer->render($message));
    }

    private function message(): AlertMessage
    {
        return new AlertMessage(
            'Two failing checks',
            [
                ['message' => 'No feed file for today.', 'acknowledge_url' => 'https://shop.test/ack/1'],
                ['message' => 'Salability drifted.', 'acknowledge_url' => null],
            ],
            '2026-08-26 06:00:00'
        );
    }
}
