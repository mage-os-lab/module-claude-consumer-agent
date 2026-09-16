'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./support.cjs');

const cards = load('MageOS_ClaudeConsumerAgent/js/luma/model/cards');

test('option values join with a slash', () => {
    assert.equal(cards.optionValuesText({option_values: {Size: 'M', Color: 'Black'}}), 'M / Black');
    assert.equal(cards.optionValuesText({option_values: null}), '');
});

test('custom option lines show three required options with four values each', () => {
    const values = ['A', 'B', 'C', 'D', 'E'].map((title) => ({title}));
    const options = [1, 2, 3, 4].map((n) => ({title: 'Option ' + n, required: true, values}));
    options.push({title: 'Optional', required: false, values});
    assert.deepEqual(cards.customOptionLines({custom_options: options}), [
        'Option 1: A, B, C, D +1 more',
        'Option 2: A, B, C, D +1 more',
        'Option 3: A, B, C, D +1 more',
        '+1 more options'
    ]);
});

test('the card action follows the product type', () => {
    assert.equal(cards.action({needs_page_choices: true, custom_options: [{}]}, {}), 'view');
    assert.equal(cards.action({custom_options: [{title: 'Engraving'}]}, {}), 'custom_options');
    assert.equal(cards.action({options: {Size: ['S', 'M']}}, {}), 'choose_options');
    assert.equal(cards.action({options: {}}, {}), 'add');
    assert.equal(cards.action({}, {addToCart: false}), '');
});

test('action messages name the product and its option values', () => {
    assert.equal(cards.actionMessage({title: 'Bag'}, 'add'), 'Add Bag to my cart');
    assert.equal(cards.actionMessage({title: 'Tee', option_values: {Size: 'M'}}, 'add'), 'Add Tee in M to my cart');
    assert.equal(cards.actionMessage({title: 'Tee'}, 'choose_options'), 'Which options are available for Tee?');
    assert.equal(cards.actionMessage({title: 'Mug'}, 'custom_options'), 'Which options does Mug have?');
    assert.equal(cards.actionMessage({title: 'Mug'}, 'view'), '');
});

test('more than four items or a list layout render as a list', () => {
    assert.equal(cards.isListLayout({items: [1, 2, 3, 4, 5]}), true);
    assert.equal(cards.isListLayout({items: [1], layout: 'list'}), true);
    assert.equal(cards.isListLayout({items: [1, 2], layout: 'grid'}), false);
});

test('tiles use one column for one item, otherwise grid or carousel', () => {
    assert.equal(cards.tilesModifier({items: [1]}), 'single');
    assert.equal(cards.tilesModifier({items: [1, 2], layout: 'carousel'}), 'carousel');
    assert.equal(cards.tilesModifier({items: [1, 2]}), 'grid');
});

test('only web links and site paths pass as safe urls', () => {
    assert.equal(cards.safeUrl('https://shop.example/bag.html'), 'https://shop.example/bag.html');
    assert.equal(cards.safeUrl('http://shop.example/bag.html'), 'http://shop.example/bag.html');
    assert.equal(cards.safeUrl('/checkout/'), '/checkout/');
    assert.equal(cards.safeUrl('javascript:alert(1)'), '');
    assert.equal(cards.safeUrl('JAVASCRIPT:alert(1)'), '');
    assert.equal(cards.safeUrl('data:text/html,<script>alert(1)</script>'), '');
    assert.equal(cards.safeUrl('//evil.example/checkout/'), '');
    assert.equal(cards.safeUrl('/\\evil.example'), '');
    assert.equal(cards.safeUrl(' javascript:alert(1)'), '');
    assert.equal(cards.safeUrl(''), '');
    assert.equal(cards.safeUrl(null), '');
    assert.equal(cards.safeUrl(undefined), '');
});

test('stock is hidden when the card hides it', () => {
    assert.equal(cards.stock({in_stock: true}, {}), 'in');
    assert.equal(cards.stock({in_stock: false}, {}), 'out');
    assert.equal(cards.stock({in_stock: true}, {stock: false}), '');
});
