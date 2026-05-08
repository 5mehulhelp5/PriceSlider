# WB Price Slider

[![Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](https://github.com/wajahatbashir/PriceSlider)
[![PHP](https://img.shields.io/badge/php-7.4%20%7C%208.1%20%7C%208.2%20%7C%208.3-8892BF.svg)](https://php.net)
[![Magento](https://img.shields.io/badge/magento-2.3%E2%80%932.4-EE672F.svg)](https://devdocs.magento.com)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

A production-ready **price-range slider** for Magento 2 category pages.

Customers drag two handles to filter products by price. The module submits the standard `?price=min-max` URL parameter that Magento's native layered navigation already understands — no custom PHP filter, no double-filtering, no conflicts.

Built with full compatibility for **BSS MultiStoreViewPricing** (per-store-view base currency) and optional **BSS PreOrder** (3-tier stock sorting). Works equally well on a vanilla Magento installation with neither BSS module installed.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [File Structure](#file-structure)
- [Upgrading from 1.0.x](#upgrading-from-10x)
- [Customisation](#customisation)
- [License](#license)
- [Author](#author)

---

## Features

| Feature | Detail |
|---|---|
| **Drag-to-filter** | jQuery UI dual-handle range slider — no extra dependencies beyond what Magento bundles |
| **Currency symbol** | Displays the store's currency symbol (e.g. `PKR`, `$`) next to each price label |
| **Multi-store** | Reads from `catalog_product_index_price_store` when BSS store-view pricing is active; falls back to the standard `catalog_product_index_price` table otherwise |
| **Multi-currency** | Slider labels show the display currency; `?price=` URL param uses the base currency — matches Magento's native convention |
| **Customer groups** | Respects customer-group-specific pricing (filters `customer_group_id` in the price index) |
| **Anchor categories** | Includes products from all sub-categories via a `path LIKE` query on `catalog_category_entity` |
| **Stock sort** | Optional: In Stock → Pre-order / Made-to-Order → Out of Stock. Detected dynamically; degrades to binary in/out sort when BSS PreOrder is absent |
| **Page-reset on filter** | Always removes `?p=` when a new price range is applied so the user never lands on a non-existent page |
| **Clear-filter sync** | Slider resets when Magento's native "Clear All" or "Remove This Item" links are clicked — both click-intercept and `popstate` strategies |
| **URL preservation** | Uses the browser `URL` / `URLSearchParams` API — existing filters (colour, size, etc.) survive navigation |
| **Zero inline JS/CSS** | All logic in `price-slider.js`; all styles in `price-slider.css`. Template is pure HTML with `data-*` attributes |
| **Admin configuration** | Enable/disable per store view; toggle stock sort; set slider step |
| **Open-source safe** | No hard BSS class imports anywhere; graceful fallbacks for all optional integrations |

---

## Requirements

| Dependency | Version |
|---|---|
| Magento Open Source / Commerce | 2.3.x – 2.4.x |
| PHP | 7.4 / 8.1 / 8.2 / 8.3 |
| jQuery UI | Bundled with Magento (no extra install needed) |

**Optional — enhances behaviour when present**

| Module | Enhancement |
|---|---|
| `Bss_MultiStoreViewPricing` | Per-store-view base currency prices. The slider reads the correct price index table automatically. |
| `Bss_PreOrder` | 3-tier stock sort — In Stock → Pre-order/Made-to-Order → Out of Stock — using the `pre_order_status` EAV attribute. |

The module **never crashes** if these optional modules are absent: it checks the config value and table existence at runtime and falls back gracefully.

---

## Installation

### Via Composer (recommended)

```bash
composer require wb/price-slider
php bin/magento module:enable WB_PriceSlider
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

### Manual (app/code)

1. Copy the `WB/PriceSlider` directory into `app/code/WB/PriceSlider/`.
2. Run:

```bash
php bin/magento module:enable WB_PriceSlider
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

The module ships with all settings **enabled by default** (`config.xml`), so the slider appears immediately after installation without any admin configuration.

---

## Configuration

**Stores → Configuration → 💻 WB → Price Slider**

Configuration is scoped to **Store View**, allowing different values per store.

| Field | Default | Description |
|---|---|---|
| **Enable Price Slider** | Yes | Show or hide the slider widget in category sidebars |
| **Sort Products by Stock Availability** | Yes | Primary sort: In Stock → Pre-order/Made-to-Order → Out of Stock |
| **Slider Step (display currency units)** | 1 | Minimum drag increment. Use `1` for whole-number currencies (PKR, JPY, etc.) |

> The **Sort by Stock** and **Slider Step** fields are hidden in the admin UI when the slider is disabled.

---

## How It Works

### Price range query

On each category page load, `Model/PriceRange.php` queries the price index directly using `MIN(final_price)` / `MAX(final_price)` aggregates — no product objects are loaded.

**Which table is queried:**

| `catalog/price/scope` value | Table used | Scope filter |
|---|---|---|
| `2` (BSS store-view scope) | `catalog_product_index_price_store` | `store_id = ?` |
| `0` or `1` (Magento default) | `catalog_product_index_price` | `website_id = ?` |

If `scope = 2` is configured but the BSS table does not exist (BSS not installed), the module transparently falls back to the standard table.

Both cases join the category product index:
- `catalog_category_product_index_store{N}` (Magento 2.3+ partitioned table)
- Falls back to `catalog_category_product_index` (Magento 2.2 legacy)
- Falls back to `catalog_category_product` (direct assignments only, no anchoring)

For **anchor categories**, descendant IDs are resolved via a `path LIKE '{path}/%'` query and all are included in the `category_id IN (…)` join condition.

### Price filtering

The slider submits `?price=<baseMin>-<baseMax>` — the exact format Magento's native layered navigation expects. No custom PHP filter intercepts the request; Magento (and BSS's `CatalogSearch\Layer\Filter\Price` preference under Elasticsearch) handles it natively.

### Currency conversion

```
displayPrice = basePrice × rate
basePrice    = displayPrice ÷ rate
```

`rate` is retrieved from `Magento\Directory\Model\Currency::getRate()`. When base and display currencies are identical (the typical per-store BSS setup), `rate = 1.0` and no conversion is performed.

Prices are rounded toward safety: `floor()` on the minimum, `ceil()` on the maximum, so edge-case products at the boundary are never excluded.

### Currency symbol

`Block/Slider.php::getCurrencySymbol()` loads the current currency's symbol. If the symbol is empty or identical to the currency code (as is the case for some currencies, e.g. PKR), it falls back to the currency code so something meaningful always displays.

### Stock sort observer

`Observer/SortProductsByStock.php` fires on `catalog_product_collection_load_before`, but only on `catalog_category_view` pages.

1. LEFT JOIN `cataloginventory_stock_status` on `product_id = e.entity_id AND stock_id = 1`.
2. Resolve `pre_order_status` `attribute_id` dynamically from `eav_attribute` (returns `0` when BSS PreOrder is absent).
3. **With BSS PreOrder** (`attribute_id > 0`): build a `CASE WHEN` expression using two correlated sub-selects against `catalog_product_entity_int` — one for the store-specific EAV value, one for the global (`store_id = 0`) fallback — then `COALESCE` them:

    | Condition | Priority |
    |---|---|
    | In stock AND `pre_order_status` = 0 (or absent) | 1 |
    | `pre_order_status` IN (1, 2) — pre-order or MTO | 2 |
    | Everything else (out of stock) | 3 |

4. **Without BSS PreOrder**: `stock_status DESC` — in-stock products first.

The sort is **prepended** before existing `ORDER BY` expressions, making it the primary sort on default category views and a tiebreaker when the user applies an explicit sort (price, name, etc.).

### JavaScript widget

`price-slider.js` is a RequireJS module initialised via `x-magento-init`. All configuration is read from `data-*` attributes on `.wb-price-slider-wrap` — the template contains no inline JavaScript.

Key behaviours:
- **Apply**: builds the new URL via `URLSearchParams`, always removes `?p=` to reset pagination, navigates.
- **Full-range detection**: if both handles are at the natural min/max, the `?price=` param is removed rather than submitted.
- **Clear link**: clicking it removes `?price=` and navigates.
- **Native filter link sync** (strategy A): click-intercept on `.filter-current .action.remove` and `.action.clear` — if the target URL has no `?price=`, resets slider handles and labels immediately before navigation.
- **AJAX theme sync** (strategy B): `popstate` listener resets or repositions the slider whenever the URL changes without a full page reload.

---

## File Structure

```
app/code/WB/PriceSlider/
├── Block/
│   └── Slider.php                     Block — price range, currency helpers, URL param parsing
├── Model/
│   └── PriceRange.php                 Direct price-index DB query with BSS + vanilla fallback
├── Observer/
│   └── SortProductsByStock.php        Stock-availability sort (3-tier with BSS PreOrder, binary without)
├── Plugin/
│   └── PriceFilterPlugin.php          Empty stub — former aroundApply plugin removed (caused double-filter)
├── etc/
│   ├── acl.xml                        ACL resource definition
│   ├── config.xml                     Default admin values (all enabled, step = 1)
│   ├── events.xml                     Observer registration (catalog_product_collection_load_before)
│   ├── module.xml                     Module declaration (version 1.1.0)
│   ├── adminhtml/
│   │   ├── di.xml
│   │   └── system.xml                 Admin config fields (💻 WB tab → Price Slider section)
│   └── frontend/
│       └── di.xml
├── view/frontend/
│   ├── layout/
│   │   └── catalog_category_view.xml  Adds block to sidebar.main + enqueues CSS
│   ├── requirejs-config.js
│   ├── templates/
│   │   └── slider.phtml               Pure HTML — data-* attributes, x-magento-init, no inline JS/CSS
│   └── web/
│       ├── css/
│       │   └── price-slider.css       All styles, prefixed .wb-ps- / .wb-price-slider-wrap
│       └── js/
│           └── price-slider.js        RequireJS widget — jQuery UI slider + URL management
├── composer.json
├── registration.php
└── README.md
```

---

## Upgrading from 1.0.x

Version 1.1.0 contains breaking changes from 1.0.x.

**What changed:**

| Area | Change |
|---|---|
| `PriceFilterPlugin` | Removed `aroundApply` — it caused double-filtering with BSS and targeted the wrong class under Elasticsearch. The file now exists as an empty stub. |
| `Block/Slider.php` | Constructor signature changed — `PriceRange` and `ResourceConnection` injected. Clear generated DI after upgrade. |
| CSS class names | `price-slider-container` → `wb-price-slider-wrap`, `#price-slider` → `#wb-price-slider`. Update any custom CSS overrides. |
| Price display | Currency symbol now shown alongside each price label. |

**Upgrade steps:**

```bash
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

---

## Customisation

### Change slider accent colour

Add to your theme's `_extend.less` (or any custom CSS file):

```css
.wb-ps-slider .ui-slider-range {
    background: #your-color;
}

.wb-ps-slider .ui-slider-handle {
    border-color: #your-color;
}

.wb-ps-slider .ui-slider-handle:hover,
.wb-ps-slider .ui-slider-handle:focus {
    border-color: #your-darker-color;
    box-shadow: 0 0 0 3px rgba(your-r, your-g, your-b, 0.22);
}
```

### Disable stock sort for a specific store view

**Stores → Configuration → 💻 WB → Price Slider → Sort Products by Stock Availability → No**

### Adjust slider step

For high-value currencies (e.g. PKR, IDR), keep **Slider Step = 1** to allow fine-grained selection.  
For currencies where rounding to 5 or 10 makes sense, set the step accordingly.

### Override the template

Copy `view/frontend/templates/slider.phtml` into your theme at:

```
app/design/frontend/<Vendor>/<Theme>/WB_PriceSlider/templates/slider.phtml
```

All data is available via `$block` methods — refer to `Block/Slider.php` for the full public API.

---

## License

MIT — free to use, modify, and distribute. See [LICENSE](LICENSE) for the full text.

---

## Author

**Wajahat Bashir**  
Email: wajahat449@gmail.com  
GitHub: [wajahatbashir](https://github.com/wajahatbashir)  
Repository: [wajahatbashir/PriceSlider](https://github.com/wajahatbashir/PriceSlider)
