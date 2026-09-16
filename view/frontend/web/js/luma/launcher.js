define([
    'MageOS_ClaudeConsumerAgent/js/luma/opener',
    'MageOS_ClaudeConsumerAgent/js/luma/model/state'
], function (opener, state) {
    'use strict';

    return function (config, element) {
        state.isOpen.subscribe(function (open) {
            element.hidden = open;
        });
        element.addEventListener('click', function () {
            opener.open({opener: element});
        });
    };
});
