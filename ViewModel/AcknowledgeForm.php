<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Supplies the confirmation page with the values it POSTs back.
 */
class AcknowledgeForm implements ArgumentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function getAlertId(): int
    {
        return (int) $this->request->getParam('id', 0);
    }

    /**
     * The signature from the link, echoed back into the form.
     */
    public function getToken(): string
    {
        return (string) $this->request->getParam('token', '');
    }

    public function getConfirmUrl(): string
    {
        return $this->urlBuilder->getUrl('importmonitor/alerts/confirm', ['_secure' => true]);
    }

    public function hasUsableLink(): bool
    {
        return $this->getAlertId() > 0 && $this->getToken() !== '';
    }
}
