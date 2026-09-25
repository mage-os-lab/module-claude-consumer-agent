define([
    'MageOS_AiShoppingAssistant/js/luma/opener'
], function (opener) {
    'use strict';

    return function (config, element) {
        element.addEventListener('click', function () {
            opener.open({
                opener: element,
                page: {type: 'product', productId: String(config.productId || '')}
            }).catch(function () {});
        });
    };
});
