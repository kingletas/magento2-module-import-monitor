<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Controller\Alerts;

use Commerce\ImportMonitor\Controller\Alerts\Acknowledge;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AcknowledgeTest extends TestCase
{
    private ?string $title = null;

    /** @var string[] */
    private array $paramsRead = [];

    protected function setUp(): void
    {
        $this->title = null;
        $this->paramsRead = [];
    }

    /**
     * The link is sent by email, and mail security gateways, link scanners and
     * preview generators issue a GET for every URL in a message.
     */
    public function testItIsAGetThatChangesNothing(): void
    {
        $controller = $this->controller();

        $this->assertInstanceOf(HttpGetActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpPostActionInterface::class, $controller);
    }

    public function testItRendersAPageWithItsOwnTitle(): void
    {
        $page = $this->controller()->execute();

        $this->assertInstanceOf(Page::class, $page);
        $this->assertSame('Acknowledge alert', $this->title);
    }

    /**
     * The signature is not verified here on purpose.
     */
    public function testItReadsNothingFromTheRequestAndSoCannotLeakWhatExists(): void
    {
        $this->controller()->execute();

        $this->assertSame([], $this->paramsRead);
    }

    private function controller(): Acknowledge
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default = null) {
                $this->paramsRead[] = $key;

                return $default;
            }
        );

        return new Acknowledge($request, $this->pageFactory());
    }

    private function pageFactory(): PageFactory&MockObject
    {
        $title = $this->createMock(Title::class);
        $title->method('set')->willReturnCallback(function ($value) use (&$title): Title {
            $this->title = (string) $value;

            return $title;
        });

        $config = $this->createMock(Config::class);
        $config->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($config);

        $factory = $this->createMock(PageFactory::class);
        $factory->method('create')->willReturn($page);

        return $factory;
    }
}
