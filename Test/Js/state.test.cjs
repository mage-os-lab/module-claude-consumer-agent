'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load, createKo, flush} = require('./support.cjs');

const SESSION = 'a'.repeat(64);

function loadState(responses) {
    const ko = createKo();
    const calls = {reloads: [], runs: [], posts: []};
    const replies = Object.assign({'/aiagent/session/start': {session: SESSION, fresh: true}}, responses || {});
    const transport = {
        run: (current, text) => calls.runs.push(text),
        postJson: (url, body) => {
            calls.posts.push([url, body]);
            return Promise.resolve({json: () => Promise.resolve(replies[url] || {})});
        }
    };
    const state = load('MageOS_AiShoppingAssistant/js/luma/model/state', {
        ko: ko,
        'Magento_Customer/js/customer-data': {
            get: () => ko.observable({}),
            reload: (names) => calls.reloads.push(names)
        },
        'MageOS_AiShoppingAssistant/js/luma/model/transport': transport,
        'MageOS_AiShoppingAssistant/js/luma/model/format': {
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
    return {state, calls, transport};
}

function fakeWindow(stored, wide) {
    const items = new Map(Object.entries(stored || {}));
    const calls = [];
    return {
        calls: calls,
        items: items,
        sessionStorage: {
            getItem: (key) => {
                calls.push(['get', key]);
                return items.has(key) ? items.get(key) : null;
            },
            setItem: (key, value) => {
                calls.push(['set', key, String(value)]);
                items.set(key, String(value));
            },
            removeItem: (key) => {
                calls.push(['remove', key]);
                items.delete(key);
            }
        },
        matchMedia: (query) => ({matches: wide && query === '(min-width: 768px)'})
    };
}

function withWindow(win, run) {
    global.window = win;
    try {
        return run();
    } finally {
        delete global.window;
    }
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

test('a streaming assistant message is not empty', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    assert.equal(assistant.isEmpty(), false);
});

test('an assistant message that finished without text, cards or notice is empty', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    state.apply('ui', {component: 'suggestions', payload: {suggestions: ['a']}});
    state.apply('turn_complete', {usage: null});
    assert.equal(assistant.isEmpty(), true);
});

test('a finished assistant message with a card or a notice is not empty', () => {
    const {state} = loadState();
    const withCard = startedTurn(state);
    state.apply('ui', {component: 'products', stream_id: 's1', payload: {items: [
        {product: {product_id: '1', title: 'Bag', price: 34, in_stock: true}, reason: ''}
    ]}});
    state.apply('turn_complete', {usage: null});
    assert.equal(withCard.isEmpty(), false);
    state.send('again');
    const withNotice = state.transcript()[3];
    state.apply('error', {message: 'Busy'});
    assert.equal(withNotice.isEmpty(), false);
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
    assert.equal(assistant.cards()[0].template, 'MageOS_AiShoppingAssistant/luma/cards/products');
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

test('an error without a message ends the turn with the interrupted notice', () => {
    const {state} = loadState();
    const assistant = startedTurn(state);
    state.apply('error', {});
    assert.equal(assistant.notice(), 'Interrupted');
    assert.equal(assistant.retryAfter(), null);
    assert.equal(assistant.sessionCap(), false);
    assert.equal(assistant.streaming(), false);
    assert.equal(state.turn.running(), false);
    assert.equal(state.announcement(), 'Interrupted');
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

test('a second message while the session starts is blocked', async () => {
    const {state, calls} = loadState();
    state.send('first');
    assert.equal(state.turn.running(), true);
    state.send('second');
    assert.equal(state.turn.running(), true);
    assert.equal(state.transcript().length, 0);
    await flush();
    assert.equal(calls.posts.length, 1);
    assert.deepEqual(calls.runs, ['first']);
    assert.equal(state.transcript().length, 2);
    assert.equal(state.transcript()[0].text(), 'first');
});

test('a message sent while a stored session starts follows the restored transcript', async () => {
    const {state, calls} = loadState({
        '/aiagent/session/start': {session: SESSION, fresh: false},
        '/aiagent/session/transcript': {messages: [
            {role: 'user', text: 'show bags'},
            {role: 'assistant', text: 'Here you go', cards: []}
        ]}
    });
    state.sessionId = SESSION;
    state.send('cheaper ones');
    await flush();
    assert.deepEqual(state.transcript().map((message) => [message.role, message.text()]), [
        ['user', 'show bags'],
        ['assistant', 'Here you go'],
        ['user', 'cheaper ones'],
        ['assistant', '']
    ]);
    assert.equal(state.transcript()[3].streaming(), true);
    assert.deepEqual(calls.runs, ['cheaper ones']);
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

test('restoring is true while the start of a stored session is pending', () => {
    const {state} = loadState();
    state.sessionId = SESSION;
    state.start();
    assert.equal(state.restoring(), true);
});

test('restoring stays false when no session id is stored', async () => {
    const {state} = loadState();
    const pending = state.start();
    assert.equal(state.restoring(), false);
    await pending;
    assert.equal(state.restoring(), false);
});

test('restoring turns false when the server starts a fresh session for the stored id', async () => {
    const {state} = loadState({'/aiagent/session/start': {session: SESSION, fresh: true}});
    state.sessionId = 'b'.repeat(64);
    await state.start();
    assert.equal(state.restoring(), false);
    assert.equal(state.transcript().length, 0);
});

test('restoring stays true until the stored transcript is restored', async () => {
    const {state, transport} = loadState();
    let releaseTranscript;
    transport.postJson = (url) => {
        if (url === '/aiagent/session/start') {
            return Promise.resolve({json: () => Promise.resolve({session: SESSION, fresh: false})});
        }
        return new Promise((resolve) => {
            releaseTranscript = () => resolve({json: () => Promise.resolve({messages: [
                {role: 'user', text: 'show bags'},
                {role: 'assistant', text: 'Here you go', cards: []}
            ]})});
        });
    };
    state.sessionId = SESSION;
    const pending = state.start();
    await flush();
    assert.equal(state.restoring(), true);
    releaseTranscript();
    await pending;
    assert.equal(state.restoring(), false);
    assert.equal(state.transcript().length, 2);
});

test('restoring turns false when the start of a stored session fails', async () => {
    const {state, transport} = loadState();
    transport.postJson = () => Promise.reject(new Error('offline'));
    state.sessionId = SESSION;
    await assert.rejects(state.start());
    assert.equal(state.restoring(), false);
    assert.equal(state.started, false);
});

test('a message sent while the reset request is pending still ends its turn', async () => {
    const {state, calls} = loadState();
    global.window = {crypto: {randomUUID: () => 'fresh-id'}};
    try {
        startedTurn(state);
        state.apply('turn_complete', {usage: null});
        state.sessionId = SESSION;
        const pending = state.reset();
        state.send('again');
        await pending;
        state.apply('turn_complete', {usage: null});
        assert.equal(state.turn.running(), false);
        assert.deepEqual(state.transcript().map((message) => message.text()), ['again', '']);
        assert.deepEqual(calls.posts[calls.posts.length - 1], ['/aiagent/session/reset', {session: SESSION}]);
        assert.equal(state.sessionId, 'fresh-id');
    } finally {
        delete global.window;
    }
});

test('starting over after a session cap ignores a failed reset request', async () => {
    const {state, transport} = loadState();
    const unhandled = [];
    const onUnhandled = (reason) => unhandled.push(reason);
    global.window = {crypto: {randomUUID: () => 'fresh-id'}};
    process.on('unhandledRejection', onUnhandled);
    try {
        const assistant = startedTurn(state);
        state.apply('error', {message: 'Limit reached', kind: 'session_cap'});
        transport.postJson = () => Promise.reject(new Error('offline'));
        assistant.startNew();
        await flush();
        assert.deepEqual(unhandled, []);
        assert.equal(state.transcript().length, 0);
        assert.equal(state.sessionId, 'fresh-id');
    } finally {
        process.off('unhandledRejection', onUnhandled);
        delete global.window;
    }
});

test('retry sends the closest earlier user message again', () => {
    const {state, calls} = loadState();
    const assistant = startedTurn(state);
    state.apply('error', {message: 'Busy', retry_after: 5});
    assistant.retry();
    assert.equal(assistant.notice(), '');
    assert.deepEqual(calls.runs, ['hello', 'hello']);
});

test('retry while a turn is running keeps the notice and sends nothing', () => {
    const {state, calls} = loadState();
    const assistant = startedTurn(state);
    state.apply('error', {message: 'Busy', retry_after: 5});
    state.send('something else');
    assistant.retry();
    assert.equal(assistant.notice(), 'Busy');
    assert.equal(assistant.retryAfter(), 5);
    assert.equal(state.transcript().length, 4);
    assert.deepEqual(calls.runs, ['hello', 'something else']);
});

test('opening the assistant stores the open flag for this tab', () => {
    const {state} = loadState();
    const win = fakeWindow({}, true);
    state.config.keepOpen = true;
    withWindow(win, () => state.rememberOpen());
    assert.equal(win.items.get('aiagent_open'), '1');
});

test('closing the assistant removes the open flag', () => {
    const {state} = loadState();
    const win = fakeWindow({aiagent_open: '1'}, true);
    state.config.keepOpen = true;
    withWindow(win, () => state.forgetOpen());
    assert.equal(win.items.has('aiagent_open'), false);
});

test('the assistant reopens when it was left open and the viewport is wider than a phone', () => {
    const {state} = loadState();
    state.config.keepOpen = true;
    assert.equal(withWindow(fakeWindow({aiagent_open: '1'}, true), () => state.shouldReopen()), true);
});

test('the assistant stays closed on a phone and keeps the open flag for a wider load', () => {
    const {state} = loadState();
    const win = fakeWindow({aiagent_open: '1'}, false);
    state.config.keepOpen = true;
    assert.equal(withWindow(win, () => state.shouldReopen()), false);
    assert.equal(win.items.get('aiagent_open'), '1');
});

test('the assistant stays closed when it was not left open', () => {
    const {state} = loadState();
    state.config.keepOpen = true;
    assert.equal(withWindow(fakeWindow({}, true), () => state.shouldReopen()), false);
});

test('with keep open switched off the open flag is never read or written', () => {
    const {state} = loadState();
    const win = fakeWindow({aiagent_open: '1'}, true);
    state.config.keepOpen = false;
    withWindow(win, () => {
        state.rememberOpen();
        state.forgetOpen();
        assert.equal(state.shouldReopen(), false);
    });
    assert.deepEqual(win.calls, []);
    assert.equal(win.items.get('aiagent_open'), '1');
});
