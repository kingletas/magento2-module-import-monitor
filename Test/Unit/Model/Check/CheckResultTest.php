<?php
/**
 * CheckResultTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Check;

use Commerce\ImportMonitor\Model\Check\CheckResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase
{
    public function testAHealthyResultHasNoFingerprintAndNoMessage(): void
    {
        $result = new CheckResult(isHealthy: true, checkCode: 'feed');

        $this->assertTrue($result->isHealthy);
        $this->assertNull($result->fingerprint);
        $this->assertNull($result->message);
    }

    /**
     * The fingerprint identifies the fault; the message describes one sighting
     * of it.
     */
    public function testTheFingerprintIsDerivedFromTheSeedRatherThanTheMessage(): void
    {
        $first = new CheckResult(false, 'feed', 'overdue', 'not arrived at 21:15');
        $second = new CheckResult(false, 'feed', 'overdue', 'not arrived at 21:30');

        $this->assertNotNull($first->fingerprint);
        $this->assertSame($first->fingerprint, $second->fingerprint);
    }

    public function testAFailureNeedsBothASeedAndAMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CheckResult(isHealthy: false, checkCode: 'feed', message: 'it broke');
    }

    public function testAFailureNeedsAMessageAsWellAsASeed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CheckResult(isHealthy: false, checkCode: 'feed', fingerprintSeed: 'overdue');
    }
}
