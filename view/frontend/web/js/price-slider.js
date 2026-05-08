/**
 * WB Price Slider — frontend widget
 *
 * All config is read from data-* attributes on .wb-price-slider-wrap so the
 * template stays free of inline JS.
 *
 * Filtering strategy
 * ------------------
 * Submits ?price=<baseMin>-<baseMax> in the store's BASE currency — the exact
 * format Magento's native layer-filter (and BSS MultiStoreViewPricing's
 * CatalogSearch\Layer\Filter\Price preference) already expects.
 *
 * Bug fixes in this version
 * -------------------------
 * 1. Applying a new price range always resets page to 1 (removes ?p= param)
 *    so you never land on a non-existent page with the new filter.
 *
 * 2. Slider syncs when Magento's native "Clear All" / "Remove This Item" link
 *    is clicked.  Covered by two mechanisms:
 *      a) Click intercept on those links: reset slider to full range immediately
 *         before navigation (instant visual feedback, no flicker).
 *      b) popstate listener: handles themes that use pushState / AJAX layer
 *         navigation where the page is not fully reloaded.
 */
define(['jquery', 'jquery/ui'], function ($) {
    'use strict';

    /**
     * Format a number with thousand separators, no decimal places.
     *
     * @param {number} n
     * @returns {string}
     */
    function fmt(n) {
        return Math.round(n).toLocaleString();
    }

    /**
     * Build a new URL from the current one, modifying/removing one param.
     * Also always removes ?p= (pagination) so applying a filter starts at page 1.
     *
     * @param {string}      key
     * @param {string|null} value  — null removes the param
     * @returns {string}
     */
    function buildFilterUrl(key, value) {
        var url = new URL(window.location.href);

        if (value === null || value === undefined) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }

        // Always start at page 1 when changing the price filter
        url.searchParams.delete('p');

        return url.toString();
    }

    /**
     * Read the ?price= value from the given URL string (or current window URL).
     *
     * @param {string} [href]
     * @returns {string|null}
     */
    function getPriceParam(href) {
        try {
            return new URL(href || window.location.href).searchParams.get('price');
        } catch (e) {
            return null;
        }
    }

    /**
     * Boot the slider for a single .wb-price-slider-wrap element.
     *
     * @param {jQuery} $wrap
     */
    function initSlider($wrap) {
        var dispMin    = parseFloat($wrap.data('display-min'));
        var dispMax    = parseFloat($wrap.data('display-max'));
        var selMin     = parseFloat($wrap.data('selected-min'));
        var selMax     = parseFloat($wrap.data('selected-max'));
        var rate       = parseFloat($wrap.data('currency-rate')) || 1;
        var step       = parseInt($wrap.data('step'), 10) || 1;

        var $track     = $wrap.find('#wb-price-slider');
        var $minVal    = $wrap.find('.wb-ps-min-val');
        var $maxVal    = $wrap.find('.wb-ps-max-val');
        var $applyBtn  = $wrap.find('.wb-ps-apply');
        var $clearLink = $wrap.find('.wb-ps-clear-link');

        // Mutable slider state (in DISPLAY currency)
        var currentMin = selMin;
        var currentMax = selMax;

        /**
         * Convert a display-currency value to base currency for the URL param.
         * rate = displayUnits / baseUnit  →  base = display / rate
         */
        function toBase(displayVal) {
            return rate === 1 ? displayVal : displayVal / rate;
        }

        /**
         * Build the ?price= value.
         * Floor min so low-boundary products are included; ceil max likewise.
         */
        function buildPriceParam(dMin, dMax) {
            return Math.floor(toBase(dMin)) + '-' + Math.ceil(toBase(dMax));
        }

        /**
         * True when the handles sit at the natural full-range positions.
         * Submitting a full-range filter is the same as having no filter.
         */
        function isFullRange(dMin, dMax) {
            return dMin <= dispMin && dMax >= dispMax;
        }

        /**
         * Reset slider handles and labels to full category range.
         * Called when the price filter is cleared externally.
         */
        function resetToFullRange() {
            currentMin = dispMin;
            currentMax = dispMax;
            $track.slider('values', [dispMin, dispMax]);
            $minVal.text(fmt(dispMin));
            $maxVal.text(fmt(dispMax));
            $clearLink.hide();
        }

        // --- jQuery UI slider ---
        $track.slider({
            range : true,
            min   : dispMin,
            max   : dispMax,
            step  : step,
            values: [currentMin, currentMax],
            slide : function (event, ui) {
                currentMin = ui.values[0];
                currentMax = ui.values[1];
                $minVal.text(fmt(currentMin));
                $maxVal.text(fmt(currentMax));
            }
        });

        // --- Apply ---
        // Always resets ?p= so the new filter starts on page 1.
        $applyBtn.on('click', function () {
            var newUrl = isFullRange(currentMin, currentMax)
                ? buildFilterUrl('price', null)
                : buildFilterUrl('price', buildPriceParam(currentMin, currentMax));
            window.location.href = newUrl;
        });

        // --- Slider clear link ---
        $clearLink.on('click', function (e) {
            e.preventDefault();
            window.location.href = buildFilterUrl('price', null);
        });

        // ---------------------------------------------------------------
        // Bug fix 2: sync slider when Magento's native filter links clear
        // the price filter without a full page reload.
        //
        // Strategy A — intercept clicks on Magento's "Remove This Item"
        // and "Clear All" filter links.  If the target URL has no ?price=,
        // reset the slider immediately (before navigation completes) so the
        // user sees instant feedback.
        // ---------------------------------------------------------------
        $(document).on('click', '.filter-current .action.remove, .action.clear', function () {
            var href = $(this).attr('href') || '';
            if (!getPriceParam(href)) {
                // The clicked link will clear the price filter — reset slider now
                resetToFullRange();
            }
        });

        // ---------------------------------------------------------------
        // Strategy B — popstate: fires when the URL changes via pushState
        // (AJAX-based layered navigation themes).  If the new URL has no
        // ?price= param, reset the slider to reflect the cleared state.
        // ---------------------------------------------------------------
        window.addEventListener('popstate', function () {
            if (!getPriceParam()) {
                resetToFullRange();
            } else {
                // Price param present — parse it and move slider to match
                var parts = getPriceParam().split('-');
                if (parts.length === 2) {
                    var newDispMin = Math.round(parseFloat(parts[0]) * rate);
                    var newDispMax = Math.round(parseFloat(parts[1]) * rate);
                    currentMin = newDispMin;
                    currentMax = newDispMax;
                    $track.slider('values', [newDispMin, newDispMax]);
                    $minVal.text(fmt(newDispMin));
                    $maxVal.text(fmt(newDispMax));
                    $clearLink.show();
                }
            }
        });
    }

    /**
     * Entry point called by x-magento-init for each matched element.
     * config is unused — all settings come from data-* attributes.
     *
     * @param {HTMLElement} el
     */
    return function (config, el) {
        $(function () {
            initSlider($(el));
        });
    };
});
