<?php
/**
 * ConfigTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model;

use Commerce\ImportMonitor\Model\Config;
use Commerce\ImportMonitor\Test\Unit\Fake\ArrayScopeConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testEveryPathIsReadUnderTheConfiguredSection(): void
    {
        $config = new Config(
            new ArrayScopeConfig(['acme_importmonitor/general/enabled' => '1']),
            'acme_importmonitor',
            $this->encryptor()
        );

        self::assertTrue($config->isEnabled());
    }

    public function testAnUnconfiguredStoreHasTheFeatureOff(): void
    {
        self::assertFalse($this->config([])->isEnabled());
        self::assertFalse($this->config(['general/enabled' => '0'])->isEnabled());
    }

    /**
     * Honoured, not clamped.
     */
    public function testAGenerousStuckThresholdIsHonouredRatherThanCapped(): void
    {
        self::assertSame(8, $this->config(['general/stuck_threshold_hours' => '8'])->getStuckThresholdHours());
        self::assertSame(168, $this->config(['general/stuck_threshold_hours' => '168'])->getStuckThresholdHours());
    }

    /**
     * A zero threshold would call every running import stuck the moment it
     * started, so it falls back rather than being honoured.
     */
    public function testANonsensicalStuckThresholdFallsBackToTheDefault(): void
    {
        foreach (['0', '-4', '', 'soon'] as $value) {
            self::assertSame(
                Config::DEFAULT_STUCK_THRESHOLD_HOURS,
                $this->config(['general/stuck_threshold_hours' => $value])->getStuckThresholdHours(),
                sprintf('"%s" should fall back to the default.', $value)
            );
        }
    }

    /**
     * Feeds arrive in the evening, so before this hour yesterday's file is
     * still the newest legitimate one.
     */
    public function testTheFeedStrictHourDefaultsToTheEvening(): void
    {
        self::assertSame(Config::DEFAULT_STRICT_HOUR, $this->config([])->getFeedStrictHour());
        self::assertGreaterThan(12, Config::DEFAULT_STRICT_HOUR);
    }

    /**
     * An hour outside 0-23 is a typo, and honouring it would either demand
     * today's feed at all times or never demand it at all.
     */
    public function testAnHourOutsideTheDayIsClampedIntoIt(): void
    {
        self::assertSame(0, $this->config(['general/feed_strict_hour' => '-3'])->getFeedStrictHour());
        self::assertSame(23, $this->config(['general/feed_strict_hour' => '47'])->getFeedStrictHour());
        self::assertSame(6, $this->config(['general/feed_strict_hour' => '6'])->getFeedStrictHour());
    }

    /**
     * Zero is a real setting - "today's feed is required from midnight" - and
     * has to survive rather than reading as unset.
     */
    public function testMidnightIsAValidStrictHour(): void
    {
        self::assertSame(0, $this->config(['general/feed_strict_hour' => '0'])->getFeedStrictHour());
    }

    public function testTheRetentionWindowFallsBackToTheDefaultWhenNonsensical(): void
    {
        self::assertSame(Config::DEFAULT_RETENTION_DAYS, $this->config([])->getRetentionDays());
        self::assertSame(
            Config::DEFAULT_RETENTION_DAYS,
            $this->config(['general/retention_days' => '0'])->getRetentionDays()
        );
        self::assertSame(7, $this->config(['general/retention_days' => '7'])->getRetentionDays());
    }

    public function testTheRecipientsAreSplitAndTrimmed(): void
    {
        $config = $this->config(['notification/recipients' => 'a@example.test , b@example.test ,,']);

        self::assertSame(['a@example.test', 'b@example.test'], $config->getRecipients());
        self::assertSame([], $this->config([])->getRecipients());
    }

    public function testTheTemplatesAndSenderFallBackToShippedDefaults(): void
    {
        $config = $this->config([]);

        self::assertSame('general', $config->getSenderIdentity());
        self::assertSame('commerce_import_monitor_alert', $config->getAlertTemplate());
        self::assertSame('commerce_import_monitor_resolved', $config->getResolvedTemplate());
    }

    /**
     * The alert and the resolution are different templates, so a store can word
     * the all-clear differently from the alarm.
     */
    public function testTheAlertAndResolvedTemplatesAreConfiguredSeparately(): void
    {
        $config = $this->config([
            'notification/alert_template' => 'acme_alert',
            'notification/resolved_template' => 'acme_resolved',
        ]);

        self::assertSame('acme_alert', $config->getAlertTemplate());
        self::assertSame('acme_resolved', $config->getResolvedTemplate());
    }

    /**
     * Off by default, because `gethostname()` publishes internal host naming to
     * a chat workspace.
     */
    public function testTheHostnameIsWithheldUnlessTheStoreAsksForIt(): void
    {
        self::assertFalse($this->config([])->shouldIncludeHostname());
        self::assertTrue($this->config(['notification/include_hostname' => '1'])->shouldIncludeHostname());
    }

    /**
     * A Slack bot token can read and post to a whole workspace.
     */
    public function testTheSlackTokenIsDecryptedOnTheWayOut(): void
    {
        self::assertSame(
            'xoxb-real-token', // pragma: allowlist secret
            $this->config(['slack/token' => 'encrypted:xoxb-real-token'])->getSlackToken() // pragma: allowlist secret
        );
    }

    public function testAnUnsetSlackTokenIsEmptyRatherThanDecrypted(): void
    {
        self::assertSame('', $this->config([])->getSlackToken());
    }

    /**
     * The admin field accepts a channel with or without its leading hash; the
     * API wants it without.
     */
    public function testTheChannelIsNormalisedForTheApi(): void
    {
        self::assertSame('ops-alerts', $this->config(['slack/channel' => '#ops-alerts'])->getSlackChannel());
        self::assertSame('ops-alerts', $this->config(['slack/channel' => 'ops-alerts'])->getSlackChannel());
    }

    /**
     * The flag alone would have every alert attempt a post that cannot succeed.
     */
    public function testSlackIsOnlyEnabledOnceItIsFullyConfigured(): void
    {
        self::assertFalse($this->config(['slack/enabled' => '1'])->isSlackEnabled());
        self::assertFalse(
            $this->config(['slack/enabled' => '1', 'slack/token' => 'encrypted:xoxb'])->isSlackEnabled()
        );
        self::assertFalse(
            $this->config(['slack/enabled' => '1', 'slack/channel' => '#ops'])->isSlackEnabled()
        );
        self::assertTrue(
            $this->config([
                'slack/enabled' => '1',
                'slack/token' => 'encrypted:xoxb',
                'slack/channel' => '#ops',
            ])->isSlackEnabled()
        );
    }

    public function testSlackStaysOffWhenTheFlagIsNotSetEvenIfCredentialsExist(): void
    {
        self::assertFalse(
            $this->config(['slack/token' => 'encrypted:xoxb', 'slack/channel' => '#ops'])->isSlackEnabled()
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function config(array $values): Config
    {
        $qualified = [];

        foreach ($values as $path => $value) {
            $qualified['test_importmonitor/' . $path] = $value;
        }

        return new Config(new ArrayScopeConfig($qualified), 'test_importmonitor', $this->encryptor());
    }

    private function encryptor(): EncryptorInterface
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $value): string => str_starts_with($value, 'encrypted:')
                ? substr($value, strlen('encrypted:'))
                : $value
        );

        return $encryptor;
    }
}
