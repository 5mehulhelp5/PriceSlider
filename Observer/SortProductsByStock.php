<?php
declare(strict_types=1);

namespace WB\PriceSlider\Observer;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Sorts the catalog layer product collection by stock availability:
 *   1. In Stock (no pre-order)
 *   2. Pre-order / Made to Order  (BSS PreOrder attribute pre_order_status IN 1, 2)
 *   3. Out of Stock
 *
 * Only fires on catalog_category_view pages.
 * Requires "Sort by Stock Status" to be enabled in admin configuration.
 *
 * BSS PreOrder compatibility: detects the pre_order_status attribute dynamically;
 * falls back to a simple in-stock/out-of-stock binary sort when BSS PreOrder is absent.
 */
class SortProductsByStock implements ObserverInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * Cached pre_order_status attribute_id (0 = not found / BSS PreOrder absent).
     *
     * @var int|null
     */
    private $preOrderAttrId = null;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ResourceConnection $resource,
        RequestInterface $request
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->resource    = $resource;
        $this->request     = $request;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Guard: feature disabled
        if (!$this->isModuleEnabled() || !$this->isSortByStockEnabled()) {
            return;
        }

        // Guard: only category listing pages
        if ($this->request->getFullActionName() !== 'catalog_category_view') {
            return;
        }

        $collection = $observer->getCollection();
        if (!$collection instanceof ProductCollection) {
            return;
        }

        $this->addStockSort($collection);
    }

    /**
     * Add stock-status ORDER BY to the collection SELECT.
     * Uses a LEFT JOIN on cataloginventory_stock_status and an optional
     * subquery-based LEFT JOIN for BSS PreOrder status.
     *
     * The sort is prepended so it acts as the primary sort when the user
     * is on the default "position" view, and as a tiebreaker otherwise.
     *
     * @param ProductCollection $collection
     * @return void
     */
    private function addStockSort(ProductCollection $collection): void
    {
        $select   = $collection->getSelect();
        $fromPart = $select->getPart(Select::FROM);

        // Prevent double-application
        if (isset($fromPart['wb_ps_stock'])) {
            return;
        }

        $stockTable = $this->resource->getTableName('cataloginventory_stock_status');

        $select->joinLeft(
            ['wb_ps_stock' => $stockTable],
            'wb_ps_stock.product_id = e.entity_id AND wb_ps_stock.stock_id = 1',
            []
        );

        $preOrderAttrId = $this->resolvePreOrderAttributeId();
        $storeId        = (int)$collection->getStoreId();

        if ($preOrderAttrId > 0) {
            $sortExpr = $this->buildThreeTierSortExpr($preOrderAttrId, $storeId);
        } else {
            // Binary: In Stock first, Out of Stock last
            $sortExpr = new \Zend_Db_Expr('wb_ps_stock.stock_status DESC');
        }

        // Prepend: save existing ORDER BY, reset, add ours first, restore theirs
        $existingOrder = $select->getPart(Select::ORDER);
        $select->reset(Select::ORDER);
        $select->order($sortExpr);
        foreach ($existingOrder as $existingExpr) {
            $select->order($existingExpr);
        }
    }

    /**
     * Build a CASE WHEN expression that gives a numeric sort priority:
     *   1 = In Stock (pre_order_status absent or 0)
     *   2 = Pre-order (pre_order_status = 1 or 2)
     *   3 = Out of Stock
     *
     * Uses COALESCE over two correlated sub-selects to honour store-level EAV
     * overrides without adding duplicate rows from a plain JOIN.
     *
     * @param int $attrId
     * @param int $storeId
     * @return \Zend_Db_Expr
     */
    private function buildThreeTierSortExpr(int $attrId, int $storeId): \Zend_Db_Expr
    {
        $eavTable = $this->resource->getTableName('catalog_product_entity_int');
        $conn     = $this->resource->getConnection();

        // Store-specific value sub-select
        $storeVal = '(SELECT value FROM ' . $conn->quoteIdentifier($eavTable)
            . ' WHERE entity_id = e.entity_id'
            . ' AND attribute_id = ' . $attrId
            . ' AND store_id = ' . $storeId
            . ' LIMIT 1)';

        // Global (store_id=0) fallback
        $globalVal = '(SELECT value FROM ' . $conn->quoteIdentifier($eavTable)
            . ' WHERE entity_id = e.entity_id'
            . ' AND attribute_id = ' . $attrId
            . ' AND store_id = 0'
            . ' LIMIT 1)';

        $coalesced = 'COALESCE(' . $storeVal . ', ' . $globalVal . ', 0)';

        return new \Zend_Db_Expr(
            'CASE'
            . ' WHEN wb_ps_stock.stock_status = 1 AND ' . $coalesced . ' = 0 THEN 1'
            . ' WHEN ' . $coalesced . ' IN (1, 2) THEN 2'
            . ' ELSE 3'
            . ' END ASC'
        );
    }

    /**
     * Look up the attribute_id for pre_order_status in eav_attribute.
     * Returns 0 when BSS PreOrder is not installed.
     *
     * @return int
     */
    private function resolvePreOrderAttributeId(): int
    {
        if ($this->preOrderAttrId !== null) {
            return $this->preOrderAttrId;
        }

        try {
            $connection   = $this->resource->getConnection();
            $attrTable    = $this->resource->getTableName('eav_attribute');
            $etypeTable   = $this->resource->getTableName('eav_entity_type');

            $select = $connection->select()
                ->from(['attr' => $attrTable], ['attribute_id'])
                ->join(
                    ['etype' => $etypeTable],
                    'etype.entity_type_id = attr.entity_type_id',
                    []
                )
                ->where('etype.entity_type_code = ?', 'catalog_product')
                ->where('attr.attribute_code = ?', 'pre_order_status')
                ->limit(1);

            $this->preOrderAttrId = (int)$connection->fetchOne($select);
        } catch (\Exception $e) {
            $this->preOrderAttrId = 0;
        }

        return $this->preOrderAttrId;
    }

    /**
     * @return bool
     */
    private function isModuleEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'wb_priceslider/general/enable',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return bool
     */
    private function isSortByStockEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'wb_priceslider/general/sort_by_stock',
            ScopeInterface::SCOPE_STORE
        );
    }
}
