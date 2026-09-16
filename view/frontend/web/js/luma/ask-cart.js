define([
    'MageOS_ClaudeConsumerAgent/js/luma/opener',
    'MageOS_ClaudeConsumerAgent/js/luma/model/state'
], function (opener, state) {
    'use strict';

    return function (config, form) {
        var input = form.querySelector('#ai-agent-cart-ask-input');

        form.addEventListener('submit', function (event) {
            var text = input.value.trim(),
                page = {type: 'cart'};

            event.preventDefault();
            if (!text || state.turn.running()) {
                opener.open({opener: input, page: page});
                return;
            }
            input.value = '';
            opener.open({opener: input, page: page}).then(function () {
                state.send(text);
            });
        });
    };
});
