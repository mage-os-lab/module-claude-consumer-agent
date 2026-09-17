define([], function () {
    'use strict';

    return {
        create: function (onEvent) {
            var buffer = '';

            return {
                push: function (chunk) {
                    var frameEnd, frame, typeMatch, dataMatch;

                    buffer += chunk;
                    frameEnd = buffer.indexOf('\n\n');
                    while (frameEnd !== -1) {
                        frame = buffer.slice(0, frameEnd);
                        buffer = buffer.slice(frameEnd + 2);
                        if (frame.charAt(0) !== ':') {
                            typeMatch = frame.match(/^event: (.+)$/m);
                            dataMatch = frame.match(/^data: (.+)$/m);
                            if (typeMatch && dataMatch) {
                                onEvent(typeMatch[1], JSON.parse(dataMatch[1]));
                            }
                        }
                        frameEnd = buffer.indexOf('\n\n');
                    }
                }
            };
        }
    };
});
