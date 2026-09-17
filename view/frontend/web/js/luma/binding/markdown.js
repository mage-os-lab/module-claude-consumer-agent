define([
    'ko',
    'MageOS_ClaudeConsumerAgent/js/luma/model/markdown'
], function (ko, markdown) {
    'use strict';

    var FRAME_KEY = 'aiAgentMarkdownFrame',
        TEXT_KEY = 'aiAgentMarkdownText';

    function cancelFrame(element) {
        var frame = ko.utils.domData.get(element, FRAME_KEY);

        if (frame) {
            window.cancelAnimationFrame(frame);
            ko.utils.domData.set(element, FRAME_KEY, null);
        }
    }

    ko.bindingHandlers.aiAgentMarkdown = {
        init: function (element) {
            ko.utils.domNodeDisposal.addDisposeCallback(element, function () {
                cancelFrame(element);
            });
        },

        update: function (element, valueAccessor) {
            var value = valueAccessor(),
                text = ko.unwrap(value.text),
                streaming = ko.unwrap(value.streaming);

            ko.utils.domData.set(element, TEXT_KEY, text);
            if (!streaming) {
                cancelFrame(element);
                markdown.render(element, text);
                return;
            }
            if (ko.utils.domData.get(element, FRAME_KEY)) {
                return;
            }
            ko.utils.domData.set(element, FRAME_KEY, window.requestAnimationFrame(function () {
                ko.utils.domData.set(element, FRAME_KEY, null);
                markdown.render(element, ko.utils.domData.get(element, TEXT_KEY));
            }));
        }
    };
});
