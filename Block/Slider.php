<?php
declare(strict_types=1);

namespace WB\PriceSlider\Block;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use WB\PriceSlider\Model\PriceRange;

class Slider extends Template
{
    /**
     * @var Resolver
     */
    private $layerResolver;

    /**
     * @var CurrencyFactory
     */
    private $currencyFactory;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var PriceRange
     */
    private $priceRange;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var array|null
     */
    private $rangeCache = null;

    /**
     * @var float|null
     */
    private $rateCache = null;

    public function __construct(
        Template\Context $context,
        Resolver $layerResolver,
        CurrencyFactory $currencyFactory,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        PriceRange $priceRange,
        ResourceConnection $resourceConnection,
        array $data = []
    ) {
        $this->layerResolver      = $layerResolver;
        $this->currencyFactory    = $currencyFactory;
        $this->scopeConfig        = $scopeConfig;
        $this->storeManager       = $storeManager;
        $this->priceRange         = $priceRange;
        $this->resourceConnection = $resourceConnection;
        parent::__construct($context, $data);
    }

    /**
     * @return bool
     */
    public function canShowSlider(): bool
    {
        return $this->scopeConfig->isSetFlag(
            'wb_priceslider/general/enable',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Minimum price in BASE currency (what the price index stores).
     *
     * @return float
     */
    public function getBaseMinPrice(): float
    {
        return $this->fetchRange()['min'];
    }

    /**
     * Maximum price in BASE currency.
     *
     * @return float
     */
    public function getBaseMaxPrice(): float
    {
        return $this->fetchRange()['max'];
    }

    /**
     * Minimum price in DISPLAY currency (shown to user).
     *
     * @return float
     */
    public function getDisplayMinPrice(): float
    {
        return round($this->getBaseMinPrice() * $this->getCurrencyRate(), 2);
    }

    /**
     * Maximum price in DISPLAY currency (shown to user).
     *
     * @return float
     */
    public function getDisplayMaxPrice(): float
    {
        return round($this->getBaseMaxPrice() * $this->getCurrencyRate(), 2);
    }

    /**
     * Currently selected min from URL ?price=min-max, in BASE currency.
     * Falls back to category min when no filter is active.
     *
     * @return float
     */
    public function getSelectedBaseMin(): float
    {
        $parts = $this->parsePriceParam();
        return $parts !== null ? $parts[0] : $this->getBaseMinPrice();
    }

    /**
     * Currently selected max from URL, in BASE currency.
     *
     * @return float
     */
    public function getSelectedBaseMax(): float
    {
        $parts = $this->parsePriceParam();
        return $parts !== null ? $parts[1] : $this->getBaseMaxPrice();
    }

    /**
     * Selected min converted to display currency.
     *
     * @return float
     */
    public function getSelectedDisplayMin(): float
    {
        return round($this->getSelectedBaseMin() * $this->getCurrencyRate(), 2);
    }

    /**
     * Selected max converted to display currency.
     *
     * @return float
     */
    public function getSelectedDisplayMax(): float
    {
        return round($this->getSelectedBaseMax() * $this->getCurrencyRate(), 2);
    }

    /**
     * Exchange rate: display currency units per 1 base currency unit.
     * Returns 1.0 when base === display (typical per-store setup with BSS MultiStoreViewPricing).
     *
     * @return float
     */
    public function getCurrencyRate(): float
    {
        if ($this->rateCache !== null) {
            return $this->rateCache;
        }

        try {
            $store       = $this->storeManager->getStore();
            $baseCode    = $store->getBaseCurrencyCode();
            $displayCode = $store->getCurrentCurrencyCode();

            if ($baseCode === $displayCode) {
                $this->rateCache = 1.0;
            } else {
                $rate = $this->currencyFactory->create()->load($baseCode)->getRate($displayCode);
                $this->rateCache = ($rate && $rate > 0) ? (float)$rate : 1.0;
            }
        } catch (\Exception $e) {
            $this->rateCache = 1.0;
        }

        return $this->rateCache;
    }

    /**
     * Display currency symbol (e.g. "Rs", "$").
     *
     * @return string
     */
    public function getCurrencySymbol(): string
    {
        try {
            $store  = $this->storeManager->getStore();
            $code   = $store->getCurrentCurrencyCode();
            $symbol = (string)$this->currencyFactory->create()->load($code)->getCurrencySymbol();
            // getCurrencySymbol() returns empty or just the code for some currencies (e.g. PKR).
            // Fall back to the currency code so something meaningful always displays.
            return ($symbol && $symbol !== $code) ? $symbol : $code;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Slider step in display currency units (admin-configurable, minimum 1).
     *
     * @return int
     */
    public function getSliderStep(): int
    {
        $step = (int)$this->scopeConfig->getValue(
            'wb_priceslider/general/step',
            ScopeInterface::SCOPE_STORE
        );
        return max(1, $step);
    }

    /**
     * Whether a price filter is currently applied via URL.
     *
     * @return bool
     */
    public function isPriceFilterActive(): bool
    {
        return (bool)$this->_request->getParam('price');
    }

    /**
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->canShowSlider()) {
            return '';
        }

        $min = $this->getBaseMinPrice();
        $max = $this->getBaseMaxPrice();

        // Do not render if the category has no priced products or flat distribution
        if ($max <= 0 || $min >= $max) {
            return '';
        }

        return parent::_toHtml();
    }

    /**
     * Parse ?price=min-max from the current request.
     * Returns [minBase, maxBase] floats or null when absent/invalid.
     *
     * @return float[]|null
     */
    private function parsePriceParam(): ?array
    {
        $param = (string)$this->_request->getParam('price', '');
        if ($param === '') {
            return null;
        }

        $parts = explode('-', $param);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }

        $min = max(0.0, (float)$parts[0]);
        $max = (float)$parts[1];

        return ($max > $min) ? [$min, $max] : null;
    }

    /**
     * Fetch and internally cache the base-currency price range for the current category.
     *
     * @return array ['min' => float, 'max' => float]
     */
    private function fetchRange(): array
    {
        if ($this->rangeCache !== null) {
            return $this->rangeCache;
        }

        try {
            $layer    = $this->layerResolver->get();
            $category = $layer->getCurrentCategory();

            if (!$category || !$category->getId()) {
                $this->rangeCache = ['min' => 0.0, 'max' => 0.0];
                return $this->rangeCache;
            }

            $descendantIds    = $this->getDescendantCategoryIds($category);
            $this->rangeCache = $this->priceRange->getRangeForCategory(
                (int)$category->getId(),
                $descendantIds
            );
        } catch (\Exception $e) {
            $this->rangeCache = ['min' => 0.0, 'max' => 0.0];
        }

        return $this->rangeCache;
    }

    /**
     * Return IDs of the category and all its descendants.
     * For non-anchor categories only the given category ID is returned.
     *
     * @param Category $category
     * @return int[]
     */
    private function getDescendantCategoryIds(Category $category): array
    {
        $ids = [(int)$category->getId()];

        if (!$category->getIsAnchor()) {
            return $ids;
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table      = $this->resourceConnection->getTableName('catalog_category_entity');
            $select     = $connection->select()
                ->from($table, ['entity_id'])
                ->where('path LIKE ?', $category->getPath() . '/%');
            $childIds   = $connection->fetchCol($select);
            $ids        = array_merge($ids, array_map('intval', $childIds));
        } catch (\Exception $e) {
            // returns only the current category ID
        }

        return $ids;
    }
}
