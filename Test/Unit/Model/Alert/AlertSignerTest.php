<?php
/**
 * AlertSignerTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Alert;

use Commerce\ImportMonitor\Model\Alert\AlertSigner;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An acknowledge endpoint taking a bare sequential id can be walked: `id=1..n`
 * silences every alert in the system.
 */
class AlertSignerTest extends TestCase
{
    private DeploymentConfig&MockObject $deploymentConfig;
    private AlertSigner $signer;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->deploymentConfig->method('get')
            ->with(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY)
            ->willReturn('a-test-crypt-key');

        $this->signer = new AlertSigner($this->deploymentConfig);
    }

    public function testASignatureVerifiesForItsOwnAlert(): void
    {
        self::assertTrue($this->signer->verify(42, $this->signer->sign(42)));
    }

    /**
     * The attack the signature exists to stop: taking a link you were sent and
     * walking the id to acknowledge everything else.
     */
    public function testASignatureDoesNotVerifyForAnotherAlert(): void
    {
        $token = $this->signer->sign(42);

        foreach ([1, 41, 43, 999] as $otherId) {
            self::assertFalse($this->signer->verify($otherId, $token), "id $otherId accepted alert 42's token");
        }
    }

    public function testAnEmptyOrGarbageTokenIsRejected(): void
    {
        self::assertFalse($this->signer->verify(42, ''));
        self::assertFalse($this->signer->verify(42, 'not-a-signature'));
        self::assertFalse($this->signer->verify(42, str_repeat('0', 32)));
    }

    public function testSignaturesAreStableForTheSameKeyAndId(): void
    {
        self::assertSame($this->signer->sign(42), $this->signer->sign(42));
    }

    public function testADifferentCryptKeyProducesADifferentSignature(): void
    {
        $other = $this->createMock(DeploymentConfig::class);
        $other->method('get')->willReturn('a-different-crypt-key');

        self::assertNotSame($this->signer->sign(42), (new AlertSigner($other))->sign(42));
    }

    public function testTheSignatureIsLongEnoughToResistGuessing(): void
    {
        // 32 hex characters is 128 bits.
        self::assertSame(32, strlen($this->signer->sign(42)));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->signer->sign(42));
    }
}
