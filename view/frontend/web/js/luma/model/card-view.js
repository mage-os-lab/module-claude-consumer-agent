define([
    'MageOS_ClaudeConsumerAgent/js/luma/model/cards',
    'MageOS_ClaudeConsumerAgent/js/luma/model/format'
], function (cards, format) {
    'use strict';

    var TEMPLATE_PREFIX = 'MageOS_ClaudeConsumerAgent/luma/cards/',
        builders;

    function productItem(item, productsConfig, send) {
        var product = item.product,
            actionName = cards.action(product, productsConfig);

        return {
            productId: product.product_id,
            title: product.title,
            url: cards.safeUrl(product.url),
            imageUrl: cards.safeUrl(product.image_url),
            showImage: productsConfig.image !== false,
            priceText: productsConfig.price !== false ? format.price(product.price) : '',
            optionValuesText: cards.optionValuesText(product),
            customOptionLines: cards.needsPageChoice(product) ? [] : cards.customOptionLines(product),
            description: productsConfig.description !== false && cards.hasText(product.short_description)
                ? product.short_description
                : '',
            reason: productsConfig.reason !== false && cards.hasText(item.reason) ? item.reason : '',
            stock: cards.stock(product, productsConfig),
            action: actionName,
            runAction: function () {
                var message = cards.actionMessage(product, actionName);

                if (message) {
                    send(message);
                }
            }
        };
    }

    function products(card, config, send) {
        var productsConfig = (config.cards || {}).products || {},
            payload = card.payload;

        return {
            title: payload.title || config.i18n.products,
            layout: cards.isListLayout(payload) ? 'list' : 'tiles',
            tiles: cards.tilesModifier(payload),
            items: (payload.items || []).map(function (item) {
                return productItem(item, productsConfig, send);
            })
        };
    }

    function comparison(card, config) {
        var payload = card.payload;

        return {
            title: payload.title || config.i18n.compare,
            entries: (payload.entries || []).map(function (entry) {
                return {
                    productId: entry.product_id,
                    title: entry.product.title,
                    optionValuesText: cards.optionValuesText(entry.product),
                    priceText: format.price(entry.product.price),
                    recommended: entry.product_id === payload.recommended_product_id,
                    pros: entry.pros || [],
                    cons: entry.cons || [],
                    bestFor: entry.best_for || ''
                };
            }),
            priceDeltaLine: payload.price_delta
                ? format.str(config.i18n.priceDifference, format.price(payload.price_delta.amount))
                : ''
        };
    }

    function orderStatus(card, config) {
        var payload = card.payload,
            labels = config.i18n.orderStatus,
            status = Object.prototype.hasOwnProperty.call(labels, payload.order.status)
                ? payload.order.status
                : 'unknown';

        return {
            status: status,
            statusLabel: labels[status],
            summary: payload.summary || '',
            nextStep: payload.next_step || '',
            items: payload.order.items || [],
            trackingUrl: cards.safeUrl(payload.order.tracking_url)
        };
    }

    function checkout(card, config) {
        var payload = card.payload;

        return {
            lines: (payload.cart.items || []).map(function (line) {
                return {
                    title: line.title,
                    imageUrl: cards.safeUrl(line.image_url),
                    quantity: line.quantity,
                    totalText: format.price(line.line_total)
                };
            }),
            subtotalText: format.price(payload.cart.subtotal),
            fulfillmentLine: payload.fulfillment_method
                ? format.str(config.i18n.fulfillment, payload.fulfillment_method)
                : '',
            note: payload.note || '',
            checkoutUrl: cards.safeUrl(payload.checkout_url)
        };
    }

    builders = {
        products: {
            template: 'products',
            build: products,
            ready: function (payload) {
                return Array.isArray(payload.items);
            }
        },
        comparison: {
            template: 'comparison',
            build: comparison,
            ready: function (payload) {
                return Array.isArray(payload.entries);
            }
        },
        order_status: {
            template: 'order-status',
            build: orderStatus,
            ready: function (payload) {
                return !!payload.order;
            }
        },
        checkout: {
            template: 'checkout',
            build: checkout,
            ready: function (payload) {
                return !!payload.cart && cards.safeUrl(payload.checkout_url) !== '';
            }
        }
    };

    return {
        create: function (card, config, send) {
            var builder = builders[card.component],
                view;

            if (!builder || !card.payload || !builder.ready(card.payload)) {
                return null;
            }
            view = builder.build(card, config, send);
            view.id = card.id;
            view.component = card.component;
            view.template = TEMPLATE_PREFIX + builder.template;
            return view;
        }
    };
});
