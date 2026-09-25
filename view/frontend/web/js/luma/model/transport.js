define([
    'jquery',
    'MageOS_AiShoppingAssistant/js/luma/model/sse',
    'mage/cookies'
], function ($, sse) {
    'use strict';

    var TURN_TIMEOUT_MS = 180000,
        BUFFERED_AFTER_MS = 2000;

    function formKey() {
        return $.mage.cookies.get('form_key') || '';
    }

    function postJson(url, body, accept) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Form-Key': formKey(),
                'Accept': accept || 'application/json'
            },
            body: JSON.stringify(body)
        });
    }

    function run(state, text) {
        var config = state.config,
            streamMode = state.mode === 'stream',
            controller = new AbortController(),
            startedAt = Date.now(),
            firstByte = false,
            firstByteMs = 0,
            chunks = 0,
            completed = false,
            intentionalAbort = false,
            timedOut = false,
            watchdog,
            abortTimer;

        function applyEvent(type, data) {
            if (type === 'turn_complete') {
                completed = true;
            }
            state.apply(type, data);
        }

        function finish() {
            clearTimeout(watchdog);
            clearTimeout(abortTimer);
            if (!completed && !intentionalAbort) {
                state.apply('turn_complete', {usage: null});
            }
            if (state.turnController === controller) {
                state.turnController = null;
            }
        }

        function readStream(response) {
            var reader = response.body.getReader(),
                decoder = new TextDecoder(),
                parser = sse.create(applyEvent);

            function pump() {
                return reader.read().then(function (result) {
                    if (result.done) {
                        return null;
                    }
                    if (!firstByte) {
                        firstByte = true;
                        clearTimeout(watchdog);
                        firstByteMs = Date.now() - startedAt;
                    }
                    chunks++;
                    parser.push(decoder.decode(result.value, {stream: true}));
                    return pump();
                });
            }

            return pump().then(function () {
                var buffered = chunks <= 2 && Date.now() - startedAt > BUFFERED_AFTER_MS,
                    slowFirstByte = firstByteMs > config.firstByteThreshold * 1000;

                if (buffered || slowFirstByte) {
                    state.setMode('json');
                }
            });
        }

        state.turnController = controller;
        watchdog = setTimeout(function () {
            if (!firstByte) {
                state.turn.status(config.i18n.stillWorking);
            }
        }, config.firstByteThreshold * 1000);
        abortTimer = setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, TURN_TIMEOUT_MS);

        return fetch(config.urls.turn, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Form-Key': formKey(),
                'Accept': streamMode ? 'text/event-stream' : 'application/json'
            },
            body: JSON.stringify({
                session: state.sessionId,
                message: text,
                page: state.pagePayload(),
                stream: streamMode ? 1 : 0
            }),
            signal: controller.signal
        }).then(function (response) {
            var contentType = response.headers.get('content-type') || '';

            if (response.status === 403) {
                applyEvent('error', {message: config.i18n.reloadPage});
                return null;
            }
            if (!streamMode || contentType.indexOf('application/json') !== -1) {
                return response.json().then(function (payload) {
                    (payload.events || []).forEach(function (event) {
                        applyEvent(event.type, event.data);
                    });
                });
            }
            if (contentType.indexOf('text/event-stream') === -1) {
                applyEvent('error', {message: config.i18n.interrupted});
                return null;
            }
            return readStream(response);
        }).catch(function () {
            if (controller.signal.aborted && !timedOut) {
                intentionalAbort = true;
                state.turn.running(false);
                state.turn.status('');
                return;
            }
            applyEvent('error', {message: timedOut ? config.i18n.timedOut : config.i18n.interrupted});
        }).then(finish);
    }

    return {
        postJson: postJson,
        run: run
    };
});
