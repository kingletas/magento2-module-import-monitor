<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Support;

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use ReflectionProperty;

/**
 * Magento's own Escaper, made usable without an object manager.
 */
class RealEscaper
{
    public static function create(): Escaper
    {
        $escaper = new Escaper();

        $translateInline = new class implements InlineInterface {
            public function isAllowed()
            {
                return false;
            }

            public function getParser()
            {
                return null;
            }

            public function processResponseBody(&$body, $isJson = false)
            {
                return $this;
            }

            public function getAdditionalHtmlAttribute($tagName = null)
            {
                return null;
            }
        };

        $property = new ReflectionProperty(Escaper::class, 'translateInline');
        $property->setAccessible(true);
        $property->setValue($escaper, $translateInline);

        return $escaper;
    }
}
