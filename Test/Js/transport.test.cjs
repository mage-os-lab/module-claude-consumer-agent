'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./support.cjs');

const I18N = {stillWorking: 'Still working', reloadPage: 'Reload', interrupted: 'Interrupted', timedOut: 'Timed out'};

function loadTransport() {
    return load('MageOS_AiShoppingAssistant/js/luma/model/transport', {
        jquery: {mage: {cookies: {get: () => 'form-key-1'}}},
        'mage/cookies': {}
    });
}

function stateStub(mode) {
    const events = [];
    return {
        events: events,
        config: {firstByteThreshold: 4, urls: {turn: '/aiagent/turn/index'}, i18n: I18N},
        sessionId: 'session-1',
        mode: mode,
        turnController: null,
        turn: {
            running: (value) => events.push(['running', value]),
            status: (value) => events.push(['status', value])
        },
        pagePayload: () => ({page_type: 'home'}),
        apply: (type, data) => events.push([type, data]),
        setMode: (value) => events.push(['mode', value])
    };
}

async function withFetch(handler, run) {
    const original = global.fetch;
    global.fetch = handler;
    try {
        await run();
    } finally {
        global.fetch = original;
    }
}

test('posts the turn with the form key and applies streamed events once', async () => {
    const requests = [];
    const state = stateStub('stream');
    const body = 'event: text_delta\ndata: {"text":"Hi"}\n\nevent: turn_complete\ndata: {"usage":null}\n\n';

    await withFetch(async (url, init) => {
        requests.push([url, init]);
        return new Response(body, {headers: {'content-type': 'text/event-stream'}});
    }, () => loadTransport().run(state, 'hello'));

    assert.equal(requests[0][0], '/aiagent/turn/index');
    assert.equal(requests[0][1].headers['X-Form-Key'], 'form-key-1');
    assert.equal(requests[0][1].headers.Accept, 'text/event-stream');
    assert.deepEqual(JSON.parse(requests[0][1].body), {session: 'session-1', message: 'hello', page: {page_type: 'home'}, stream: 1});
    assert.deepEqual(state.events, [['text_delta', {text: 'Hi'}], ['turn_complete', {usage: null}]]);
});

test('asks for a reload when the form key is rejected', async () => {
    const state = stateStub('stream');
    await withFetch(async () => new Response('{}', {status: 403, headers: {'content-type': 'application/json'}}), () => loadTransport().run(state, 'hello'));
    assert.deepEqual(state.events, [['error', {message: 'Reload'}], ['turn_complete', {usage: null}]]);
});

test('applies the events of a JSON reply in json mode', async () => {
    const state = stateStub('json');
    let accept = '';
    await withFetch(async (url, init) => {
        accept = init.headers.Accept;
        return new Response(
            JSON.stringify({events: [{type: 'text_delta', data: {text: 'A'}}, {type: 'turn_complete', data: {usage: null}}]}),
            {headers: {'content-type': 'application/json'}}
        );
    }, () => loadTransport().run(state, 'hello'));
    assert.equal(accept, 'application/json');
    assert.deepEqual(state.events, [['text_delta', {text: 'A'}], ['turn_complete', {usage: null}]]);
});

test('reports an interrupted turn when a stream request gets HTML back', async () => {
    const state = stateStub('stream');
    await withFetch(async () => new Response('<html></html>', {headers: {'content-type': 'text/html'}}), () => loadTransport().run(state, 'hello'));
    assert.deepEqual(state.events, [['error', {message: 'Interrupted'}], ['turn_complete', {usage: null}]]);
});

test('reports an interrupted turn when the request fails', async () => {
    const state = stateStub('stream');
    await withFetch(async () => {
        throw new TypeError('network');
    }, () => loadTransport().run(state, 'hello'));
    assert.deepEqual(state.events, [['error', {message: 'Interrupted'}], ['turn_complete', {usage: null}]]);
});
