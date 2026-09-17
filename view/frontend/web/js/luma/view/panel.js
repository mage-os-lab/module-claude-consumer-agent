define([
    'uiComponent',
    'ko',
    'jquery',
    'MageOS_ClaudeConsumerAgent/js/luma/model/state',
    'MageOS_ClaudeConsumerAgent/js/luma/model/format',
    'MageOS_ClaudeConsumerAgent/js/luma/binding/markdown'
], function (Component, ko, $, state, format) {
    'use strict';

    var MAX_INPUT_HEIGHT = 120,
        FOLLOW_THRESHOLD = 4;

    return Component.extend({
        defaults: {
            template: 'MageOS_ClaudeConsumerAgent/luma/panel'
        },

        initialize: function () {
            var self = this;

            this._super();
            this.state = state;
            this.settings = state.config;
            this.panelElement = null;
            this.transcriptElement = null;
            this.opener = null;
            this.scrollFrame = null;
            this.transcriptObserver = null;
            this.following = true;
            this.lastScrollTop = 0;
            this.text = ko.observable('');
            this.productChipsVisible = ko.observable(false);
            this.hasTranscript = ko.pureComputed(function () {
                return state.transcript().length > 0;
            });
            this.showStart = ko.pureComputed(function () {
                return state.transcript().length === 0;
            });
            this.hasSuggestions = ko.pureComputed(function () {
                return state.suggestions().length > 0;
            });
            this.placeholder = ko.pureComputed(function () {
                return self.hasTranscript() ? self.settings.i18n.placeholderReply : self.settings.i18n.placeholder;
            });
            this.cannotSend = ko.pureComputed(function () {
                return state.turn.running() || self.text().trim() === '';
            });
            this.cartLabel = ko.pureComputed(function () {
                var cart = state.cart() || {},
                    count = Number(cart.summary_count || 0);

                if (!count) {
                    return self.settings.i18n.cartEmpty;
                }
                return format.str(self.settings.i18n.cartItems, count, format.stripTags(cart.subtotal));
            });
            this.hintPrefix = (this.settings.showAiLabel ? this.settings.i18n.aiAssistant : '')
                + (this.settings.contact.label ? this.settings.i18n.needPerson : '');
            this.pick = function (text) {
                state.send(text);
            };
            state.activity.subscribe(this.scrollToEnd, this);
            state.turn.id.subscribe(this.followLatest, this);
            this.text.subscribe(this.fitInput, this);
            this.placeholder.subscribe(this.fitInput, this);
            $(document).on('keydown.aiAgentPanel', function (event) {
                if (event.key === 'Escape' && state.isOpen()) {
                    self.closePanel();
                }
            });
            $('[data-block="minicart"]').on('dropdowndialogopen.aiAgentPanel', function () {
                self.hidePanel();
            });
            $(document).on('click.aiAgentPanel', '[data-action="toggle-nav"]', function () {
                self.hidePanel();
            });
            return this;
        },

        afterPanelRender: function (element) {
            this.panelElement = element;
        },

        afterTranscriptRender: function (element) {
            var self = this;

            this.transcriptElement = element;
            $(element).off('scroll.aiAgentPanel').on('scroll.aiAgentPanel', function () {
                self.updateFollowing();
            });
            if (!window.ResizeObserver) {
                return;
            }
            if (!this.transcriptObserver) {
                this.transcriptObserver = new window.ResizeObserver(function () {
                    self.keepAtEnd();
                });
            }
            this.transcriptObserver.disconnect();
            this.transcriptObserver.observe(element);
            this.transcriptObserver.observe(element.querySelector('.ai-agent-transcript__content'));
        },

        afterInputRender: function (element) {
            if (state.isOpen()) {
                element.focus({preventScroll: true});
            }
        },

        openPanel: function (opener) {
            var self = this,
                scrollY = window.scrollY;

            this.opener = opener;
            this.following = true;
            this.productChipsVisible(state.page.type === 'product');
            state.isOpen(true);
            if (!state.started) {
                state.start().catch(function () {
                    return null;
                });
            }
            window.requestAnimationFrame(function () {
                var input = self.input();

                if (input) {
                    input.focus({preventScroll: true});
                }
                if (window.scrollY !== scrollY) {
                    window.scrollTo(0, scrollY);
                }
                self.scrollToEnd();
            });
        },

        closePanel: function () {
            state.isOpen(false);
            if (this.opener && typeof this.opener.focus === 'function') {
                this.opener.focus();
            }
        },

        hidePanel: function () {
            state.isOpen(false);
        },

        newConversation: function () {
            state.reset().catch(function () {});
        },

        onKeydown: function (data, event) {
            if (event.key !== 'Enter' || event.shiftKey) {
                return true;
            }
            this.submit();
            return false;
        },

        submit: function () {
            var value = this.text().trim();

            if (!value || state.turn.running()) {
                return false;
            }
            state.send(value);
            this.text('');
            this.fitInput();
            return false;
        },

        input: function () {
            return this.panelElement ? this.panelElement.querySelector('textarea') : null;
        },

        fitInput: function () {
            var input = this.input();

            if (!input) {
                return;
            }
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, MAX_INPUT_HEIGHT) + 'px';
        },

        followLatest: function () {
            this.following = true;
            this.scrollToEnd();
        },

        updateFollowing: function () {
            var element = this.transcriptElement,
                top = element.scrollTop;

            if (element.scrollHeight - top - element.clientHeight <= FOLLOW_THRESHOLD) {
                this.following = true;
            } else if (top < this.lastScrollTop) {
                this.following = false;
            }
            this.lastScrollTop = top;
        },

        keepAtEnd: function () {
            var element = this.transcriptElement;

            if (!element || !this.following) {
                return;
            }
            element.scrollTop = element.scrollHeight;
            this.lastScrollTop = element.scrollTop;
        },

        scrollToEnd: function () {
            var self = this;

            if (this.scrollFrame !== null) {
                return;
            }
            this.scrollFrame = window.requestAnimationFrame(function () {
                self.scrollFrame = null;
                self.keepAtEnd();
            });
        }
    });
});
