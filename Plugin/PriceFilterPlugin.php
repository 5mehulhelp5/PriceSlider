<?php
declare(strict_types=1);

namespace WB\PriceSlider\Plugin;

/**
 * This plugin class is intentionally empty.
 *
 * The previous aroundApply implementation on Magento\Catalog\Model\Layer\Filter\Price
 * double-applied the price filter and conflicted with BSS MultiStoreViewPricing's
 * own price-filter preference (Bss\MultiStoreViewPricing\Model\CatalogSearch\Layer\Filter\Price).
 *
 * Price filtering is now handled entirely by the native Magento/BSS layer filter
 * pipeline.  The slider submits ?price=min-max which BSS/Magento reads and applies
 * against the correct price index table automatically.
 *
 * The plugin type reference has been removed from etc/frontend/di.xml.
 */
class PriceFilterPlugin
{
}
