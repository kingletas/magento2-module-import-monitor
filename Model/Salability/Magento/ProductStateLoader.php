<?php
/**
 * ProductStateLoader.php
 *
 * @package     Commerce_ImportMonitor
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\ImportMonitor\Model\Salability\Magento;

use Commerce\ImportMonitor\Api\SalableQuantityProviderInterface;
use Commerce\ImportMonitor\Model\Salability\ProductState;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Zend_Db_Expr;

/**
 * Loads the Magento side of a batch of SKUs in a fixed number of queries.
 */
class ProductStateLoader
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly SalableQuantityProviderInterface $quantityProvider,
        private readonly ProductStateMapper $stateMapper,
        private readonly string $siteStatusAttribute = 'site_status',
        private readonly string $masterStatusAttribute = 'master_status'
    ) {
    }

    /**
     * @param string[] $skus
     *
     * @return array<string, ProductState> Keyed by SKU; every requested SKU is present.
     *
     * @throws LocalizedException
     */
    public function load(array $skus, ?int $websiteId = null): array
    {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));

        if ($skus === []) {
            return [];
        }

        $rows = $this->loadRows($skus);
        $quantities = $this->quantityProvider->getSalableQuantities($skus, $websiteId);
        $salability = $this->quantityProvider->getSalabilityStatuses($skus, $websiteId);

        $states = [];

        foreach ($skus as $sku) {
            $states[$sku] = isset($rows[$sku])
                ? $this->stateMapper->fromRow(
                    $rows[$sku],
                    $salability[$sku] ?? false,
                    (float) ($quantities[$sku] ?? 0.0)
                )
                // A SKU with no product row is a real answer, not a missing
                // one, so every requested SKU comes back with a state.
                : new ProductState(exists: false);
        }

        return $states;
    }

    /**
     * @param string[] $skus
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws LocalizedException
     */
    private function loadRows(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $intTable = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $quotedLinkField = $connection->quoteIdentifier($linkField);

        $select = $connection->select()
            ->from(['p' => $productTable], ['sku', 'entity_id', 'type_id'])
            ->joinLeft(
                ['st' => $intTable],
                $connection->quoteInto(
                    sprintf(
                        'st.%s = p.%s AND st.attribute_id = ? AND st.store_id = 0',
                        $quotedLinkField,
                        $quotedLinkField
                    ),
                    $this->attributeId(ProductInterface::STATUS)
                ),
                ['status' => 'value']
            )
            ->where('p.sku IN (?)', $skus);

        $this->joinStatusAttribute(
            $select,
            $connection,
            $varcharTable,
            $quotedLinkField,
            'ss',
            $this->siteStatusAttribute,
            'site_status'
        );
        $this->joinStatusAttribute(
            $select,
            $connection,
            $varcharTable,
            $quotedLinkField,
            'ms',
            $this->masterStatusAttribute,
            'master_status'
        );

        $rows = [];

        foreach ($connection->fetchAll($select) as $row) {
            $rows[(string) $row['sku']] = $row;
        }

        return $rows;
    }

    /**
     * Join one optional status attribute, tolerating its absence.
     */
    private function joinStatusAttribute(
        \Magento\Framework\DB\Select $select,
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $table,
        string $quotedLinkField,
        string $alias,
        string $attributeCode,
        string $columnAlias
    ): void {
        $attributeId = $this->attributeId($attributeCode);

        if ($attributeId === null) {
            $select->columns([$columnAlias => new Zend_Db_Expr("''")]);

            return;
        }

        $select->joinLeft(
            [$alias => $table],
            $connection->quoteInto(
                sprintf(
                    '%1$s.%2$s = p.%2$s AND %1$s.attribute_id = ? AND %1$s.store_id = 0',
                    $alias,
                    $quotedLinkField
                ),
                $attributeId
            ),
            [$columnAlias => 'value']
        );
    }

    private function attributeId(string $attributeCode): ?int
    {
        try {
            $attribute = $this->eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $attributeCode);
            $id = (int) $attribute->getAttributeId();

            return $id > 0 ? $id : null;
        } catch (LocalizedException) {
            return null;
        }
    }
}
