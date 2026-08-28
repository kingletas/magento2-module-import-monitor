<?php
/**
 * FeedFileCheck.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Check;

use Commerce\ImportMonitor\Api\ImportCheckInterface;
use Commerce\ImportMonitor\Model\Config;
use DateTime;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Checks that a dated feed file arrived and has content.
 */
class FeedFileCheck implements ImportCheckInterface
{
    /**
     * @param string[] $searchDirectories Directories relative to var/, including any archive folder.
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly TimezoneInterface $timezone,
        private readonly Config $config,
        private readonly array $searchDirectories = [],
        private readonly string $filenamePrefix = 'feed_',
        private readonly string $filenameExtension = 'csv',
        private readonly string $code = 'feed_file',
        private readonly string $label = 'Supplier feed file'
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function run(): CheckResult
    {
        try {
            return $this->evaluate();
        } catch (FileSystemException $e) {
            return new CheckResult(
                isHealthy: false,
                checkCode: $this->code,
                fingerprintSeed: 'unreadable',
                message: sprintf('%s: could not be verified (%s).', $this->label, $e->getMessage())
            );
        }
    }

    /**
     * @throws FileSystemException
     */
    private function evaluate(): CheckResult
    {
        $varDirectory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);

        $today = $this->presenceFor($varDirectory, $this->localDate('today'));

        if ($today->hasContent) {
            return new CheckResult(isHealthy: true, checkCode: $this->code);
        }

        $yesterday = $this->presenceFor($varDirectory, $this->localDate('yesterday'));

        // Nothing usable for either day: stale whatever the hour.
        if (!$yesterday->hasContent) {
            return new CheckResult(
                isHealthy: false,
                checkCode: $this->code,
                fingerprintSeed: 'stale',
                message: $this->staleMessage($today, $yesterday)
            );
        }

        // Yesterday's is on hand and today's is not due yet.
        if ($this->currentHour() < $this->config->getFeedStrictHour()) {
            return new CheckResult(isHealthy: true, checkCode: $this->code);
        }

        return new CheckResult(
            isHealthy: false,
            checkCode: $this->code,
            fingerprintSeed: $today->wasFound() ? 'empty' : 'overdue',
            message: $this->overdueMessage($today)
        );
    }

    private function staleMessage(FeedFilePresence $today, FeedFilePresence $yesterday): string
    {
        $empties = array_merge($today->matches, $yesterday->matches);

        if ($empties !== []) {
            return sprintf(
                '%s: nothing usable for today or yesterday — only empty (0 byte) files: %s.',
                $this->label,
                implode(', ', $empties)
            );
        }

        return sprintf(
            '%s: no file found for today or yesterday (expected %s or %s).',
            $this->label,
            $this->patternFor($today->date),
            $this->patternFor($yesterday->date)
        );
    }

    private function overdueMessage(FeedFilePresence $today): string
    {
        if ($today->wasFound()) {
            return sprintf("%s: today's file is empty (0 bytes): %s.", $this->label, $today->describe());
        }

        return sprintf(
            "%s: today's file has not arrived (expected %s).",
            $this->label,
            $this->patternFor($today->date)
        );
    }

    /**
     * @throws FileSystemException
     */
    private function presenceFor(ReadInterface $varDirectory, string $date): FeedFilePresence
    {
        $matches = $this->findMatches($varDirectory, $this->patternFor($date));
        $hasContent = false;

        foreach ($matches as $relativePath) {
            if ($this->fileSize($varDirectory, $relativePath) > 0) {
                $hasContent = true;
                break;
            }
        }

        return new FeedFilePresence($date, $matches, $hasContent);
    }

    private function patternFor(string $date): string
    {
        return sprintf('%s%s_*.%s', $this->filenamePrefix, $date, $this->filenameExtension);
    }

    /**
     * @return string[]
     *
     * @throws FileSystemException
     */
    private function findMatches(ReadInterface $varDirectory, string $pattern): array
    {
        $matches = [];

        foreach ($this->searchDirectories as $directory) {
            if (!$varDirectory->isExist($directory)) {
                continue;
            }

            foreach ($varDirectory->search($pattern, $directory) as $match) {
                if ($varDirectory->isFile($match)) {
                    $matches[] = $match;
                }
            }
        }

        return $matches;
    }

    /**
     * @throws FileSystemException
     */
    private function fileSize(ReadInterface $varDirectory, string $relativePath): int
    {
        return (int) ($varDirectory->stat($relativePath)['size'] ?? 0);
    }

    /**
     * Dates are resolved in the **store** timezone, because that is the clock
     * the supplier's drop schedule is quoted against.
     */
    private function localDate(string $when): string
    {
        return $this->timezone->date(new DateTime($when))->format('Ymd');
    }

    private function currentHour(): int
    {
        return (int) $this->timezone->date()->format('G');
    }
}
