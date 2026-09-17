'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load, fakeDocument} = require('./support.cjs');

function serialize(node) {
    if (Object.prototype.hasOwnProperty.call(node, 'nodeValue')) {
        return JSON.stringify(node.nodeValue);
    }
    return node.tagName + '(' + node.childNodes.map(serialize).join(' ') + ')';
}

function render(text) {
    const container = fakeDocument().createElement('div');
    load('MageOS_ClaudeConsumerAgent/js/luma/model/markdown').render(container, text);
    return container.childNodes.map(serialize).join(' ');
}

test('blank lines split paragraphs and single newlines become line breaks', () => {
    assert.equal(render('One\ntwo\n\nThree'), 'P("One" BR() "two") P("Three")');
});

test('double asterisks become strong text', () => {
    assert.equal(render('A **bold** move'), 'P("A " STRONG("bold") " move")');
});

test('dash and numbered lines become lists', () => {
    assert.equal(render('- red\n- blue\n1. first'), 'UL(LI("red") LI("blue")) OL(LI("first"))');
});

test('markup in the reply stays text', () => {
    assert.equal(render('<img src=x onerror=alert(1)>'), 'P("<img src=x onerror=alert(1)>")');
});

test('rendering again replaces the previous content', () => {
    const container = fakeDocument().createElement('div');
    const markdown = load('MageOS_ClaudeConsumerAgent/js/luma/model/markdown');
    markdown.render(container, 'First');
    markdown.render(container, 'Second');
    assert.equal(container.childNodes.map(serialize).join(' '), 'P("Second")');
});
