'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const SCRIPT = path.resolve(__dirname, '../../view/frontend/web/js/aiagent.js');

function loadComponents(store) {
    const source = fs.readFileSync(SCRIPT, 'utf8');
    const window = {addEventListener: () => undefined};
    const Alpine = {store: () => store};
    return new Function(
        'window',
        'Alpine',
        'hyva',
        source + '\nreturn {initAiAgentTranscript: initAiAgentTranscript, initAiAgentDrawer: initAiAgentDrawer};'
    )(window, Alpine, {});
}

function bubble(message) {
    const component = loadComponents({transcript: [message]}).initAiAgentTranscript();
    component.m = message;
    return component;
}

test('a finished assistant message with no text, cards or notice shows no bubble', () => {
    assert.equal(bubble({role: 'assistant', text: '', cards: [], streaming: false}).isVisible(), false);
});

test('a streaming assistant message with no text yet keeps its bubble for the typing indicator', () => {
    assert.equal(bubble({role: 'assistant', text: '', cards: [], streaming: true}).isVisible(), true);
});

test('an assistant message with a notice keeps its bubble', () => {
    const message = {role: 'assistant', text: '', cards: [], streaming: false, notice: 'Try again', retryAfter: 5, error: true};
    assert.equal(bubble(message).isVisible(), true);
});

test('an assistant message with text or cards keeps its bubble', () => {
    assert.equal(bubble({role: 'assistant', text: 'Hello', cards: []}).isVisible(), true);
    assert.equal(bubble({role: 'assistant', text: '', cards: [{component: 'products', payload: {}, id: 'p1'}]}).isVisible(), true);
});

test('a user message keeps its bubble', () => {
    assert.equal(bubble({role: 'user', text: 'show me bags'}).isVisible(), true);
});

test('the cart view summary shows the latest assistant message that has text', () => {
    const drawer = loadComponents({transcript: [
        {role: 'user', text: 'show me bags'},
        {role: 'assistant', text: 'Here are some bags.', cards: [{component: 'products', payload: {}, id: 'p1'}]},
        {role: 'user', text: 'cheaper ones'},
        {role: 'assistant', text: '', cards: [], streaming: false}
    ]}).initAiAgentDrawer();

    assert.equal(drawer.lastLine(), 'Here are some bags.');
});
