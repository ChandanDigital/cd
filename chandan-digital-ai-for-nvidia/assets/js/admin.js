/**
 * Chandan Digital AI for NVIDIA - admin screens (tests, model manager, diagnostics).
 *
 * All text from the server is inserted with textContent, never as HTML.
 */
(function () {
	'use strict';

	var cfg = window.cdnvConfig || {};
	var t = cfg.i18n || {};

	function fmt(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(text || '').replace(/%(\d+\$)?[sd]/g, function (match, position) {
			var index = position ? parseInt(position, 10) - 1 : i++;
			return args[index] !== undefined ? String(args[index]) : match;
		});
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		return node;
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function api(path, body) {
		return fetch(cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify(body || {})
		}).then(function (response) {
			return response.json().catch(function () {
				return null;
			}).then(function (data) {
				return { httpOk: response.ok, status: response.status, data: data };
			});
		});
	}

	function pill(label, tone) {
		return el('span', 'cdnv-pill cdnv-pill--' + tone, label);
	}

	function errorBlock(error) {
		var box = el('div', 'cdnv-error');
		box.appendChild(el('p', 'cdnv-error__message', (error && error.message) || t.requestFailed));
		if (error && error.detail) {
			box.appendChild(el('p', 'cdnv-error__detail', error.detail));
		}
		if (error && error.status) {
			box.appendChild(el('p', 'description', t.http + ' ' + error.status + (error.code ? ' · ' + error.code : '')));
		}
		if (error && error.retry_after) {
			box.appendChild(el('p', 'description', fmt(t.retryAfter, error.retry_after)));
		}
		return box;
	}

	function busy(button, isBusy) {
		button.disabled = isBusy;
		button.setAttribute('aria-busy', isBusy ? 'true' : 'false');
	}

	function target(button) {
		return document.querySelector(button.getAttribute('data-target'));
	}

	function working(node) {
		clear(node);
		node.appendChild(el('p', 'cdnv-working', t.working));
	}

	/* Connection test */
	function connectionTest(button) {
		var out = target(button);
		var selector = button.getAttribute('data-model-select');
		var model = selector && document.querySelector(selector) ? document.querySelector(selector).value : '';
		busy(button, true);
		working(out);
		api('connection-test', { model: model }).then(function (res) {
			clear(out);
			if (!res.data || !res.data.steps) {
				out.appendChild(errorBlock(res.data && res.data.error));
				return;
			}
			var list = el('ul', 'cdnv-steps');
			res.data.steps.forEach(function (step) {
				var item = el('li', 'cdnv-step');
				var tone = step.ok === true ? 'ok' : (step.ok === false ? 'bad' : 'muted');
				var label = step.ok === true ? t.ok : (step.ok === false ? t.failed : t.notChecked);
				item.appendChild(pill(label, tone));
				item.appendChild(el('strong', '', ' ' + step.label));
				var meta = [];
				if (step.http) {
					meta.push(t.http + ' ' + step.http);
				}
				if (step.duration_ms) {
					meta.push(step.duration_ms + ' ms');
				}
				if (meta.length) {
					item.appendChild(el('span', 'description', ' (' + meta.join(', ') + ')'));
				}
				if (step.note) {
					item.appendChild(el('p', 'description', step.note));
				}
				if (step.error) {
					item.appendChild(errorBlock(step.error));
				}
				list.appendChild(item);
			});
			out.appendChild(list);
			out.appendChild(el('p', res.data.ok ? 'cdnv-summary cdnv-summary--ok' : 'cdnv-summary cdnv-summary--bad', res.data.summary));
		}).catch(function () {
			clear(out);
			out.appendChild(errorBlock(null));
		}).then(function () {
			busy(button, false);
		});
	}

	/* Model access check */
	var stateLabels = {
		verified: ['verified', 'ok'],
		not_available: ['notAvailable', 'bad'],
		access_denied: ['accessDenied', 'bad'],
		error: ['error', 'warn']
	};

	function verifyModel(button) {
		var model = button.getAttribute('data-model');
		var cells = document.querySelectorAll('[data-cdnv-access]');
		busy(button, true);
		var original = button.textContent;
		button.textContent = t.working;
		api('models/verify', { model: model }).then(function (res) {
			var data = res.data || {};
			var state = data.status && data.status.state ? data.status.state : (data.ok ? 'verified' : 'error');
			var label = stateLabels[state] || ['error', 'warn'];
			Array.prototype.forEach.call(cells, function (cell) {
				if (cell.getAttribute('data-cdnv-access') !== model) {
					return;
				}
				clear(cell);
				cell.appendChild(pill(t[label[0]], label[1]));
				if (data.error) {
					cell.appendChild(errorBlock(data.error));
				} else if (data.http) {
					cell.appendChild(el('p', 'description', t.http + ' ' + data.http + ', ' + data.duration_ms + ' ms'));
				}
			});
		}).catch(function () {
			window.alert(t.requestFailed); // eslint-disable-line no-alert
		}).then(function () {
			button.textContent = original;
			busy(button, false);
		});
	}

	/* Catalogue refresh */
	function refreshModels(button) {
		var out = target(button);
		busy(button, true);
		working(out);
		api('models/refresh', {}).then(function (res) {
			clear(out);
			var data = res.data || {};
			if (!data.ok) {
				out.appendChild(errorBlock(data.error));
				return;
			}
			out.appendChild(el('p', 'cdnv-summary cdnv-summary--ok', fmt(t.refreshDone, data.count)));

			var missing = data.missing || [];
			out.appendChild(el('p', '', t.missing + ' ' + (missing.length ? missing.join(', ') : t.none)));

			var unregistered = data.unregistered || [];
			var details = el('details', 'cdnv-details');
			details.appendChild(el('summary', '', t.unregistered + ' ' + unregistered.length));
			var list = el('ul', 'cdnv-catalog-list');
			unregistered.slice(0, 500).forEach(function (id) {
				var item = el('li');
				item.appendChild(el('code', '', id));
				var add = el('button', 'button button-small', t.useAsCustom);
				add.type = 'button';
				add.addEventListener('click', function () {
					var input = document.getElementById('cdnv-custom-id');
					if (input) {
						input.value = id;
						input.focus();
						input.scrollIntoView({ behavior: 'smooth', block: 'center' });
					}
				});
				item.appendChild(document.createTextNode(' '));
				item.appendChild(add);
				list.appendChild(item);
			});
			details.appendChild(list);
			out.appendChild(details);
		}).catch(function () {
			clear(out);
			out.appendChild(errorBlock(null));
		}).then(function () {
			busy(button, false);
		});
	}

	/* Streaming self-test */
	function streamTest(button) {
		var out = target(button);
		busy(button, true);
		working(out);
		var start = performance.now();
		var times = [];
		fetch(cfg.restUrl + 'stream-test', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		}).then(function (response) {
			if ((response.headers.get('content-type') || '').indexOf('text/event-stream') === -1) {
				throw new Error('not-a-stream');
			}
			return window.cdnvSse.read(response, function (eventName) {
				if (eventName === 'tick') {
					times.push(Math.round(performance.now() - start));
				}
			});
		}).then(function () {
			clear(out);
			if (times.length < 5) {
				out.appendChild(el('p', 'cdnv-summary cdnv-summary--bad', t.streamFailed));
				return;
			}
			// Ticks are sent 400 ms apart, so progressive delivery spreads them over about 1.6 s.
			var spread = times[times.length - 1] - times[0];
			if (spread >= 1000) {
				out.appendChild(el('p', 'cdnv-summary cdnv-summary--ok', fmt(t.streamWorks, times[0], times[times.length - 1])));
			} else {
				out.appendChild(el('p', 'cdnv-summary cdnv-summary--warn', fmt(t.streamBuffered, times[times.length - 1])));
			}
		}).catch(function () {
			clear(out);
			out.appendChild(el('p', 'cdnv-summary cdnv-summary--bad', t.streamFailed));
		}).then(function () {
			busy(button, false);
		});
	}

	/* Model search and filter */
	function filterModels() {
		var search = document.getElementById('cdnv-model-search');
		var enabledOnly = document.getElementById('cdnv-model-enabled-only');
		if (!search) {
			return;
		}
		var terms = search.value.toLowerCase().split(/\s+/).filter(Boolean);
		var visible = 0;
		Array.prototype.forEach.call(document.querySelectorAll('[data-cdnv-model-row]'), function (row) {
			var text = row.getAttribute('data-search') || '';
			var checkbox = row.querySelector('input[type="checkbox"]');
			var show = terms.every(function (term) {
				return text.indexOf(term) !== -1;
			}) && (!enabledOnly || !enabledOnly.checked || (checkbox && checkbox.checked));
			row.hidden = !show;
			if (show) {
				visible++;
			}
		});
		var empty = document.querySelector('.cdnv-no-results');
		if (empty) {
			empty.hidden = visible !== 0;
		}
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest ? event.target.closest('[data-cdnv-action]') : null;
		if (!button) {
			return;
		}
		var action = button.getAttribute('data-cdnv-action');
		if (action === 'connection-test') {
			connectionTest(button);
		} else if (action === 'verify-model') {
			verifyModel(button);
		} else if (action === 'refresh-models') {
			refreshModels(button);
		} else if (action === 'stream-test') {
			streamTest(button);
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		var message = form && form.getAttribute ? form.getAttribute('data-cdnv-confirm') : null;
		if (message && !window.confirm(message)) { // eslint-disable-line no-alert
			event.preventDefault();
		}
	});

	['cdnv-model-search', 'cdnv-model-enabled-only'].forEach(function (id) {
		var node = document.getElementById(id);
		if (node) {
			node.addEventListener('input', filterModels);
			node.addEventListener('change', filterModels);
		}
	});
}());
