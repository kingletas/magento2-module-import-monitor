<?php
/**
 * CheckRunner.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Check;

use Commerce\ImportMonitor\Api\ImportCheckInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs every registered check and collects the results.
 */
class CheckRunner
{
    /**
     * @param array<string, ImportCheckInterface> $checks
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $checks = []
    ) {
        foreach ($this->checks as $key => $check) {
            if (!$check instanceof ImportCheckInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Check "%s" must implement %s, got %s.',
                    $key,
                    ImportCheckInterface::class,
                    get_debug_type($check)
                ));
            }
        }
    }

    /**
     * @return CheckResult[] One per registered check.
     */
    public function runAll(): array
    {
        $results = [];

        foreach ($this->checks as $key => $check) {
            $results[] = $this->runOne($key, $check);
        }

        return $results;
    }

    /**
     * @return CheckResult[] Only the failures.
     */
    public function runFailures(): array
    {
        return array_values(array_filter(
            $this->runAll(),
            static fn (CheckResult $result): bool => !$result->isHealthy
        ));
    }

    /**
     * @return ImportCheckInterface[]
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    private function runOne(string $key, ImportCheckInterface $check): CheckResult
    {
        try {
            return $check->run();
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Import monitor: check "%s" threw instead of returning a result.', $key),
                ['exception' => $e]
            );

            // A check that cannot run is itself a fault worth alerting on: the
            // alternative is reporting healthy because nothing answered.
            return new CheckResult(
                isHealthy: false,
                checkCode: $check->getCode(),
                fingerprintSeed: 'check-error',
                message: sprintf('%s: the check could not run (%s).', $check->getLabel(), $e->getMessage())
            );
        }
    }
}
