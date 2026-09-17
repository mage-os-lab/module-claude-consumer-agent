define([], function () {
    'use strict';

    var BULLET = /^[-*]\s+/,
        ORDERED = /^\d+\.\s+/;

    function appendInline(doc, parent, text) {
        String(text).split('**').forEach(function (part, index) {
            var strong;

            if (part === '') {
                return;
            }
            if (index % 2 === 1) {
                strong = doc.createElement('strong');
                strong.appendChild(doc.createTextNode(part));
                parent.appendChild(strong);
                return;
            }
            parent.appendChild(doc.createTextNode(part));
        });
    }

    function appendList(doc, container, lines, start, pattern, tagName) {
        var list = doc.createElement(tagName),
            index = start,
            item;

        list.className = 'ai-agent-list';
        while (index < lines.length && pattern.test(lines[index])) {
            item = doc.createElement('li');
            appendInline(doc, item, lines[index].replace(pattern, ''));
            list.appendChild(item);
            index++;
        }
        container.appendChild(list);
        return index;
    }

    function appendParagraph(doc, container, lines, start) {
        var paragraph = doc.createElement('p'),
            index = start;

        while (index < lines.length && !BULLET.test(lines[index]) && !ORDERED.test(lines[index])) {
            if (index > start) {
                paragraph.appendChild(doc.createElement('br'));
            }
            appendInline(doc, paragraph, lines[index]);
            index++;
        }
        container.appendChild(paragraph);
        return index;
    }

    return {
        render: function (container, text) {
            var doc = container.ownerDocument;

            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }
            String(text || '').split(/\n{2,}/).forEach(function (block) {
                var lines = block.split('\n'),
                    index = 0;

                while (index < lines.length) {
                    if (BULLET.test(lines[index])) {
                        index = appendList(doc, container, lines, index, BULLET, 'ul');
                    } else if (ORDERED.test(lines[index])) {
                        index = appendList(doc, container, lines, index, ORDERED, 'ol');
                    } else {
                        index = appendParagraph(doc, container, lines, index);
                    }
                }
            });
        }
    };
});
