<?php
/**
 * AcknowledgeFormTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\ViewModel;

use Commerce\ImportMonitor\ViewModel\AcknowledgeForm;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AcknowledgeFormTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $params = ['id' => '9', 'token' => 'abc123'];

    public function testItIsUsableAsALayoutViewModel(): void
    {
        $this->assertInstanceOf(ArgumentInterface::class, $this->viewModel());
    }

    public function testTheAlertIdAndTokenComeFromTheLink(): void
    {
        $form = $this->viewModel();

        $this->assertSame(9, $form->getAlertId());
        $this->assertSame('abc123', $form->getToken());
    }

    /**
     * The id is cast, because the whole point of the confirmation step is that
     * the value came from a query string somebody could have edited.
     */
    public function testANonNumericIdBecomesZeroRatherThanReachingTheController(): void
    {
        $this->params['id'] = '9 OR 1=1';

        $this->assertSame(9, $this->viewModel()->getAlertId());

        $this->params['id'] = 'nine';

        $this->assertSame(0, $this->viewModel()->getAlertId());
    }

    /**
     * A link missing either half is said to be unusable rather than rendered as
     * a form.
     */
    public function testALinkMissingEitherHalfIsNotUsable(): void
    {
        $this->assertTrue($this->viewModel()->hasUsableLink());

        $this->params = ['id' => '9', 'token' => ''];
        $this->assertFalse($this->viewModel()->hasUsableLink());

        $this->params = ['id' => '0', 'token' => 'abc123'];
        $this->assertFalse($this->viewModel()->hasUsableLink());

        $this->params = [];
        $this->assertFalse($this->viewModel()->hasUsableLink());
    }

    /**
     * The form POSTs a signature that acknowledges an alert; over plain HTTP
     * that signature is on the wire for anyone on the path to replay.
     */
    public function testTheConfirmationIsPostedOverHttps(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->expects($this->once())
            ->method('getUrl')
            ->with('importmonitor/alerts/confirm', ['_secure' => true])
            ->willReturn('https://shop.test/importmonitor/alerts/confirm/');

        $this->assertSame(
            'https://shop.test/importmonitor/alerts/confirm/',
            $this->viewModel($urlBuilder)->getConfirmUrl()
        );
    }

    /**
     * The token is echoed back verbatim, and the template escapes it into the
     * attribute.
     */
    public function testTheTokenIsEchoedBackUnchanged(): void
    {
        $this->params['token'] = 'abc"><script>alert(1)</script>';

        $this->assertSame('abc"><script>alert(1)</script>', $this->viewModel()->getToken());
    }

    private function viewModel(?UrlInterface $urlBuilder = null): AcknowledgeForm
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );

        return new AcknowledgeForm(
            $request,
            $urlBuilder ?? $this->createMock(UrlInterface::class)
        );
    }
}
