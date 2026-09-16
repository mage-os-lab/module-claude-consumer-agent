define([
    'uiLayout',
    'uiRegistry',
    'MageOS_ClaudeConsumerAgent/js/luma/model/state'
], function (layout, registry, state) {
    'use strict';

    var PANEL_NAME = 'aiAgentPanel',
        registered = false;

    function panel() {
        if (!registered) {
            registered = true;
            layout([{
                name: PANEL_NAME,
                component: 'MageOS_ClaudeConsumerAgent/js/luma/view/panel'
            }]);
        }
        return new Promise(function (resolve) {
            registry.get(PANEL_NAME, resolve);
        });
    }

    return {
        open: function (detail) {
            var options = detail || {};

            return state.ready.then(function () {
                if (options.page) {
                    state.mergePage(options.page);
                }
                return panel();
            }).then(function (view) {
                view.openPanel(options.opener || null);
                return view;
            });
        }
    };
});
