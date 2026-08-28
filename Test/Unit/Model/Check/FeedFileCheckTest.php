<?php
/**
 * FeedFileCheckTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Check;

use Commerce\ImportMonitor\Api\ImportCheckInterface;
use Commerce\ImportMonitor\Model\Check\FeedFileCheck;
use Commerce\ImportMonitor\Model\Config;
use DateTimeImmutable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeedFileCheckTest extends TestCase
{
    private const TODAY = '20260826';
    private const YESTERDAY = '20260825';

    /** @var array<string, int> Relative path => size in bytes. */
    private array $files = [];

    private int $currentHour = 22;
    private bool $filesystemFails = false;

    protected function setUp(): void
    {
        $this->files = [];
        $this->currentHour = 22;
        $this->filesystemFails = false;
    }

    public function testItAnnouncesItsCodeAndLabel(): void
    {
        $check = $this->check();

        $this->assertInstanceOf(ImportCheckInterface::class, $check);
        $this->assertSame('feed_file', $check->getCode());
        $this->assertSame('Supplier feed file', $check->getLabel());
    }

    public function testTodaysFileWithContentIsHealthy(): void
    {
        $this->files = ['import/feed_' . self::TODAY . '_01.csv' => 4096];

        $this->assertTrue($this->check()->run()->isHealthy);
    }

    /**
     * The feed arrives late in the day.
     */
    public function testYesterdaysFileIsEnoughBeforeTheStrictHour(): void
    {
        $this->currentHour = 9;
        $this->files = ['import/feed_' . self::YESTERDAY . '_01.csv' => 4096];

        $this->assertTrue($this->check()->run()->isHealthy);
    }

    public function testTodaysFileIsRequiredFromTheStrictHourOn(): void
    {
        $this->currentHour = 21;
        $this->files = ['import/feed_' . self::YESTERDAY . '_01.csv' => 4096];

        $result = $this->check()->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString("today's file has not arrived", (string) $result->message);
    }

    /**
     * A stale feed is a fault at any hour, because yesterday's file being
     * unusable means it stopped.
     */
    public function testNothingUsableForEitherDayFailsWhateverTheHour(): void
    {
        $this->currentHour = 3;

        $result = $this->check()->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString('no file found for today or yesterday', (string) $result->message);
    }

    /**
     * A file caught mid-transfer is zero bytes for a moment.
     */
    public function testAnEmptyFileEarlyInTheDayDoesNotFailWhileYesterdaysIsOnHand(): void
    {
        $this->currentHour = 9;
        $this->files = [
            'import/feed_' . self::TODAY . '_01.csv' => 0,
            'import/feed_' . self::YESTERDAY . '_01.csv' => 4096,
        ];

        $this->assertTrue($this->check()->run()->isHealthy);
    }

    /**
     * Past the strict hour an empty file is reported as empty rather than
     * missing.
     */
    public function testAnEmptyFilePastTheStrictHourIsReportedAsEmpty(): void
    {
        $this->currentHour = 22;
        $this->files = [
            'import/feed_' . self::TODAY . '_01.csv' => 0,
            'import/feed_' . self::YESTERDAY . '_01.csv' => 4096,
        ];

        $result = $this->check()->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString("today's file is empty", (string) $result->message);
        $this->assertStringContainsString('feed_' . self::TODAY, (string) $result->message);
    }

    /**
     * "Empty" and "missing" are different faults, so an operator who
     * acknowledged one is still told about the other.
     */
    public function testAnEmptyFileAndAMissingOneAreDifferentAlerts(): void
    {
        $this->files = [
            'import/feed_' . self::TODAY . '_01.csv' => 0,
            'import/feed_' . self::YESTERDAY . '_01.csv' => 4096,
        ];
        $empty = $this->check()->run();

        $this->files = ['import/feed_' . self::YESTERDAY . '_01.csv' => 4096];
        $missing = $this->check()->run();

        $this->assertNotSame($empty->fingerprint, $missing->fingerprint);
    }

    /**
     * The stale message names the empty files it did find.
     */
    public function testAStaleFeedWithOnlyEmptyFilesNamesThem(): void
    {
        $this->files = [
            'import/feed_' . self::TODAY . '_01.csv' => 0,
            'import/feed_' . self::YESTERDAY . '_01.csv' => 0,
        ];

        $result = $this->check()->run();

        $this->assertStringContainsString('only empty (0 byte) files', (string) $result->message);
        $this->assertStringContainsString('feed_' . self::YESTERDAY, (string) $result->message);
    }

    /**
     * The missing-file message quotes the pattern it looked for, so whoever
     * reads the alert can check the drop directory themselves.
     */
    public function testTheMissingFileMessageQuotesThePatternItExpected(): void
    {
        $result = $this->check()->run();

        $this->assertStringContainsString('feed_' . self::TODAY . '_*.csv', (string) $result->message);
    }

    /**
     * A supplier prefix written into the code is what makes a monitor usable by
     * exactly one store.
     */
    public function testThePatternIsBuiltFromConfiguredPartsRatherThanHardcoded(): void
    {
        $result = $this->check(prefix: 'acme_stock_', extension: 'txt')->run();

        $this->assertStringContainsString('acme_stock_' . self::TODAY . '_*.txt', (string) $result->message);
    }

    /**
     * The archive folder counts: a feed moved there by a successful import is
     * still evidence that it arrived.
     */
    public function testEveryConfiguredDirectoryIsSearched(): void
    {
        $this->files = ['archive/feed_' . self::TODAY . '_01.csv' => 4096];

        $this->assertTrue($this->check(directories: ['import', 'archive'])->run()->isHealthy);
    }

    public function testADirectoryThatDoesNotExistIsSkippedRatherThanFailing(): void
    {
        $this->files = ['import/feed_' . self::TODAY . '_01.csv' => 4096];

        $this->assertTrue($this->check(directories: ['import', 'never_created'])->run()->isHealthy);
    }

    /**
     * An unreadable directory is a fault of its own rather than a missing feed.
     */
    public function testAnUnreadableFilesystemIsItsOwnFault(): void
    {
        $this->filesystemFails = true;

        $result = $this->check()->run();

        $this->assertFalse($result->isHealthy);
        $this->assertStringContainsString('could not be verified', (string) $result->message);
    }

    /**
     * A directory entry matching the pattern is not a feed.
     */
    public function testADirectoryMatchingThePatternIsNotAFile(): void
    {
        $this->files = ['import/feed_' . self::TODAY . '_01.csv' => -1];

        $this->assertFalse($this->check()->run()->isHealthy);
    }

    /**
     * @param string[] $directories
     */
    private function check(
        array $directories = ['import'],
        string $prefix = 'feed_',
        string $extension = 'csv'
    ): FeedFileCheck {
        $config = new Config(
            $this->scopeConfig(['test_importmonitor/general/feed_strict_hour' => '21']),
            'test_importmonitor',
            $this->createMock(EncryptorInterface::class)
        );

        return new FeedFileCheck(
            $this->filesystem($directories),
            $this->timezone(),
            $config,
            $directories,
            $prefix,
            $extension
        );
    }

    /**
     * @param string[] $directories
     */
    private function filesystem(array $directories): Filesystem&MockObject
    {
        $read = $this->createMock(ReadInterface::class);
        $read->method('isExist')->willReturnCallback(
            fn (?string $path = null): bool => in_array((string) $path, $directories, true)
                && $path !== 'never_created'
        );
        $read->method('search')->willReturnCallback(
            function (string $pattern, $directory = null): array {
                if ($this->filesystemFails) {
                    throw new FileSystemException(new Phrase('The directory is not readable.'));
                }

                $regex = '#^' . preg_quote((string) $directory . '/', '#')
                    . str_replace('\*', '[^/]*', preg_quote($pattern, '#')) . '$#';

                return array_values(array_filter(
                    array_keys($this->files),
                    static fn (string $path): bool => preg_match($regex, $path) === 1
                ));
            }
        );
        $read->method('isFile')->willReturnCallback(
            fn (?string $path = null): bool => ($this->files[(string) $path] ?? 0) >= 0
        );
        $read->method('stat')->willReturnCallback(
            fn (?string $path = null): array => ['size' => max(0, $this->files[(string) $path] ?? 0)]
        );

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::VAR_DIR)->willReturn($read);

        return $filesystem;
    }

    private function timezone(): TimezoneInterface&MockObject
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            function ($date = null): DateTimeImmutable {
                $day = $date instanceof \DateTimeInterface
                    && $date->format('Ymd') !== (new \DateTime('today'))->format('Ymd')
                        ? self::YESTERDAY
                        : self::TODAY;

                return new DateTimeImmutable(
                    sprintf('%s %02d:00:00', $day, $this->currentHour)
                );
            }
        );

        return $timezone;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
