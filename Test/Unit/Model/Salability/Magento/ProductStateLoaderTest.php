<?php
/**
 * ProductStateLoaderTest.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Test\Unit\Model\Salability\Magento;

use Commerce\ImportMonitor\Api\SalableQuantityProviderInterface;
use Commerce\ImportMonitor\Model\Salability\Magento\ProductStateLoader;
use Commerce\ImportMonitor\Model\Salability\Magento\ProductStateMapper;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every requested SKU comes back with a state.
 */
class ProductStateLoaderTest extends TestCase
{
    private ProductStateLoader $loader;

    /** @var array<int, array<string, mixed>> Rows the product query returns. */
    private array $rows = [];

    /** @var array<string, float> */
    private array $quantities = [];

    /** @var array<string, bool> */
    private array $salability = [];

    protected function setUp(): void
    {
        $this->rows = [];
        $this->quantities = [];
        $this->salability = [];

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $i): string => '`' . $i . '`');
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace('?', (string) $value, $text)
        );
        $connection->method('fetchAll')->willReturnCallback(fn (): array => $this->rows);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');

        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(93);

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $provider = $this->createMock(SalableQuantityProviderInterface::class);
        $provider->method('getSalableQuantities')->willReturnCallback(fn (): array => $this->quantities);
        $provider->method('getSalabilityStatuses')->willReturnCallback(fn (): array => $this->salability);

        $this->loader = new ProductStateLoader(
            $resourceConnection,
            $metadataPool,
            $eavConfig,
            $provider,
            new ProductStateMapper()
        );
    }

    public function testNoSkusMeansNoQueryAndNoStates(): void
    {
        $this->assertSame([], $this->loader->load([]));
    }

    /**
     * A SKU with no product row still gets a state, flagged as not existing.
     */
    public function testASkuTheCatalogueDoesNotHaveComesBackAsNotExisting(): void
    {
        $states = $this->loader->load(['SKU-GONE']);

        $this->assertArrayHasKey('SKU-GONE', $states);
        $this->assertFalse($states['SKU-GONE']->exists);
    }

    public function testAKnownSkuCarriesItsStatusQuantityAndSalability(): void
    {
        $this->rows = [
            ['sku' => 'SKU-1', 'entity_id' => '10', 'type_id' => 'simple', 'status' => '1'],
        ];
        $this->quantities = ['SKU-1' => 4.0];
        $this->salability = ['SKU-1' => true];

        $state = $this->loader->load(['SKU-1'])['SKU-1'];

        $this->assertTrue($state->exists);
        $this->assertTrue($state->isSimple);
        $this->assertTrue($state->isEnabled);
        $this->assertTrue($state->isSalable);
        $this->assertSame(4.0, $state->quantity);
    }

    /**
     * A provider that knows nothing about a SKU defaults to not sellable rather
     * than sellable.
     */
    public function testAnAbsentQuantityDefaultsToZeroAndNotSalable(): void
    {
        $this->rows = [
            ['sku' => 'SKU-1', 'entity_id' => '10', 'type_id' => 'simple', 'status' => '1'],
        ];

        $state = $this->loader->load(['SKU-1'])['SKU-1'];

        $this->assertSame(0.0, $state->quantity);
        $this->assertFalse($state->isSalable);
    }

    public function testDuplicateAndBlankSkusAreNormalisedAway(): void
    {
        $states = $this->loader->load(['SKU-1', 'SKU-1', '']);

        $this->assertSame(['SKU-1'], array_keys($states));
    }
}
