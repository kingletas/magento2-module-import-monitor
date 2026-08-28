<?php
/**
 * Confirm.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Controller\Alerts;

use Commerce\ImportMonitor\Model\Alert\AlertManager;
use Commerce\ImportMonitor\Model\Alert\AlertSigner;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;

/**
 * POST - acknowledge one alert.
 */
class Confirm implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly MessageManagerInterface $messageManager,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly AlertManager $alertManager,
        private readonly AlertSigner $signer
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $alertId = (int) $this->request->getParam('id', 0);
        $token = (string) $this->request->getParam('token', '');

        if (!$this->formKeyValidator->validate($this->request)) {
            $this->messageManager->addErrorMessage(__('Your session has expired. Please open the link again.'));

            return $this->backToConfirmation($redirect, $alertId, $token);
        }

        if (!$this->alertManager->acknowledge($alertId, $token, $this->signer)) {
            // One response for a bad signature, an unknown id and an alert that
            // is already acknowledged.
            $this->messageManager->addErrorMessage(__('That acknowledgement link is not valid.'));

            return $this->backToConfirmation($redirect, $alertId, $token);
        }

        $this->messageManager->addSuccessMessage(__('Thanks - the alert has been acknowledged.'));

        return $this->backToConfirmation($redirect, $alertId, $token);
    }

    /**
     * Return to the confirmation page so the outcome is rendered with the
     * message, rather than dropping the visitor on a bare redirect target.
     */
    private function backToConfirmation(Redirect $redirect, int $alertId, string $token): Redirect
    {
        return $redirect->setPath(
            'importmonitor/alerts/acknowledge',
            ['id' => $alertId, 'token' => $token, '_secure' => true]
        );
    }
}
