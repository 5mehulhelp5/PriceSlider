<?php
declare(strict_types=1);

namespace WB\PriceSlider\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Queries the Magento price index tables to obtain the price range for a category.
 *
 * BSS MultiStoreViewPricing compatibility:
 *   When catalog/price/scope == 2 (store-view scope introduced by BSS), prices are
 *   stored per store in `catalog_product_index_price_store`.  Otherwise the standard
 *   Magento `catalog_product_index_price` table (website-scoped) is used.
 *
 * Gracefully degrades when BSS is not installed (scope never equals 2).
 */
class PriceRange
{
    /** Value BSS MultiStoreViewPricing stores in catalog/price/scope for store-level pricing. */
    private const BSS_STORE_PRICE_SCOPE = 2;

    private const TABLE_PRICE_INDEX       = 'catalog_product_index_price';
    private const TABLE_PRICE_INDEX_STORE = 'catalog_product_index_price_store';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Internal per-request cache: cacheKey => ['min' => float, 'max' => float].
     *
     * @var array
     */
    private $cache = [];

    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        CustomerSession $customerSession,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->resource        = $resource;
        $this->storeManager    = $storeManager;
        $this->customerSession = $customerSession;
        $this->scopeConfig     = $scopeConfig;
    }

    /**
     * Return ['min' => float, 'max' => float] in the store's BASE currency.
     *
     * @param int   $categoryId        Current category.
     * @param int[] $descendantCategoryIds All category IDs to include (category + children).
     * @return array
     */
    public function getRangeForCategory(int $categoryId, array $descendantCategoryIds): array
    {
        $storeId    = $this->getStoreId();
        $cacheKey   = $storeId . '_' . md5(implode(',', $descendantCategoryIds));

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $connection      = $this->resource->getConnection();
        $websiteId       = $this->getWebsiteId();
        $customerGroupId = $this->getCustomerGroupId();
        $useBssPricing   = $this->isBssStorePricingActive();

        // Defensive: if scope=2 is set but BSS is not installed (table absent), fall back
        // to the standard price index so the module never crashes on vanilla Magento.
        if ($useBssPricing) {
            $bssTable = $this->resource->getTableName(self::TABLE_PRICE_INDEX_STORE);
            if (!$connection->isTableExists($bssTable)) {
                $useBssPricing = false;
            }
        }

        $priceTable = $this->resource->getTableName(
            $useBssPricing ? self::TABLE_PRICE_INDEX_STORE : self::TABLE_PRICE_INDEX
        );

        // Resolve the correct category product index table
        $catIndexTable = $this->resolveCategoryIndexTable($storeId);

        // Ensure we have the current category in the list
        $categoryIds = $descendantCategoryIds;
        if (!in_array($categoryId, $categoryIds, true)) {
            array_unshift($categoryIds, $categoryId);
        }
        $categoryIdsCsv = implode(',', array_unique(array_map('intval', $categoryIds)));

        $select = $connection->select()
            ->from(
                ['price_index' => $priceTable],
                [
                    'min_price' => 'MIN(price_index.final_price)',
                    'max_price' => 'MAX(price_index.final_price)',
                ]
            );

        // Join to category product index to restrict results to this category's products
        if ($catIndexTable) {
            $select->join(
                ['cat_idx' => $catIndexTable],
                'cat_idx.product_id = price_index.entity_id'
                . ' AND cat_idx.category_id IN (' . $categoryIdsCsv . ')'
                . ' AND cat_idx.store_id = ' . $storeId,
                []
            );
        } else {
            // Fallback: catalog_category_product (direct assignments only, no anchoring)
            $fallback = $this->resource->getTableName('catalog_category_product');
            $select->join(
                ['cat_idx' => $fallback],
                'cat_idx.product_id = price_index.entity_id'
                . ' AND cat_idx.category_id IN (' . $categoryIdsCsv . ')',
                []
            );
        }

        // Scope filter
        if ($useBssPricing) {
            $select->where('price_index.store_id = ?', $storeId);
        } else {
            $select->where('price_index.website_id = ?', $websiteId);
        }

        $select->where('price_index.customer_group_id = ?', $customerGroupId);
        $select->where('price_index.final_price > 0');

        $row = $connection->fetchRow($select);

        $result = [
            'min' => max(0.0, (float)($row['min_price'] ?? 0)),
            'max' => (float)($row['max_price'] ?? 0),
        ];

        $this->cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * True when BSS MultiStoreViewPricing store-view scope is active (catalog/price/scope = 2).
     * Standard Magento only supports 0 (global) and 1 (website), so this flag is always false
     * when the BSS module is absent.
     *
     * @return bool
     */
    public function isBssStorePricingActive(): bool
    {
        return (int)$this->scopeConfig->getValue(
            'catalog/price/scope',
            ScopeInterface::SCOPE_STORE
        ) === self::BSS_STORE_PRICE_SCOPE;
    }

    /**
     * Attempt to resolve the store-specific category product index table.
     * Magento 2.3+ creates partitioned tables per store: catalog_category_product_index_store{n}.
     * Falls back to the legacy shared table, and finally to null (caller uses catalog_category_product).
     *
     * @param int $storeId
     * @return string|null
     */
    private function resolveCategoryIndexTable(int $storeId): ?string
    {
        $connection = $this->resource->getConnection();

        // Partitioned (Magento 2.3+)
        $partitioned = $this->resource->getTableName(
            'catalog_category_product_index_store' . $storeId
        );
        if ($connection->isTableExists($partitioned)) {
            return $partitioned;
        }

        // Legacy shared table (Magento 2.2 and earlier)
        $legacy = $this->resource->getTableName('catalog_category_product_index');
        if ($connection->isTableExists($legacy)) {
            return $legacy;
        }

        return null;
    }

    /**
     * @return int
     */
    private function getStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }

    /**
     * @return int
     */
    private function getWebsiteId(): int
    {
        return (int)$this->storeManager->getStore()->getWebsiteId();
    }

    /**
     * Returns the current customer group ID (0 = NOT_LOGGED_IN for guests).
     *
     * @return int
     */
    private function getCustomerGroupId(): int
    {
        return (int)$this->customerSession->getCustomerGroupId();
    }
}
