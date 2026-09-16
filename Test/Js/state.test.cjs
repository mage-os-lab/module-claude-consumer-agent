'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load, createKo, flush} = require('./support.cjs');

const SESSION = 'a'.repeat(64);

function loadState(responses) {
    const ko = createKo();
    const calls = {reloads: [], runs: [], posts: []};
    const replies = Object.assign({'/aiagent/session/start': {session: SESSION, fresh: true}}, responses || {});
    const state = load('MageOS_ClaudeConsumerAgent/js/luma/model/state', {
        ko: ko,
        'Magento_Customer/js/customer-data': {
            get: () => ko.observable({}),
            reload: (names) => calls.reloads.push(names)
        },
        'MageOS_ClaudeConsumerAgent/js/luma/model/transport': {
            run: (current, text) => calls.runs.push(text),
            postJson: (url, body) => {
                calls.posts.push([url, body]);
                return Promise.resolve({json: () => Promise.resolve(replies[url] || {})});
            }
        },
        'MageOS_ClaudeConsumerAgent/js/luma/model/format': {
            setPriceFormat: () => undefined,
            price: (amount) => '$' + Number(amount).toFixed(2),
            str: (template) => template,
            stripTags: (html) => html
        }
    });
    state.config = {
        urls: {
            start: '/aiagent/session/start',
            transcript: '/aiagent/session/transcript',
            reset: '/aiagent/session/reset',
            turn: '/aiagent/turn/index'
        },
        cards: {products: {}},
        i18n: {products: 'Products', interrupted: 'Interrupted'}
    };
    state.page = {type: 'home'};
    return {state, calls};
}

function startedTurn(state) {
    state.started = true;
    state.send('hello');
    return state.transcript()[1];
}

test('text_delta appends to the streaming assistant message', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    assert.equal(assistant.isTyping(), true);
    state.apply('text_delta', {text: 'Hel'});
    state.apply('text_delta', {text: 'lo'});
    assert.equal(assistant.text(), 'Hello');
    assert.equal(assistant.isTyping(), false);
});

test('a text_delta without text appends nothing', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    state.apply('text_delta', {text: 'Hi'});
    state.apply('text_delta', {});
    assert.equal(assistant.text(), 'Hi');
});

test('suggestions replace the chips and keep at most four', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    state.apply('ui', {component: 'suggestions', payload: {suggestions: ['a', 'b', 'c', 'd', 'e']}});
    assert.deepEqual(state.suggestions(), ['a', 'b', 'c', 'd']);
    assert.equal(assistant.cards().length, 0);
});

test('a products card becomes a card view and unknown cards are ignored', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    state.apply('ui', {component: 'products', stream_id: 's1', payload: {items: [
        {product: {product_id: '1', title: 'Bag', price: 34, in_stock: true}, reason: ''}
    ]}});
    state.apply('ui', {component: 'mystery', payload: {}});
    assert.equal(assistant.cards().length, 1);
    assert.equal(assistant.cards()[0].template, 'MageOS_ClaudeConsumerAgent/luma/cards/products');
    assert.equal(assistant.cards()[0].items[0].priceText, '$34.00');
});

test('a cart update reloads the cart section', () => {
    const {state, calls} = loadState();
    startedTurn(state);
    state.apply('cart_update', {cart: {item_count: 1, subtotal: 49}});
    assert.deepEqual(calls.reloads, [['cart']]);
});

test('an error ends the turn and keeps retry details on the message', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    state.apply('error', {message: 'Busy', retry_after: 5, kind: 'session_cap'});
    assert.equal(assistant.notice(), 'Busy');
    assert.equal(assistant.retryAfter(), 5);
    assert.equal(assistant.sessionCap(), true);
    assert.equal(assistant.streaming(), false);
    assert.equal(state.turn.running(), false);
    assert.equal(state.announcement(), 'Busy');
});

test('an error without a message ends the turn with an empty notice', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    state.apply('error', {});
    assert.equal(assistant.notice(), '');
    assert.equal(assistant.retryAfter(), null);
    assert.equal(assistant.sessionCap(), false);
    assert.equal(assistant.streaming(), false);
    assert.equal(state.turn.running(), false);
    assert.equal(state.announcement(), '');
});

test('turn_complete ends the turn and adopts a 64 character session id', () => {
    const {state} = loadState();
    startedTurn(state);
    state.sessionId = 'old';
    state.apply('text_delta', {text: 'Done'});
    state.apply('turn_complete', {usage: null, session: SESSION});
    assert.equal(state.sessionId, SESSION);
    assert.equal(state.turn.running(), false);
    assert.equal(state.announcement(), 'Done');
});

test('send before the session started waits for start, then runs the turn', async () => {
    const {state, calls} = loadState();
    state.send('hello');
    assert.equal(state.transcript().length, 0);
    await flush();
    assert.deepEqual(calls.posts[0], ['/aiagent/session/start', {
        session: null,
        page: {page_type: 'home', product_id: null, product_name: null, category_id: null, category_name: null, query: null}
    }]);
    assert.equal(state.sessionId, SESSION);
    assert.equal(state.transcript().length, 2);
    assert.deepEqual(calls.runs, ['hello']);
});

test('start restores the transcript of a stored session', async () => {
    const {state} = loadState({
        '/aiagent/session/start': {session: SESSION, fresh: false},
        '/aiagent/session/transcript': {messages: [
            {role: 'user', text: 'show bags'},
            {role: 'assistant', text: 'Here you go', cards: [
                {component: 'suggestions', payload: {suggestions: ['Cheaper']}},
                {component: 'products', payload: {items: []}}
            ]}
        ]}
    });
    state.sessionId = SESSION;
    await state.start();
    assert.equal(state.started, true);
    assert.equal(state.transcript().length, 2);
    assert.equal(state.transcript()[1].cards().length, 1);
    assert.deepEqual(state.suggestions(), ['Cheaper']);
});

test('retry sends the closest earlier user message again', () => {
    const {state, calls} = loadState();
    const assistant = startedTurn(state);
    state.apply('error', {message: 'Busy', retry_after: 5});
    assistant.retry();
    assert.equal(assistant.notice(), '');
    assert.deepEqual(calls.runs, ['hello', 'hello']);
});
