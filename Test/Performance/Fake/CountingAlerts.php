<?php
/**
 * CountingAlerts.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Performance\Fake;

use Commerce\ImportMonitor\Test\Behaviour\Fake\InMemoryAlerts;
use Commerce\ImportMonitor\Test\Performance\AlertingCostTest;

/**
 * The alert table, counting every statement it is asked for.
 */
final class CountingAlerts extends InMemoryAlerts
{
    public function __construct(private readonly AlertingCostTest $test)
    {
        parent::__construct();
    }

    public function recordOccurrence(string $fingerprint, string $message, string $now): bool
    {
        $this->test->recordRowStatement();

        return parent::recordOccurrence($fingerprint, $message, $now);
    }

    /**
     * @param string[] $activeFingerprints
     */
    public function resolveAllExcept(array $activeFingerprints, string $now): int
    {
        $this->test->recordRowStatement();

        return parent::resolveAllExcept($activeFingerprints, $now);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadByFingerprint(string $fingerprint): ?array
    {
        $this->test->recordRowStatement();

        return parent::loadByFingerprint($fingerprint);
    }
}
