<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Check;

/**
 * What was found on disk for one day's feed.
 */
class FeedFilePresence
{
    /**
     * @param string[] $matches Paths matching the day's pattern, empty or not.
     */
    public function __construct(
        public readonly string $date,
        public readonly array $matches,
        public readonly bool $hasContent
    ) {
    }

    public function wasFound(): bool
    {
        return $this->matches !== [];
    }

    public function describe(): string
    {
        return implode(', ', $this->matches);
    }
}
