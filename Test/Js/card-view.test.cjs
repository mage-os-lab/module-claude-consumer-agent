'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./support.cjs');

const CONFIG = {
    cards: {products: {}},
    i18n: {
        products: 'Products',
        compare: 'Compare',
        priceDifference: 'Price difference: %1',
        fulfillment: 'Fulfillment: %1',
        orderStatus: {shipped: 'Shipped', unknown: 'Unknown'}
    }
};

function loadCardView() {
    return load('MageOS_AiShoppingAssistant/js/luma/model/card-view', {
        'MageOS_AiShoppingAssistant/js/luma/model/format': {
            price: (amount) => '$' + Number(amount).toFixed(2),
            str: (template, value) => template.replace('%1', value)
        }
    });
}

test('a products card lists items and its add action sends a message', () => {
    const sent = [];
    const view = loadCardView().create({component: 'products', id: 'c1', payload: {items: [
        {product: {product_id: '7', title: 'Bag', url: '/bag.html', image_url: '/bag.jpg', price: 34, in_stock: true}, reason: 'Roomy'}
    ]}}, CONFIG, (text) => sent.push(text));

    assert.equal(view.template, 'MageOS_AiShoppingAssistant/luma/cards/products');
    assert.equal(view.title, 'Products');
    assert.equal(view.layout, 'tiles');
    assert.equal(view.tiles, 'single');
    assert.equal(view.items[0].priceText, '$34.00');
    assert.equal(view.items[0].reason, 'Roomy');
    assert.equal(view.items[0].stock, 'in');
    assert.equal(view.items[0].action, 'add');
    view.items[0].runAction();
    assert.deepEqual(sent, ['Add Bag to my cart']);
});

test('the products card hides the parts switched off in the admin', () => {
    const config = Object.assign({}, CONFIG, {cards: {products: {price: false, reason: false, image: false}}});
    const view = loadCardView().create({component: 'products', payload: {items: [
        {product: {product_id: '7', title: 'Bag', price: 34}, reason: 'Roomy'}
    ]}}, config, () => undefined);

    assert.equal(view.items[0].priceText, '');
    assert.equal(view.items[0].reason, '');
    assert.equal(view.items[0].showImage, false);
});

test('an unknown order status falls back to unknown', () => {
    const view = loadCardView().create({component: 'order_status', payload: {summary: 'On its way', order: {status: 'lost', items: []}}}, CONFIG, () => undefined);

    assert.equal(view.template, 'MageOS_AiShoppingAssistant/luma/cards/order-status');
    assert.equal(view.status, 'unknown');
    assert.equal(view.statusLabel, 'Unknown');

    const inherited = loadCardView().create({component: 'order_status', payload: {summary: 'On its way', order: {status: 'constructor', items: []}}}, CONFIG, () => undefined);

    assert.equal(inherited.status, 'unknown');
    assert.equal(inherited.statusLabel, 'Unknown');
});

test('a checkout card without a web checkout link produces no view', () => {
    const view = loadCardView().create({component: 'checkout', payload: {
        checkout_url: 'javascript:alert(1)',
        cart: {subtotal: 49, items: [{title: 'Jacket', quantity: 1, line_total: 49}]}
    }}, CONFIG, () => undefined);

    assert.equal(view, null);
});

test('a checkout line keeps only a web image link', () => {
    const view = loadCardView().create({component: 'checkout', payload: {
        checkout_url: '/checkout/',
        cart: {subtotal: 98, items: [
            {title: 'Jacket', quantity: 1, line_total: 49, image_url: 'javascript:alert(1)'},
            {title: 'Scarf', quantity: 1, line_total: 49, image_url: '/scarf.jpg'}
        ]}
    }}, CONFIG, () => undefined);

    assert.deepEqual(view.lines.map((line) => line.imageUrl), ['', '/scarf.jpg']);
    assert.equal(view.checkoutUrl, '/checkout/');
});

test('an order status card keeps only a web tracking link', () => {
    const cardView = loadCardView();
    const unsafe = cardView.create({component: 'order_status', payload: {summary: 'On its way', order: {status: 'shipped', items: [], tracking_url: 'javascript:alert(1)'}}}, CONFIG, () => undefined);
    const safe = cardView.create({component: 'order_status', payload: {summary: 'On its way', order: {status: 'shipped', items: [], tracking_url: 'https://track.example/1'}}}, CONFIG, () => undefined);

    assert.equal(unsafe.trackingUrl, '');
    assert.equal(safe.trackingUrl, 'https://track.example/1');
});

test('a products item keeps only web links for the product and its image', () => {
    const view = loadCardView().create({component: 'products', payload: {items: [
        {product: {product_id: '7', title: 'Bag', url: 'javascript:alert(1)', image_url: 'data:image/svg+xml,<svg/>', price: 34}},
        {product: {product_id: '8', title: 'Tote', url: '/tote.html', image_url: 'https://cdn.example/tote.jpg', price: 20}}
    ]}}, CONFIG, () => undefined);

    assert.deepEqual(view.items.map((item) => [item.url, item.imageUrl]), [['', ''], ['/tote.html', 'https://cdn.example/tote.jpg']]);
});

test('a comparison marks the recommended entry and formats the price delta', () => {
    const view = loadCardView().create({component: 'comparison', payload: {
        recommended_product_id: '2',
        price_delta: {amount: 15},
        entries: [
            {product_id: '1', product: {title: 'A', price: 10}},
            {product_id: '2', product: {title: 'B', price: 25}, pros: ['Light']}
        ]
    }}, CONFIG, () => undefined);

    assert.deepEqual(view.entries.map((entry) => entry.recommended), [false, true]);
    assert.equal(view.priceDeltaLine, 'Price difference: $15.00');
});

test('a checkout card formats line totals and the subtotal', () => {
    const view = loadCardView().create({component: 'checkout', payload: {
        checkout_url: '/checkout/',
        fulfillment_method: 'Flat rate',
        cart: {subtotal: 49, items: [{title: 'Jacket', quantity: 1, line_total: 49}]}
    }}, CONFIG, () => undefined);

    assert.equal(view.lines[0].totalText, '$49.00');
    assert.equal(view.subtotalText, '$49.00');
    assert.equal(view.fulfillmentLine, 'Fulfillment: Flat rate');
});

test('cards restored without the data their view needs produce no view', () => {
    const cardView = loadCardView();
    assert.equal(cardView.create({component: 'order_status', id: 'toolu_1', payload: {order_id: '100', summary: 'On its way', next_step: 'Wait'}}, CONFIG, () => undefined), null);
    assert.equal(cardView.create({component: 'checkout', id: 'toolu_2', payload: {note: 'Check the size', fulfillment_method: 'delivery'}}, CONFIG, () => undefined), null);
    assert.equal(cardView.create({component: 'products', id: 'toolu_3', payload: {layout: 'carousel'}}, CONFIG, () => undefined), null);
    assert.equal(cardView.create({component: 'comparison', id: 'toolu_4', payload: {title: 'Bags'}}, CONFIG, () => undefined), null);
});

test('unknown components and cards without a payload produce no view', () => {
    const cardView = loadCardView();
    assert.equal(cardView.create({component: 'mystery', payload: {}}, CONFIG, () => undefined), null);
    assert.equal(cardView.create({component: 'products'}, CONFIG, () => undefined), null);
});
