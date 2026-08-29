<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Support;

use Commerce\ImportMonitor\Model\Salability\Magento\ProductStateLoader;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use RuntimeException;

/**
 * A state loader that answers from a map, and can be told to fail.
 *
 * @SuppressWarnings(PHPMD.MissingConstructor)
 */
class ScriptedStateLoader extends ProductStateLoader
{
    /** @var array<int, array<string, ProductState>|null> */
    private array $answers;

    public int $calls = 0;

    /**
     * @param array<int, array<string, ProductState>|null> $answers One entry per
     *        expected chunk; null means that chunk throws. A shorter list than
     *        the number of chunks repeats its last entry.
     */
    public function __construct(array $answers = [[]])
    {
        $this->answers = $answers;
    }

    /**
     * @param string[] $skus
     *
     * @return array<string, ProductState>
     */
    public function load(array $skus, ?int $websiteId = null): array
    {
        // `array_key_exists`, not `??`: a null entry is the instruction to
        // fail.
        $answer = array_key_exists($this->calls, $this->answers)
            ? $this->answers[$this->calls]
            : end($this->answers);
        $this->calls++;

        if ($answer === null || $answer === false) {
            throw new RuntimeException('The state loader was told to fail for this chunk.');
        }

        return $answer;
    }
}
