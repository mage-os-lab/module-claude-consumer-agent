'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./support.cjs');

const page = load('MageOS_AiShoppingAssistant/js/luma/model/page');

function fakeDocument(classNames, productInputValue) {
    const classList = classNames.slice();
    classList.contains = (name) => classNames.indexOf(name) !== -1;
    return {
        body: {classList: classList},
        querySelector: () => (productInputValue ? {value: productInputValue} : null)
    };
}

test('the server page context wins over the DOM', () => {
    assert.deepEqual(
        page.detect({page_type: 'category', category_id: '12', category_name: 'Tops'}, fakeDocument(['cms-index-index']), ''),
        {type: 'category', productId: '', productName: '', categoryId: '12', categoryName: 'Tops', query: ''}
    );
});

test('a product page falls back to the add to cart form', () => {
    const detected = page.detect({page_type: 'other'}, fakeDocument(['catalog-product-view'], '42'), '');
    assert.equal(detected.type, 'product');
    assert.equal(detected.productId, '42');
});

test('a search page reads the query string', () => {
    assert.equal(page.detect(null, fakeDocument(['catalogsearch-result-index']), '?q=bags').query, 'bags');
});

test('order pages match on the sales-order prefix', () => {
    assert.equal(page.detect({}, fakeDocument(['account', 'sales-order-history']), '').type, 'orders');
});

test('the payload uses snake case keys and null for empty values', () => {
    assert.deepEqual(
        page.payload({type: 'product', productId: '42', productName: '', categoryId: '', categoryName: '', query: ''}),
        {page_type: 'product', product_id: '42', product_name: null, category_id: null, category_name: null, query: null}
    );
});
