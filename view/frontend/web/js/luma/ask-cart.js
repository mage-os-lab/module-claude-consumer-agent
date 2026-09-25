define([
    'MageOS_AiShoppingAssistant/js/luma/opener',
    'MageOS_AiShoppingAssistant/js/luma/model/state'
], function (opener, state) {
    'use strict';

    return function (config, form) {
        var input = form.querySelector('#ai-agent-cart-ask-input');

        form.addEventListener('submit', function (event) {
            var text = input.value.trim();

            event.preventDefault();
            opener.open({opener: input, page: {type: 'cart'}}).then(function () {
                if (!text || state.turn.running()) {
                    return;
                }
                input.value = '';
                state.send(text);
            }, function () {});
        });
    };
});
