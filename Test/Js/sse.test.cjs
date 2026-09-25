'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./support.cjs');

function collect() {
    const events = [];
    const parser = load('MageOS_AiShoppingAssistant/js/luma/model/sse').create((type, data) => events.push([type, data]));
    return {events, parser};
}

test('parses frames split across chunks', () => {
    const {events, parser} = collect();
    parser.push('event: text_delta\ndata: {"text":"Hel');
    parser.push('lo"}\n\nevent: turn_complete\ndata: {"usage":null}\n\n');
    assert.deepEqual(events, [['text_delta', {text: 'Hello'}], ['turn_complete', {usage: null}]]);
});

test('ignores comment frames', () => {
    const {events, parser} = collect();
    parser.push(': open\n\n: ping\n\nevent: progress\ndata: {"message":"Looking"}\n\n');
    assert.deepEqual(events, [['progress', {message: 'Looking'}]]);
});

test('waits for the blank line that ends a frame', () => {
    const {events, parser} = collect();
    parser.push('event: progress\ndata: {"message":"Looking"}\n');
    assert.deepEqual(events, []);
    parser.push('\n');
    assert.deepEqual(events, [['progress', {message: 'Looking'}]]);
});
