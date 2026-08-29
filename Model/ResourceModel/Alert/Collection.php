<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\ResourceModel\Alert;

use Commerce\ImportMonitor\Api\Data\AlertInterface;
use Commerce\ImportMonitor\Model\Alert;
use Commerce\ImportMonitor\Model\ResourceModel\Alert as AlertResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Magento requires the _construct() initialiser, which trips PHPMD naming.
 *
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 */
class Collection extends AbstractCollection
{

    /**
     * Set through the setter rather than by redeclaring the property.
     */
    protected function _construct(): void
    {
        $this->_setIdFieldName(AlertInterface::ALERT_ID);
        $this->_init(Alert::class, AlertResource::class);
    }
}
