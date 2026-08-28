<?php
/**
 * ConfirmTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Controller\Alerts;

use Commerce\ImportMonitor\Controller\Alerts\Confirm;
use Commerce\ImportMonitor\Model\Alert\AlertManager;
use Commerce\ImportMonitor\Model\Alert\AlertSigner;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfirmTest extends TestCase
{
    /** @var array<int, array{level: string, message: string}> */
    private array $messages = [];

    /** @var array<int, array{path: string, params: array<string, mixed>}> */
    private array $redirects = [];

    /** @var array<int, array{id: int, token: string}> */
    private array $acknowledgements = [];

    /** @var array<string, mixed> */
    private array $params = ['id' => '9', 'token' => 'signature-for-9'];

    private bool $formKeyValid = true;
    private bool $acknowledgeSucceeds = true;

    protected function setUp(): void
    {
        $this->messages = [];
        $this->redirects = [];
        $this->acknowledgements = [];
        $this->params = ['id' => '9', 'token' => 'signature-for-9'];
        $this->formKeyValid = true;
        $this->acknowledgeSucceeds = true;
    }

    /**
     * The form key stops another site POSTing here through the visitor's
     * browser.
     */
    public function testItOnlyAnswersPosts(): void
    {
        $controller = $this->controller();

        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $controller);
    }

    public function testAValidAcknowledgementIsRecordedAndConfirmed(): void
    {
        $this->controller()->execute();

        $this->assertSame([['id' => 9, 'token' => 'signature-for-9']], $this->acknowledgements);
        $this->assertSame('success', $this->messages[0]['level']);
    }

    /**
     * The signature is a second, independent gate on a sequential alert id.
     */
    public function testTheSignatureIsCheckedBeforeAnythingIsAcknowledged(): void
    {
        $this->acknowledgeSucceeds = false;

        $this->controller()->execute();

        $this->assertSame('error', $this->messages[0]['level']);
    }

    /**
     * One response for a bad signature, an unknown id and an alert already
     * acknowledged.
     */
    public function testEveryRefusalReadsTheSame(): void
    {
        $this->acknowledgeSucceeds = false;

        $this->controller()->execute();
        $badSignature = $this->messages[0]['message'];

        $this->messages = [];
        $this->params['id'] = '99999';
        $this->controller()->execute();

        $this->assertSame($badSignature, $this->messages[0]['message']);
    }

    public function testARejectedFormKeyAcknowledgesNothing(): void
    {
        $this->formKeyValid = false;

        $this->controller()->execute();

        $this->assertSame([], $this->acknowledgements);
        $this->assertSame('error', $this->messages[0]['level']);
        $this->assertStringContainsString('session has expired', $this->messages[0]['message']);
    }

    /**
     * Every outcome returns to the confirmation page with its message rendered.
     */
    public function testEveryOutcomeReturnsToTheConfirmationPage(): void
    {
        $paths = [];

        $this->controller()->execute();
        $paths[] = $this->redirects[0]['path'];

        $this->acknowledgeSucceeds = false;
        $this->controller()->execute();
        $paths[] = $this->redirects[1]['path'];

        $this->formKeyValid = false;
        $this->controller()->execute();
        $paths[] = $this->redirects[2]['path'];

        $this->assertSame(
            [
                'importmonitor/alerts/acknowledge',
                'importmonitor/alerts/acknowledge',
                'importmonitor/alerts/acknowledge',
            ],
            $paths
        );
    }

    /**
     * The link's parameters are carried back over HTTPS, because the signature
     * travels with them.
     */
    public function testTheLinkParametersAreCarriedBackSecurely(): void
    {
        $this->formKeyValid = false;

        $this->controller()->execute();

        $this->assertSame(9, $this->redirects[0]['params']['id']);
        $this->assertSame('signature-for-9', $this->redirects[0]['params']['token']);
        $this->assertTrue($this->redirects[0]['params']['_secure']);
    }

    /**
     * The id arrives from a query string, so it is cast before it reaches the
     * alert manager rather than being trusted as given.
     */
    public function testANonNumericIdIsCastRatherThanPassedThrough(): void
    {
        $this->params['id'] = '9 OR 1=1';

        $this->controller()->execute();

        $this->assertSame(9, $this->acknowledgements[0]['id']);
    }

    private function controller(): Confirm
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $this->params[$key] ?? $default
        );

        $messageManager = $this->createMock(MessageManagerInterface::class);
        $messageManager->method('addSuccessMessage')->willReturnCallback(
            function ($message) use (&$messageManager) {
                $this->messages[] = ['level' => 'success', 'message' => (string) $message];

                return $messageManager;
            }
        );
        $messageManager->method('addErrorMessage')->willReturnCallback(
            function ($message) use (&$messageManager) {
                $this->messages[] = ['level' => 'error', 'message' => (string) $message];

                return $messageManager;
            }
        );

        $formKeyValidator = $this->createMock(FormKeyValidator::class);
        $formKeyValidator->method('validate')->willReturnCallback(fn (): bool => $this->formKeyValid);

        $alertManager = $this->createMock(AlertManager::class);
        $alertManager->method('acknowledge')->willReturnCallback(
            function (int $alertId, string $token): bool {
                $this->acknowledgements[] = ['id' => $alertId, 'token' => $token];

                return $this->acknowledgeSucceeds;
            }
        );

        return new Confirm(
            $request,
            $this->redirectFactory(),
            $messageManager,
            $formKeyValidator,
            $alertManager,
            $this->createMock(AlertSigner::class)
        );
    }

    private function redirectFactory(): RedirectFactory&MockObject
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function (string $path, ?array $params = null) use (&$redirect): Redirect {
                $this->redirects[] = ['path' => $path, 'params' => (array) $params];

                return $redirect;
            }
        );

        $factory = $this->createMock(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        return $factory;
    }
}
