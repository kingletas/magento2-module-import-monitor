<?php
/**
 * UnboundImportTaskSource.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Unbound;

use Commerce\ImportMonitor\Api\ImportTaskSourceInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * The placeholder that stands in until a store binds its own task source.
 */
class UnboundImportTaskSource implements ImportTaskSourceInterface
{
    public const MESSAGE = 'No import task source is bound. Bind '
        . 'Commerce\\ImportMonitor\\Api\\ImportTaskSourceInterface to an implementation '
        . 'that can read this store\'s import history.';

    private bool $warned = false;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string[] $taskCodes
     *
     * @return array<string, \Commerce\ImportMonitor\Model\Check\ImportTask>
     *
     * @throws LocalizedException Always: this is a placeholder, not a source.
     */
    public function getLatestRuns(array $taskCodes, string $since): array
    {
        if (!$this->warned) {
            $this->warned = true;
            $this->logger->warning('Import monitor: ' . self::MESSAGE);
        }

        throw new LocalizedException(
            __(
                'No import task source is bound. Bind '
                . 'Commerce\\ImportMonitor\\Api\\ImportTaskSourceInterface to an implementation '
                . 'that can read this store\'s import history.'
            )
        );
    }
}
