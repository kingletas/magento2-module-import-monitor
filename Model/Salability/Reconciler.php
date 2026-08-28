<?php
/**
 * Reconciler.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability;

use Commerce\ImportMonitor\Api\SupplierFeedReaderInterface;
use Commerce\ImportMonitor\Model\Salability\Magento\ProductStateLoader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compares a supplier feed against the catalogue and reports the disagreements.
 */
class Reconciler
{
    public const int DEFAULT_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly SupplierFeedReaderInterface $feedReader,
        private readonly ProductStateLoader $stateLoader,
        private readonly DiscrepancyClassifier $classifier,
        private readonly LoggerInterface $logger,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ) {
    }

    /**
     * @param callable(Discrepancy): void|null $onDiscrepancy Called per finding, for streaming output.
     */
    public function reconcile(
        string $feedPath,
        ?int $websiteId = null,
        ?callable $onDiscrepancy = null
    ): ReconciliationResult {
        // Read from the feed, actually compared, and lost to a chunk that would
        // not load.
        $read = 0;
        $examined = 0;
        $skipped = 0;
        $discrepancies = [];
        $countsByReason = [];
        $chunk = [];

        foreach ($this->feedReader->read($feedPath) as $supplierSku) {
            $chunk[$supplierSku->sku] = $supplierSku;
            $read++;

            if (count($chunk) >= $this->effectiveChunkSize()) {
                $this->processChunk(
                    $chunk,
                    $websiteId,
                    $discrepancies,
                    $countsByReason,
                    $onDiscrepancy,
                    $examined,
                    $skipped
                );
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->processChunk(
                $chunk,
                $websiteId,
                $discrepancies,
                $countsByReason,
                $onDiscrepancy,
                $examined,
                $skipped
            );
        }

        return new ReconciliationResult($examined, $discrepancies, $countsByReason, $read, $skipped);
    }

    /**
     * @param array<string, SupplierSku> $chunk
     * @param Discrepancy[]              $discrepancies
     * @param array<string, int>         $countsByReason
     */
    private function processChunk(
        array $chunk,
        ?int $websiteId,
        array &$discrepancies,
        array &$countsByReason,
        ?callable $onDiscrepancy,
        int &$examined,
        int &$skipped
    ): void {
        try {
            $states = $this->stateLoader->load(array_keys($chunk), $websiteId);
        } catch (Throwable $e) {
            // An unreadable chunk is counted rather than abandoning the run or
            // passing as clean.
            $skipped += count($chunk);

            $this->logger->error(
                'Import monitor: could not load Magento state for a reconciliation chunk.',
                ['exception' => $e, 'size' => count($chunk)]
            );

            return;
        }

        $examined += count($chunk);

        foreach ($chunk as $sku => $supplierSku) {
            $discrepancy = $this->classifier->classify(
                $supplierSku,
                $states[$sku] ?? new ProductState(exists: false)
            );

            if ($discrepancy === null) {
                continue;
            }

            $discrepancies[] = $discrepancy;
            $reason = $discrepancy->reason->value;
            $countsByReason[$reason] = ($countsByReason[$reason] ?? 0) + 1;

            if ($onDiscrepancy !== null) {
                $onDiscrepancy($discrepancy);
            }
        }
    }

    private function effectiveChunkSize(): int
    {
        return $this->chunkSize > 0 ? $this->chunkSize : self::DEFAULT_CHUNK_SIZE;
    }
}
