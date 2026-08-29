<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Check;

use InvalidArgumentException;

/**
 * The outcome of one health check.
 */
class CheckResult
{
    /**
     * Derived from the check code and the seed, never from the message.
     */
    public readonly ?string $fingerprint;

    /**
     * @param bool        $isHealthy       Two states, so the discriminant is the
     *                                     boolean the object already carries.
     * @param string      $fingerprintSeed Something stable across repeats of the
     *                                     same fault — a task code, a file
     *                                     pattern — and deliberately NOT the
     *                                     timestamped message. Ignored when
     *                                     healthy.
     * @param string|null $message         This sighting of the fault.
     */
    public function __construct(
        public readonly bool $isHealthy,
        public readonly string $checkCode,
        string $fingerprintSeed = '',
        public readonly ?string $message = null
    ) {
        if (!$this->isHealthy && ($fingerprintSeed === '' || $this->message === null)) {
            throw new InvalidArgumentException('A failed check needs both a fingerprint seed and a message.');
        }

        // Computed here rather than at the call site so that two reports of one
        // fault cannot disagree about their fingerprint and alert twice.
        $this->fingerprint = $this->isHealthy
            ? null
            : hash('xxh128', $this->checkCode . '|' . $fingerprintSeed);
    }
}
