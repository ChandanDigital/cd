/**
 * Chandan Digital AI for NVIDIA - Server-Sent Events reader.
 *
 * Reads a fetch() response body as a stream and reports complete SSE events. Network chunks do
 * not line up with events, so unfinished text is kept until its blank-line terminator arrives.
 * A "\r" at the end of a chunk is held back in case the matching "\n" comes next.
 */
(function () {
	'use strict';

	function createParser(onEvent) {
		var buffer = '';

		function dispatch(block) {
			var eventName = 'message';
			var data = [];
			block.split('\n').forEach(function (line) {
				if (!line || line.charAt(0) === ':') {
					return;
				}
				var index = line.indexOf(':');
				var field = index === -1 ? line : line.slice(0, index);
				var value = index === -1 ? '' : line.slice(index + 1);
				if (value.charAt(0) === ' ') {
					value = value.slice(1);
				}
				if (field === 'event') {
					eventName = value;
				} else if (field === 'data') {
					data.push(value);
				}
			});
			if (data.length) {
				onEvent(eventName, data.join('\n'));
			}
		}

		return {
			push: function (text) {
				buffer += text;
				var held = '';
				if (buffer.slice(-1) === '\r') {
					held = '\r';
					buffer = buffer.slice(0, -1);
				}
				buffer = buffer.replace(/\r\n?/g, '\n');
				var position;
				while ((position = buffer.indexOf('\n\n')) !== -1) {
					var block = buffer.slice(0, position);
					buffer = buffer.slice(position + 2);
					dispatch(block);
				}
				buffer += held;
			},
			finish: function () {
				var rest = buffer.replace(/\r\n?/g, '\n').replace(/^\n+|\n+$/g, '');
				buffer = '';
				if (rest) {
					dispatch(rest);
				}
			}
		};
	}

	/**
	 * Reads every event from a fetch() Response. Resolves when the stream ends.
	 *
	 * @param {Response} response Fetch response.
	 * @param {function(string, string)} onEvent Receives (event name, data).
	 * @return {Promise<void>}
	 */
	function read(response, onEvent) {
		var parser = createParser(onEvent);
		if (!response.body || !response.body.getReader || typeof TextDecoder === 'undefined') {
			return response.text().then(function (text) {
				parser.push(text);
				parser.finish();
			});
		}
		var reader = response.body.getReader();
		var decoder = new TextDecoder('utf-8');
		function pump() {
			return reader.read().then(function (step) {
				if (step.done) {
					parser.push(decoder.decode());
					parser.finish();
					return;
				}
				// stream: true keeps multi-byte characters that are split across chunks intact.
				parser.push(decoder.decode(step.value, { stream: true }));
				return pump();
			});
		}
		return pump();
	}

	window.cdnvSse = { createParser: createParser, read: read };
}());
