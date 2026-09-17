define([
    'ko',
    'Magento_Customer/js/customer-data',
    'MageOS_ClaudeConsumerAgent/js/luma/model/transport',
    'MageOS_ClaudeConsumerAgent/js/luma/model/card-view',
    'MageOS_ClaudeConsumerAgent/js/luma/model/page',
    'MageOS_ClaudeConsumerAgent/js/luma/model/format'
], function (ko, customerData, transport, cardView, pageModel, format) {
    'use strict';

    var SESSION_KEY = 'aiagent_session',
        MODE_KEY = 'aiagent_mode',
        SESSION_ID_LENGTH = 64,
        MAX_SUGGESTIONS = 4,
        resolveReady;

    function storageOf(name) {
        try {
            return window[name] || null;
        } catch (e) {
            return null;
        }
    }

    function readStorage(name, key) {
        var storage = storageOf(name);

        try {
            return storage ? storage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function writeStorage(name, key, value) {
        var storage = storageOf(name);

        try {
            if (storage) {
                storage.setItem(key, value);
            }
        } catch (e) {
            return;
        }
    }

    function generateSessionId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'aiagent-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    return {
        config: null,
        sessionId: null,
        mode: 'stream',
        page: null,
        started: false,
        startPromise: null,
        restoring: ko.observable(false),
        turnController: null,
        transcript: ko.observableArray([]),
        suggestions: ko.observableArray([]),
        announcement: ko.observable(''),
        activity: ko.observable(0),
        isOpen: ko.observable(false),
        cart: customerData.get('cart'),
        turn: {
            running: ko.observable(false),
            status: ko.observable(''),
            id: ko.observable(0)
        },
        ready: new Promise(function (resolve) {
            resolveReady = resolve;
        }),

        init: function (config) {
            if (this.config) {
                return;
            }
            this.config = config;
            format.setPriceFormat(config.priceFormat);
            this.mode = config.streaming === 'off' ? 'json' : readStorage('localStorage', MODE_KEY) || 'stream';
            this.sessionId = readStorage('sessionStorage', SESSION_KEY);
            this.page = pageModel.detect(config.page, document, window.location.search);
            resolveReady(this);
        },

        touch: function () {
            this.activity(this.activity() + 1);
        },

        pagePayload: function () {
            return pageModel.payload(this.page || {});
        },

        mergePage: function (page) {
            this.page = Object.assign({}, this.page, page);
        },

        createMessage: function (role, text) {
            var self = this,
                message = {
                    role: role,
                    text: ko.observable(text || ''),
                    cards: ko.observableArray([]),
                    streaming: ko.observable(false),
                    notice: ko.observable(''),
                    retryAfter: ko.observable(null),
                    sessionCap: ko.observable(false)
                };

            message.isTyping = ko.pureComputed(function () {
                return message.streaming() && message.text() === '';
            });
            message.isEmpty = ko.pureComputed(function () {
                return message.role === 'assistant'
                    && !message.streaming()
                    && message.text() === ''
                    && message.cards().length === 0
                    && message.notice() === '';
            });
            message.retry = function () {
                self.retry(message);
            };
            message.startNew = function () {
                self.reset().catch(function () {});
            };
            return message;
        },

        createCard: function (card) {
            return cardView.create(card, this.config, this.send.bind(this));
        },

        start: function () {
            var self = this,
                hadStoredId = !!this.sessionId;

            if (this.startPromise) {
                return this.startPromise;
            }
            this.restoring(hadStoredId);
            this.startPromise = transport.postJson(this.config.urls.start, {
                session: this.sessionId,
                page: this.pagePayload()
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                self.sessionId = data.session;
                self.persistSession();
                if (data.streaming) {
                    self.config.streaming = data.streaming;
                }
                if (data.first_byte_threshold) {
                    self.config.firstByteThreshold = data.first_byte_threshold;
                }
                return hadStoredId && !data.fresh ? self.restore() : null;
            }).then(function () {
                self.started = true;
                self.restoring(false);
            }, function (error) {
                self.startPromise = null;
                self.restoring(false);
                throw error;
            });
            return this.startPromise;
        },

        restore: function () {
            var self = this;

            return transport.postJson(this.config.urls.transcript, {session: this.sessionId}).then(function (response) {
                return response.json();
            }).then(function (data) {
                var suggestions = [];

                self.transcript((data.messages || []).map(function (raw) {
                    var message = self.createMessage(raw.role, raw.text);

                    (Array.isArray(raw.cards) ? raw.cards : []).forEach(function (card, index) {
                        var view;

                        if (card.component === 'suggestions') {
                            suggestions = ((card.payload || {}).suggestions || []).slice(0, MAX_SUGGESTIONS);
                            return;
                        }
                        view = self.createCard({component: card.component, payload: card.payload, id: card.id || index});
                        if (view) {
                            message.cards.push(view);
                        }
                    });
                    return message;
                }));
                self.suggestions(suggestions);
                self.touch();
            });
        },

        send: function (text) {
            var self = this;

            if (this.turn.running() || !text) {
                return;
            }
            if (!this.started) {
                this.turn.running(true);
                this.start().then(function () {
                    self.turn.running(false);
                    self.send(text);
                }, function () {
                    self.pushTurn(text);
                    self.apply('error', {message: self.config.i18n.interrupted});
                });
                return;
            }
            this.pushTurn(text);
            transport.run(this, text);
        },

        pushTurn: function (text) {
            var assistant = this.createMessage('assistant', '');

            this.suggestions([]);
            this.transcript.push(this.createMessage('user', text));
            assistant.streaming(true);
            this.transcript.push(assistant);
            this.turn.running(true);
            this.turn.status('');
            this.turn.id(this.turn.id() + 1);
            this.touch();
        },

        apply: function (type, data) {
            var messages = this.transcript(),
                last = messages[messages.length - 1],
                view;

            if (!last) {
                return;
            }
            switch (type) {
                case 'text_delta':
                    last.text(last.text() + (data.text || ''));
                    break;
                case 'tool_call':
                    this.turn.status(data.label || '');
                    break;
                case 'tool_result':
                    if (data.status !== 'ok') {
                        this.turn.status('');
                    }
                    break;
                case 'ui':
                    if (data.component === 'suggestions') {
                        this.suggestions((data.payload.suggestions || []).slice(0, MAX_SUGGESTIONS));
                        break;
                    }
                    view = this.createCard({component: data.component, payload: data.payload, id: data.stream_id});
                    if (view) {
                        last.cards.push(view);
                    }
                    break;
                case 'cart_update':
                    customerData.reload(['cart'], true);
                    break;
                case 'progress':
                    this.turn.status(data.message);
                    break;
                case 'error':
                    last.notice(data.message || this.config.i18n.interrupted);
                    last.retryAfter(data.retry_after || null);
                    last.sessionCap(data.kind === 'session_cap');
                    last.streaming(false);
                    this.turn.running(false);
                    this.turn.status('');
                    this.announcement(data.message || this.config.i18n.interrupted);
                    break;
                case 'turn_complete':
                    last.streaming(false);
                    this.turn.running(false);
                    this.turn.status('');
                    this.announcement(last.text());
                    if (typeof data.session === 'string'
                        && data.session.length === SESSION_ID_LENGTH
                        && data.session !== this.sessionId
                    ) {
                        this.sessionId = data.session;
                        this.persistSession();
                    }
                    break;
                default:
                    break;
            }
            this.touch();
        },

        retry: function (message) {
            var messages = this.transcript(),
                index = messages.indexOf(message);

            if (this.turn.running()) {
                return;
            }
            while (index >= 0 && messages[index].role !== 'user') {
                index--;
            }
            if (index < 0) {
                return;
            }
            message.notice('');
            message.retryAfter(null);
            this.send(messages[index].text());
        },

        setMode: function (mode) {
            this.mode = mode;
            writeStorage('localStorage', MODE_KEY, mode);
        },

        reset: function () {
            var previous = this.sessionId;

            if (this.turnController) {
                this.turnController.abort();
            }
            this.transcript([]);
            this.suggestions([]);
            this.sessionId = generateSessionId();
            this.persistSession();
            this.touch();
            return transport.postJson(this.config.urls.reset, {session: previous});
        },

        persistSession: function () {
            writeStorage('sessionStorage', SESSION_KEY, this.sessionId);
        }
    };
});
