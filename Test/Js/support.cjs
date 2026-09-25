'use strict';

const fs = require('node:fs');
const path = require('node:path');

const MODULE_PREFIX = 'MageOS_AiShoppingAssistant/';
const WEB_ROOT = path.resolve(__dirname, '../../view/frontend/web');

function load(moduleId, stubs, cache) {
    const registry = cache || new Map();
    if (registry.has(moduleId)) {
        return registry.get(moduleId);
    }
    const file = path.join(WEB_ROOT, moduleId.slice(MODULE_PREFIX.length) + '.js');
    let definition = null;
    const define = function (deps, factory) {
        definition = typeof deps === 'function' ? {deps: [], factory: deps} : {deps: deps, factory: factory};
    };
    new Function('define', fs.readFileSync(file, 'utf8'))(define);
    const resolved = definition.deps.map(function (dep) {
        if (stubs && Object.prototype.hasOwnProperty.call(stubs, dep)) {
            return stubs[dep];
        }
        if (dep.indexOf(MODULE_PREFIX) === 0) {
            return load(dep, stubs, registry);
        }
        throw new Error('Missing stub for ' + dep + ' in ' + moduleId);
    });
    const exported = definition.factory.apply(null, resolved);
    registry.set(moduleId, exported);
    return exported;
}

function createKo() {
    function observable(initial) {
        let value = initial;
        const subscribers = [];
        function accessor() {
            if (arguments.length === 0) {
                return value;
            }
            value = arguments[0];
            subscribers.slice().forEach((callback) => callback(value));
            return accessor;
        }
        accessor.subscribe = (callback) => {
            subscribers.push(callback);
            return {dispose: () => undefined};
        };
        return accessor;
    }

    function observableArray(initial) {
        const accessor = observable(initial || []);
        accessor.push = (item) => accessor(accessor().concat([item]));
        return accessor;
    }

    return {
        observable: observable,
        observableArray: observableArray,
        pureComputed: (read) => read,
        unwrap: (value) => (typeof value === 'function' ? value() : value)
    };
}

function fakeDocument() {
    const doc = {
        createElement: function (tag) {
            return {
                tagName: tag.toUpperCase(),
                className: '',
                childNodes: [],
                ownerDocument: doc,
                appendChild: function (child) {
                    this.childNodes.push(child);
                    return child;
                },
                removeChild: function (child) {
                    this.childNodes.splice(this.childNodes.indexOf(child), 1);
                    return child;
                },
                get firstChild() {
                    return this.childNodes[0] || null;
                }
            };
        },
        createTextNode: function (text) {
            return {nodeValue: text};
        }
    };
    return doc;
}

function flush() {
    return new Promise((resolve) => setImmediate(resolve));
}

module.exports = {load, createKo, fakeDocument, flush};
