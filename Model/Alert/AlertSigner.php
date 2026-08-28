<?php
/**
 * AlertSigner.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Alert;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;

/**
 * Signs and verifies acknowledge links.
 */
class AlertSigner
{
    private const string ALGORITHM = 'sha256';

    /**
     * Truncated to keep the URL readable; 32 hex characters is 128 bits, far
     * beyond what an online guessing attack could reach.
     */
    private const int SIGNATURE_LENGTH = 32;

    private ?string $key = null;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function sign(int $alertId): string
    {
        return substr(
            hash_hmac(self::ALGORITHM, 'alert:' . $alertId, $this->key()),
            0,
            self::SIGNATURE_LENGTH
        );
    }

    public function verify(int $alertId, string $signature): bool
    {
        return hash_equals($this->sign($alertId), $signature);
    }

    private function key(): string
    {
        return $this->key ??= (string) $this->deploymentConfig->get(
            ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY
        );
    }
}
