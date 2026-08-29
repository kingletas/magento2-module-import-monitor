<?php
/**
 * @package   Commerce_ImportMonitor
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

/**
 * What one reconciliation run found.
 */
class ReconciliationResult
{
    /**
     * @param Discrepancy[]      $discrepancies
     * @param array<string, int> $countsByReason
     */
    public function __construct(
        public readonly int $examined,
        public readonly array $discrepancies,
        public readonly array $countsByReason,
        public readonly int $read = 0,
        public readonly int $skipped = 0
    ) {
    }

    public function getDiscrepancyCount(): int
    {
        return count($this->discrepancies);
    }

    /**
     * Clean means Magento was asked about every sellable SKU in the feed and
     * agreed about all of them.
     */
    public function isClean(): bool
    {
        return $this->discrepancies === [] && !$this->isInconclusive();
    }

    public function isInconclusive(): bool
    {
        return $this->skipped > 0 || $this->examined === 0;
    }

    /**
     * @return Discrepancy[]
     */
    public function getAutoFixable(): array
    {
        return array_values(array_filter(
            $this->discrepancies,
            static fn (Discrepancy $discrepancy): bool => $discrepancy->reason->isAutoFixable()
        ));
    }

    public function summarise(): string
    {
        // Order matters: a run can be both inconclusive and disagreeing, and
        // the disagreement is the more useful half.
        if ($this->discrepancies === [] && $this->isInconclusive()) {
            return $this->summariseInconclusive();
        }

        if ($this->isClean()) {
            return sprintf('Examined %d sellable SKU(s); Magento agrees on all of them.', $this->examined);
        }

        $parts = [];

        foreach ($this->countsByReason as $reason => $count) {
            $parts[] = sprintf('%s: %d', DiscrepancyReason::from($reason)->label(), $count);
        }

        $summary = sprintf(
            'Examined %d sellable SKU(s); %d disagreed (%s).',
            $this->examined,
            $this->getDiscrepancyCount(),
            implode('; ', $parts)
        );

        return $this->skipped > 0
            ? $summary . sprintf(' %d further SKU(s) could not be examined.', $this->skipped)
            : $summary;
    }

    /**
     * Says which kind of nothing was found: an empty feed and skipped SKUs need
     * different responses.
     */
    private function summariseInconclusive(): string
    {
        if ($this->read === 0) {
            return 'No sellable SKUs were found in the feed, so nothing was reconciled. '
                . 'Check the feed and the sellable status letter before reading this as agreement.';
        }

        if ($this->examined === 0) {
            return sprintf(
                'None of the %d sellable SKU(s) in the feed could be examined, so nothing was reconciled. '
                . 'See the log for why the Magento state could not be loaded.',
                $this->read
            );
        }

        return sprintf(
            'Examined %d of %d sellable SKU(s) and found no disagreement, but %d could not be examined, '
            . 'so this run cannot report the catalogue as consistent. See the log for why.',
            $this->examined,
            $this->read,
            $this->skipped
        );
    }
}
