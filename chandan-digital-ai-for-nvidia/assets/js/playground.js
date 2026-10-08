/**
 * Chandan Digital AI for NVIDIA - AI Playground.
 *
 * The conversation lives only in this browser tab. Every request goes to this site's REST API,
 * which adds the NVIDIA API key on the server. Model output is always inserted with textContent,
 * never as HTML, so a reply cannot inject markup or scripts.
 */
(function () {
	'use strict';

	var cfg = window.cdnvConfig || {};
	var t = cfg.i18n || {};
	var models = cfg.models || [];
	var MIME = { jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' };

	function $(id) {
		return document.getElementById(id);
	}

	var els = {
		model: $('cdnv-pg-model'),
		summary: $('cdnv-pg-model-summary'),
		stream: $('cdnv-pg-stream'),
		showReasoning: $('cdnv-pg-show-reasoning'),
		policy: $('cdnv-pg-policy'),
		skill: $('cdnv-pg-skill'),
		system: $('cdnv-pg-system'),
		log: $('cdnv-pg-log'),
		form: $('cdnv-pg-form'),
		prompt: $('cdnv-pg-prompt'),
		imagesBox: $('cdnv-pg-images'),
		file: $('cdnv-pg-file'),
		fileLabel: $('cdnv-pg-file-label'),
		urlWrap: $('cdnv-pg-url-wrap'),
		url: $('cdnv-pg-url'),
		urlAdd: $('cdnv-pg-url-add'),
		pending: $('cdnv-pg-pending'),
		imageHelp: $('cdnv-pg-image-help'),
		send: $('cdnv-pg-send'),
		stop: $('cdnv-pg-stop'),
		clear: $('cdnv-pg-clear'),
		status: $('cdnv-pg-status')
	};
	if (!els.form) {
		return;
	}

	var conversation = [];
	var pendingImages = [];
	var busy = false;
	var controller = null;

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

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array(16);
		window.crypto.getRandomValues(bytes);
		return Array.prototype.map.call(bytes, function (b) {
			return ('0' + b.toString(16)).slice(-2);
		}).join('').replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
	}

	function currentModel() {
		for (var i = 0; i < models.length; i++) {
			if (models[i].id === els.model.value) {
				return models[i];
			}
		}
		return null;
	}

	function setStatus(text, tone) {
		els.status.textContent = text || '';
		els.status.className = 'cdnv-chat__status' + (tone ? ' cdnv-chat__status--' + tone : '');
	}

	function scrollToEnd() {
		els.log.scrollTop = els.log.scrollHeight;
	}

	/* Model selection */
	function populateModels() {
		if (!models.length) {
			els.send.disabled = true;
			els.prompt.disabled = true;
			setStatus(t.noModels, 'bad');
			return;
		}
		models.forEach(function (model) {
			var option = el('option', '', model.name + ' (' + model.id + ')');
			option.value = model.id;
			els.model.appendChild(option);
		});
		onModelChange();
	}

	function onModelChange() {
		var model = currentModel();
		if (!model) {
			return;
		}
		els.summary.textContent = model.developer + ' · ' + model.summary;
		els.stream.checked = !!model.stream;
		els.imagesBox.hidden = !model.images;
		els.urlWrap.hidden = !model.imageUrls;
		els.file.accept = model.imageFormats.map(function (format) {
			return MIME[format];
		}).join(',');
		els.fileLabel.hidden = !cfg.canUpload;
		els.imageHelp.textContent = t.imageNotice + ' ' + model.imageFormats.join(', ').toUpperCase() + ' · ' + model.imageMaxMb + ' MB · ' + fmt(t.tooManyImages, model.imageMaxCount);
		if (!model.images) {
			pendingImages = [];
			renderPending();
		}
	}

	/* Pending images */
	function renderPending() {
		while (els.pending.firstChild) {
			els.pending.removeChild(els.pending.firstChild);
		}
		pendingImages.forEach(function (image, index) {
			var item = el('li', 'cdnv-chat__pending-item');
			if (image.type === 'data') {
				var img = el('img');
				img.src = image.value;
				img.alt = image.name;
				item.appendChild(img);
			}
			// Remote URLs are not loaded in the browser, so the admin's IP is not sent to that host.
			item.appendChild(el('span', 'cdnv-chat__pending-name', image.name));
			var remove = el('button', 'button-link', t.remove);
			remove.type = 'button';
			remove.addEventListener('click', function () {
				pendingImages.splice(index, 1);
				renderPending();
			});
			item.appendChild(remove);
			els.pending.appendChild(item);
		});
	}

	function canAddImage(model) {
		if (pendingImages.length >= model.imageMaxCount) {
			setStatus(fmt(t.tooManyImages, model.imageMaxCount), 'bad');
			return false;
		}
		return true;
	}

	els.file.addEventListener('change', function () {
		var model = currentModel();
		var file = els.file.files && els.file.files[0];
		els.file.value = '';
		if (!model || !file || !canAddImage(model)) {
			return;
		}
		var allowed = model.imageFormats.map(function (format) {
			return MIME[format];
		});
		if (allowed.indexOf(file.type) === -1) {
			setStatus(fmt(t.imageType, model.imageFormats.join(', ').toUpperCase()), 'bad');
			return;
		}
		if (file.size > model.imageMaxMb * 1048576) {
			setStatus(fmt(t.imageTooLarge, model.imageMaxMb), 'bad');
			return;
		}
		var reader = new FileReader();
		reader.onload = function () {
			pendingImages.push({ type: 'data', value: String(reader.result), name: file.name });
			renderPending();
			setStatus('');
		};
		reader.readAsDataURL(file);
	});

	els.urlAdd.addEventListener('click', function () {
		var model = currentModel();
		var value = els.url.value.trim();
		if (!model || !canAddImage(model)) {
			return;
		}
		if (!/^https:\/\/[^\s]+$/i.test(value)) {
			setStatus(t.imageUrlInvalid, 'bad');
			return;
		}
		pendingImages.push({ type: 'url', value: value, name: value });
		els.url.value = '';
		renderPending();
		setStatus('');
	});

	/* Conversation rendering */
	function renderUser(message) {
		var empty = els.log.querySelector('.cdnv-chat__empty');
		if (empty) {
			empty.remove();
		}
		var root = el('div', 'cdnv-msg cdnv-msg--user');
		root.appendChild(el('div', 'cdnv-msg__role', t.you));
		if (message.images.length) {
			var list = el('div', 'cdnv-msg__images');
			message.images.forEach(function (image) {
				if (image.type === 'data') {
					var img = el('img');
					img.src = image.value;
					img.alt = image.name;
					list.appendChild(img);
				} else {
					list.appendChild(el('span', 'cdnv-msg__image-url', image.value));
				}
			});
			root.appendChild(list);
		}
		root.appendChild(el('div', 'cdnv-msg__body', message.text));
		els.log.appendChild(root);
		scrollToEnd();
		return root;
	}

	function renderAssistant(modelName) {
		var root = el('div', 'cdnv-msg cdnv-msg--assistant');
		root.appendChild(el('div', 'cdnv-msg__role', t.assistant + ' · ' + modelName));
		var details = el('details', 'cdnv-msg__reasoning');
		details.hidden = true;
		details.appendChild(el('summary', '', t.reasoning));
		var reasoningBody = el('div', 'cdnv-msg__reasoning-body');
		details.appendChild(reasoningBody);
		root.appendChild(details);
		var body = el('div', 'cdnv-msg__body');
		var cursor = el('span', 'cdnv-msg__thinking', t.thinking);
		body.appendChild(cursor);
		root.appendChild(body);
		var notes = el('div', 'cdnv-msg__notes');
		root.appendChild(notes);
		var meta = el('div', 'cdnv-msg__meta');
		root.appendChild(meta);
		els.log.appendChild(root);
		scrollToEnd();

		var text = '';
		var started = false;
		function startBody() {
			if (!started) {
				started = true;
				body.removeChild(cursor);
			}
		}

		return {
			reset: function () {
				text = '';
				started = false;
				body.textContent = '';
				body.appendChild(cursor);
				reasoningBody.textContent = '';
				details.hidden = true;
			},
			appendContent: function (chunk) {
				startBody();
				text += chunk;
				body.appendChild(document.createTextNode(chunk));
				scrollToEnd();
			},
			appendReasoning: function (chunk) {
				details.hidden = false;
				reasoningBody.appendChild(document.createTextNode(chunk));
				scrollToEnd();
			},
			setContent: function (value) {
				startBody();
				text = value;
				body.textContent = value;
			},
			setReasoning: function (value) {
				if (value) {
					details.hidden = false;
					reasoningBody.textContent = value;
				}
			},
			note: function (message, tone) {
				notes.appendChild(el('p', 'cdnv-msg__note' + (tone ? ' cdnv-msg__note--' + tone : ''), message));
			},
			error: function (error) {
				startBody();
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
				root.appendChild(box);
				scrollToEnd();
			},
			finish: function (result) {
				startBody();
				var parts = [];
				if (result.duration_ms) {
					parts.push(fmt(t.seconds, (result.duration_ms / 1000).toFixed(1)));
				}
				if (result.usage && result.usage.prompt_tokens !== undefined) {
					parts.push(fmt(t.tokens, result.usage.prompt_tokens, result.usage.completion_tokens));
				}
				meta.textContent = parts.join(' · ');
				if (result.finish_reason === 'length') {
					this.note(t.lengthLimit, 'warn');
				}
				if (text) {
					var copy = el('button', 'button button-small', t.copy);
					copy.type = 'button';
					copy.addEventListener('click', function () {
						copyText(text, copy);
					});
					meta.appendChild(document.createTextNode(' '));
					meta.appendChild(copy);
				}
			}
		};
	}

	function copyText(text, button) {
		function done(ok) {
			var label = button.textContent;
			button.textContent = ok ? t.copied : t.copyFailed;
			setTimeout(function () {
				button.textContent = label;
			}, 2000);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				done(true);
			}, function () {
				done(false);
			});
			return;
		}
		var area = el('textarea');
		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'absolute';
		area.style.left = '-9999px';
		document.body.appendChild(area);
		area.select();
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}
		document.body.removeChild(area);
		done(ok);
	}

	/* Requests */
	function requestBody(messages) {
		return {
			model: els.model.value,
			messages: messages,
			system: els.system.value,
			skill: els.skill ? els.skill.value : '',
			policy: !!els.policy.checked,
			request_id: uuid()
		};
	}

	function post(path, body, signal) {
		return fetch(cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify(body),
			signal: signal
		});
	}

	function runStandard(messages, view) {
		return post('chat', requestBody(messages), controller.signal).then(function (response) {
			return response.json();
		}).then(function (data) {
			if (!data || !data.ok) {
				return { ok: false, error: data && data.error };
			}
			view.setReasoning(data.reasoning);
			view.setContent(data.content);
			(data.notices || []).forEach(function (notice) {
				view.note(notice, 'warn');
			});
			return {
				ok: true,
				content: data.content,
				reasoning: data.reasoning,
				finish_reason: data.finish_reason,
				usage: data.usage,
				duration_ms: data.duration_ms
			};
		}).catch(function (error) {
			if (error && error.name === 'AbortError') {
				return { ok: false, aborted: true };
			}
			return { ok: false, error: null };
		});
	}

	function runStream(messages, view) {
		var state = { content: '', reasoning: '', started: false, finished: false, result: null };
		return post('chat/stream', requestBody(messages), controller.signal).then(function (response) {
			var type = response.headers.get('content-type') || '';
			if (type.indexOf('text/event-stream') === -1) {
				// Rejected before streaming began (validation, permissions, duplicate): a JSON error.
				return response.json().then(function (data) {
					return { ok: false, error: data && data.error, rejected: true };
				});
			}
			return window.cdnvSse.read(response, function (eventName, data) {
				var payload;
				try {
					payload = JSON.parse(data);
				} catch (e) {
					return;
				}
				if (eventName === 'meta') {
					state.started = true;
					(payload.notices || []).forEach(function (notice) {
						view.note(notice, 'warn');
					});
				} else if (eventName === 'reasoning' && !state.finished) {
					state.reasoning += payload.text;
					view.appendReasoning(payload.text);
				} else if (eventName === 'content' && !state.finished) {
					state.content += payload.text;
					view.appendContent(payload.text);
				} else if (eventName === 'reset' && !state.finished) {
					// The server threw away a garbled reply and is asking again.
					state.content = '';
					state.reasoning = '';
					view.reset();
					view.note(payload.message, 'info');
				} else if (eventName === 'done' && !state.finished) {
					state.finished = true;
					state.result = {
						ok: true,
						content: state.content,
						reasoning: state.reasoning,
						finish_reason: payload.finish_reason,
						usage: payload.usage,
						duration_ms: payload.duration_ms
					};
				} else if (eventName === 'error' && !state.finished) {
					state.finished = true;
					state.result = { ok: false, error: payload, partial: !!payload.partial };
				}
			}).then(function () {
				if (state.result) {
					return state.result;
				}
				return {
					ok: false,
					started: state.started,
					partial: state.content !== '' || state.reasoning !== '',
					error: { code: 'stream_interrupted', message: t.interrupted }
				};
			});
		}).catch(function (error) {
			if (error && error.name === 'AbortError') {
				return { ok: false, aborted: true, partial: state.content !== '' };
			}
			// A network failure before the server started the stream means NVIDIA was never called.
			return { ok: false, started: state.started, networkFailure: true, error: null };
		});
	}

	function setBusy(value) {
		busy = value;
		els.send.disabled = value;
		els.stop.disabled = !value;
		els.model.disabled = value;
		els.form.setAttribute('aria-busy', value ? 'true' : 'false');
		setStatus(value ? t.working : '');
	}

	function serialise(message) {
		var out = { role: message.role, text: message.text };
		if (message.images && message.images.length) {
			out.images = message.images.map(function (image) {
				return { type: image.type, value: image.value };
			});
		}
		if (message.reasoning) {
			out.reasoning = message.reasoning;
		}
		return out;
	}

	els.form.addEventListener('submit', function (event) {
		event.preventDefault();
		if (busy) {
			return;
		}
		var model = currentModel();
		var text = els.prompt.value;
		if (!model) {
			return;
		}
		if (!text.trim() && !pendingImages.length) {
			setStatus(t.emptyPrompt, 'bad');
			return;
		}

		var userMessage = { role: 'user', text: text, images: pendingImages.slice() };
		renderUser(userMessage);
		els.prompt.value = '';
		pendingImages = [];
		renderPending();

		var messages = conversation.concat([userMessage]).map(serialise);
		var view = renderAssistant(model.name);
		controller = new AbortController();
		setBusy(true);

		var run = els.stream.checked
			? runStream(messages, view).then(function (result) {
				if (!result.ok && result.networkFailure && !result.started && !controller.signal.aborted) {
					view.note(t.fallbackUsed, 'info');
					return runStandard(messages, view);
				}
				return result;
			})
			: runStandard(messages, view);

		run.then(function (result) {
			if (result.ok) {
				conversation.push(userMessage, { role: 'assistant', text: result.content, reasoning: result.reasoning });
				view.finish(result);
				return;
			}
			if (result.aborted) {
				view.note(t.stopped, 'warn');
			} else {
				if (result.error && result.error.code === 'garbled_output') {
					view.reset();
				}
				view.error(result.error);
				if (result.partial && !(result.error && result.error.code === 'garbled_output')) {
					view.note(t.interrupted, 'warn');
				}
			}
		}).then(function () {
			setBusy(false);
			controller = null;
			els.prompt.focus();
		});
	});

	els.stop.addEventListener('click', function () {
		if (controller) {
			controller.abort();
		}
	});

	els.clear.addEventListener('click', function () {
		if (controller) {
			controller.abort();
		}
		conversation = [];
		pendingImages = [];
		renderPending();
		while (els.log.firstChild) {
			els.log.removeChild(els.log.firstChild);
		}
		setStatus('');
		els.prompt.focus();
	});

	els.prompt.addEventListener('keydown', function (event) {
		if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
			event.preventDefault();
			els.form.requestSubmit ? els.form.requestSubmit() : els.send.click();
		}
	});

	els.showReasoning.addEventListener('change', function () {
		els.log.classList.toggle('cdnv-hide-reasoning', !els.showReasoning.checked);
	});

	els.model.addEventListener('change', onModelChange);
	populateModels();
}());
