define([
    'Magento_Catalog/js/price-utils'
], function (priceUtils) {
    'use strict';

    var priceFormat = {};

    return {
        setPriceFormat: function (format) {
            priceFormat = format || {};
        },

        price: function (amount) {
            if (amount === null || amount === undefined || amount === '') {
                return '';
            }
            return priceUtils.formatPriceLocale(Number(amount), priceFormat);
        },

        str: function (template) {
            var args = Array.prototype.slice.call(arguments, 1);

            return String(template).replace(/%(\d+)/g, function (match, position) {
                var value = args[Number(position) - 1];

                return value === undefined ? match : String(value);
            });
        },

        stripTags: function (html) {
            return new DOMParser().parseFromString(String(html || ''), 'text/html').body.textContent || '';
        }
    };
});
