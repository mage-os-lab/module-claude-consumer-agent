define([], function () {
    'use strict';

    function empty(type) {
        return {type: type, productId: '', productName: '', categoryId: '', categoryName: '', query: ''};
    }

    function isOrderPage(classList) {
        return Array.prototype.some.call(classList, function (name) {
            return name.indexOf('sales-order-') === 0;
        });
    }

    function fromDom(doc, search) {
        var classList = doc.body.classList,
            formInput = doc.querySelector('#product_addtocart_form input[name="product"]'),
            productId = formInput && formInput.value ? formInput.value : '',
            page;

        if (productId || classList.contains('catalog-product-view')) {
            page = empty('product');
            page.productId = productId;
            return page;
        }
        if (classList.contains('catalogsearch-result-index')) {
            page = empty('search');
            page.query = new URLSearchParams(search).get('q') || '';
            return page;
        }
        if (classList.contains('checkout-cart-index')) {
            return empty('cart');
        }
        if (isOrderPage(classList)) {
            return empty('orders');
        }
        if (classList.contains('cms-index-index')) {
            return empty('home');
        }
        return empty('other');
    }

    return {
        detect: function (serverPage, doc, search) {
            var page;

            serverPage = serverPage || {};
            if (!serverPage.page_type || serverPage.page_type === 'other') {
                return fromDom(doc, search);
            }
            page = empty(serverPage.page_type);
            page.productId = serverPage.product_id || '';
            page.productName = serverPage.product_name || '';
            page.categoryId = serverPage.category_id || '';
            page.categoryName = serverPage.category_name || '';
            page.query = serverPage.query || '';
            return page;
        },

        payload: function (page) {
            return {
                page_type: page.type || 'other',
                product_id: page.productId || null,
                product_name: page.productName || null,
                category_id: page.categoryId || null,
                category_name: page.categoryName || null,
                query: page.query || null
            };
        }
    };
});
