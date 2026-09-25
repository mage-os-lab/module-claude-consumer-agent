define([
    'uiLayout',
    'uiRegistry',
    'MageOS_AiShoppingAssistant/js/luma/model/state'
], function (layout, registry, state) {
    'use strict';

    var PANEL_NAME = 'aiAgentPanel',
        PANEL_COMPONENT = 'MageOS_AiShoppingAssistant/js/luma/view/panel',
        loading = null;

    function panel() {
        if (loading) {
            return loading;
        }
        loading = new Promise(function (resolve, reject) {
            require([PANEL_COMPONENT], function () {
                layout([{
                    name: PANEL_NAME,
                    component: PANEL_COMPONENT
                }]);
                registry.get(PANEL_NAME, resolve);
            }, function (error) {
                [PANEL_COMPONENT].concat(error.requireModules || []).forEach(function (id) {
                    require.undef(id);
                });
                loading = null;
                reject(error);
            });
        });
        return loading;
    }

    return {
        open: function (detail) {
            var options = detail || {};

            return state.ready.then(function () {
                if (options.page) {
                    state.mergePage(options.page);
                }
                state.start().catch(function () {
                    return null;
                });
                return panel();
            }).then(function (view) {
                view.openPanel(options.opener || null, options.focus !== false);
                return view;
            }).catch(function (error) {
                console.error(error);
                throw error;
            });
        }
    };
});
