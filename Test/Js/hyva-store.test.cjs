'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const TEMPLATE = path.resolve(__dirname, '../../view/frontend/templates/js/store.phtml');

function loadStore(responses) {
    const source = fs.readFileSync(TEMPLATE, 'utf8');
    const script = source
        .slice(source.indexOf('<script>') + '<script>'.length, source.indexOf('</script>'))
        .replace(/<\?=[^?]*\?>/, '{}');
    const handlers = {};
    let store = null;
    const window = {
        addEventListener: (name, handler) => {
            handlers[name] = handler;
        },
        dispatchEvent: () => true
    };
    const Alpine = {
        store: (name, value) => {
            store = value;
        }
    };
    const hyva = {getFormKey: () => 'key'};
    const fetch = (url) => Promise.resolve({json: () => Promise.resolve(responses[url] || {})});
    const CustomEvent = function (name) {
        this.type = name;
    };
    new Function('window', 'Alpine', 'hyva', 'fetch', 'CustomEvent', script)(window, Alpine, hyva, fetch, CustomEvent);
    handlers['alpine:init']();
    store.config = {urls: {transcript: '/aiagent/session/transcript'}};
    store.sessionId = 'a'.repeat(64);
    return store;
}

test('restore drops an assistant message left empty after its suggestions move to the chips', async () => {
    const store = loadStore({
        '/aiagent/session/transcript': {messages: [
            {role: 'user', text: 'show me bags'},
            {role: 'assistant', text: 'Here are some bags.', cards: [{component: 'products', payload: {items: []}, id: 'p1'}]},
            {role: 'assistant', text: '', cards: [{component: 'suggestions', payload: {suggestions: ['Backpacks', 'Duffles']}, id: 's1'}]}
        ]}
    });

    await store.restore();

    assert.deepEqual(store.transcript.map((message) => message.role), ['user', 'assistant']);
    assert.equal(store.transcript[1].text, 'Here are some bags.');
    assert.deepEqual(store.transcript[1].cards.map((card) => card.component), ['products']);
    assert.deepEqual(store.suggestions, ['Backpacks', 'Duffles']);
});

test('restore keeps an assistant message whose text shares a row with the suggestions', async () => {
    const store = loadStore({
        '/aiagent/session/transcript': {messages: [
            {role: 'user', text: 'jackets for rain'},
            {role: 'assistant', text: 'Men or women?', cards: [{component: 'suggestions', payload: {suggestions: ['Men', 'Women']}, id: 's1'}]}
        ]}
    });

    await store.restore();

    assert.deepEqual(store.transcript, [
        {role: 'user', text: 'jackets for rain'},
        {role: 'assistant', text: 'Men or women?', cards: []}
    ]);
    assert.deepEqual(store.suggestions, ['Men', 'Women']);
});
