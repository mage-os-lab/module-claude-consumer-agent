'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./support.cjs');

function loadFormat(calls) {
    return load('MageOS_AiShoppingAssistant/js/luma/model/format', {
        'Magento_Catalog/js/price-utils': {
            formatPriceLocale: function (amount, format) {
                calls.push([amount, format]);
                return format.pattern.replace('%s', amount.toFixed(2));
            }
        }
    });
}

test('str replaces numbered placeholders', () => {
    assert.equal(loadFormat([]).str('%1 item(s), %2', 3, '$10.00'), '3 item(s), $10.00');
});

test('str keeps a placeholder without an argument', () => {
    assert.equal(loadFormat([]).str('%1 and %2', 'a'), 'a and %2');
});

test('price formats through price-utils with the configured format', () => {
    const calls = [];
    const format = loadFormat(calls);
    format.setPriceFormat({pattern: '$%s'});
    assert.equal(format.price('49'), '$49.00');
    assert.deepEqual(calls, [[49, {pattern: '$%s'}]]);
});

test('price is empty for a missing amount', () => {
    const format = loadFormat([]);
    assert.equal(format.price(null), '');
    assert.equal(format.price(undefined), '');
    assert.equal(format.price(''), '');
});
