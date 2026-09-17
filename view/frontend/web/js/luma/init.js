define([
    'MageOS_ClaudeConsumerAgent/js/luma/model/state',
    'MageOS_ClaudeConsumerAgent/js/luma/opener',
    'Magento_Ui/js/lib/knockout/bootstrap'
], function (state, opener) {
    'use strict';

    return function (config) {
        state.init(config);
        if (state.shouldReopen()) {
            opener.open({focus: false}).catch(function () {});
        }
    };
});
