<?php
/**
 * CheckRunnerTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Check;

use Commerce\ImportMonitor\Api\ImportCheckInterface;
use Commerce\ImportMonitor\Model\Check\CheckResult;
use Commerce\ImportMonitor\Model\Check\CheckRunner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CheckRunnerTest extends TestCase
{
    public function testRunsEveryRegisteredCheck(): void
    {
        $runner = new CheckRunner($this->createMock(LoggerInterface::class), [
            'a' => $this->check('a', true),
            'b' => $this->check('b', false),
        ]);

        self::assertCount(2, $runner->runAll());
        self::assertCount(1, $runner->runFailures());
    }

    /**
     * An exception escaping one check must not abort the run and report
     * healthy.
     */
    public function testAThrowingCheckBecomesAFailureRatherThanStoppingTheRun(): void
    {
        $broken = $this->createMock(ImportCheckInterface::class);
        $broken->method('getCode')->willReturn('broken');
        $broken->method('getLabel')->willReturn('Broken check');
        $broken->method('run')->willThrowException(new RuntimeException('disk gone'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $runner = new CheckRunner($logger, ['broken' => $broken, 'ok' => $this->check('ok', true)]);

        $results = $runner->runAll();

        self::assertCount(2, $results, 'The healthy check must still have run.');
        self::assertFalse($results[0]->isHealthy);
        self::assertStringContainsString('disk gone', (string) $results[0]->message);
    }

    public function testRejectsANonCheckAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CheckRunner($this->createMock(LoggerInterface::class), ['bad' => new \stdClass()]);
    }

    /**
     * Fingerprints identify the fault, not the sighting.
     */
    public function testFingerprintsAreStableAcrossDifferentMessagesForTheSameFault(): void
    {
        $first = new CheckResult(false, 'feed', 'overdue', "today's file has not arrived at 21:15");
        $second = new CheckResult(false, 'feed', 'overdue', "today's file has not arrived at 21:30");

        self::assertSame($first->fingerprint, $second->fingerprint);
    }

    public function testDifferentFaultsGetDifferentFingerprints(): void
    {
        self::assertNotSame(
            (new CheckResult(false, 'feed', 'overdue', 'x'))->fingerprint,
            (new CheckResult(false, 'feed', 'empty', 'x'))->fingerprint
        );
        self::assertNotSame(
            (new CheckResult(false, 'feed', 'overdue', 'x'))->fingerprint,
            (new CheckResult(false, 'task', 'overdue', 'x'))->fingerprint
        );
    }

    private function check(string $code, bool $healthy): ImportCheckInterface
    {
        $check = $this->createMock(ImportCheckInterface::class);
        $check->method('getCode')->willReturn($code);
        $check->method('getLabel')->willReturn(ucfirst($code));
        $check->method('run')->willReturn(
            $healthy
                ? new CheckResult(true, $code)
                : new CheckResult(false, $code, 'seed', 'it broke')
        );

        return $check;
    }
}
