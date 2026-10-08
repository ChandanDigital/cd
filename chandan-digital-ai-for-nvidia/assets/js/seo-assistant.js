/**
 * Chandan Digital AI for NVIDIA - SEO Assistant in the post editor.
 *
 * Works in the block editor and the Classic Editor. Reads the current (unsaved) title and content,
 * asks this site's REST API (which talks to NVIDIA), and only changes the post when the user presses
 * a "Use" button. Model text is inserted with textContent; improved content HTML is cleaned on the
 * server with wp_kses_post before it is shown.
 */
(function () {
	'use strict';

	var cfg = window.cdnvSeoConfig || {};
	var t = cfg.i18n || {};
	var root = document.getElementById('cdnv-seo');
	if (!root) {
		return;
	}
	var postId = parseInt(root.getAttribute('data-post-id'), 10);
	var statusEl = root.querySelector('.cdnv-seo__status');
	var resultsEl = root.querySelector('.cdnv-seo__results');
	var busy = false;

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

	function button(label, onClick, primary) {
		var b = el('button', 'button button-small' + (primary ? ' button-primary' : ''), label);
		b.type = 'button';
		b.addEventListener('click', onClick);
		return b;
	}

	/* Editor access (block editor or Classic Editor) */
	function blockEditor() {
		return !!(window.wp && wp.data && wp.data.select && wp.data.select('core/editor') && document.body.classList.contains('block-editor-page'));
	}

	function getTitle() {
		if (blockEditor()) {
			return wp.data.select('core/editor').getEditedPostAttribute('title') || '';
		}
		var input = document.getElementById('title');
		return input ? input.value : '';
	}

	function getContent() {
		if (blockEditor()) {
			return wp.data.select('core/editor').getEditedPostContent() || '';
		}
		if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) {
			return tinymce.get('content').getContent();
		}
		var area = document.getElementById('content');
		return area ? area.value : '';
	}

	function setTitle(value) {
		if (blockEditor()) {
			wp.data.dispatch('core/editor').editPost({ title: value });
			return;
		}
		var input = document.getElementById('title');
		if (input) {
			input.value = value;
			input.dispatchEvent(new Event('input', { bubbles: true }));
			var label = document.getElementById('title-prompt-text');
			if (label) {
				label.classList.add('screen-reader-text');
			}
		}
	}

	function setContent(html) {
		if (blockEditor()) {
			var blocks = wp.blocks && wp.blocks.rawHandler ? wp.blocks.rawHandler({ HTML: html }) : null;
			if (blocks && wp.data.dispatch('core/editor').resetEditorBlocks) {
				wp.data.dispatch('core/editor').resetEditorBlocks(blocks);
			} else {
				wp.data.dispatch('core/editor').editPost({ content: html });
			}
			return;
		}
		if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) {
			tinymce.get('content').setContent(html);
			tinymce.get('content').fire('change');
			return;
		}
		var area = document.getElementById('content');
		if (area) {
			area.value = html;
		}
	}

	/** Fills a Chandan Digital SEO field on screen, so saving the post keeps the new value. */
	function fillSeoField(name, value) {
		var field = document.querySelector('[name="seom_' + name + '"]');
		if (field) {
			field.value = value;
			field.dispatchEvent(new Event('input', { bubbles: true }));
			field.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	/* Requests */
	function post(path, body) {
		return fetch(cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.json().catch(function () {
				return { ok: false, error: { message: t.failed } };
			});
		}).catch(function () {
			return { ok: false, error: { message: t.failed } };
		});
	}

	function setStatus(text, tone) {
		statusEl.textContent = text || '';
		statusEl.className = 'cdnv-seo__status' + (tone ? ' cdnv-seo__status--' + tone : '');
	}

	function showError(error) {
		var box = el('div', 'cdnv-seo__error');
		box.appendChild(el('p', 'cdnv-seo__error-message', (error && error.message) || t.failed));
		if (error && error.detail) {
			box.appendChild(el('p', 'description', error.detail));
		}
		if (error && error.status) {
			box.appendChild(el('p', 'description', 'HTTP ' + error.status + (error.code ? ' · ' + error.code : '')));
		}
		resultsEl.appendChild(box);
	}

	function copy(text, btn) {
		var done = function () {
			var label = btn.textContent;
			btn.textContent = t.copied;
			setTimeout(function () {
				btn.textContent = label;
			}, 1500);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, done);
			return;
		}
		var area = el('textarea');
		area.value = text;
		document.body.appendChild(area);
		area.select();
		try {
			document.execCommand('copy');
		} catch (e) {
			// Nothing else to try; the text stays visible for manual copying.
		}
		document.body.removeChild(area);
		done();
	}

	function saveField(field, value, btn) {
		btn.disabled = true;
		post('apply', { post_id: postId, field: field, value: value }).then(function (data) {
			btn.disabled = false;
			if (data && data.ok) {
				fillSeoField(field, value);
				btn.textContent = t.saved;
				setStatus(t.applied, 'ok');
			} else {
				showError(data && data.error);
			}
		});
	}

	/* Result rendering */
	function renderOptions(items, kind) {
		var list = el('ol', 'cdnv-seo__options');
		items.forEach(function (item) {
			var li = el('li');
			li.appendChild(el('span', 'cdnv-seo__option-text', item.text));
			var limit = kind === 'titles' ? 60 : 160;
			li.appendChild(el('span', 'cdnv-seo__count' + (item.chars > limit ? ' cdnv-seo__count--long' : ''), fmt(t.chars, item.chars)));
			var actions = el('span', 'cdnv-seo__option-actions');
			if (kind === 'titles') {
				actions.appendChild(button(t.usePostTitle, function () {
					setTitle(item.text);
					setStatus(t.applied, 'ok');
				}));
				if (cfg.hasSeoPlugin) {
					actions.appendChild(button(t.useSeoTitle, function (event) {
						saveField('title', item.text, event.currentTarget);
					}));
				}
			} else if (cfg.hasSeoPlugin) {
				actions.appendChild(button(t.useDescription, function (event) {
					saveField('description', item.text, event.currentTarget);
				}));
			}
			actions.appendChild(button(t.copy, function (event) {
				copy(item.text, event.currentTarget);
			}));
			li.appendChild(actions);
			list.appendChild(li);
		});
		resultsEl.appendChild(list);
	}

	function renderAudit(data) {
		resultsEl.appendChild(el('p', 'cdnv-seo__score', fmt(t.score, data.score)));
		if (data.summary) {
			resultsEl.appendChild(el('p', '', data.summary));
		}
		if (data.issues && data.issues.length) {
			resultsEl.appendChild(el('h4', '', t.issues));
			var issues = el('ul', 'cdnv-seo__issues');
			data.issues.forEach(function (issue) {
				var li = el('li', 'cdnv-seo__issue cdnv-seo__issue--' + issue.priority);
				li.appendChild(el('strong', '', issue.issue));
				if (issue.fix) {
					li.appendChild(el('span', 'cdnv-seo__fix', ' ' + issue.fix));
				}
				issues.appendChild(li);
			});
			resultsEl.appendChild(issues);
		}
		if (data.strengths && data.strengths.length) {
			resultsEl.appendChild(el('h4', '', t.strengths));
			var good = el('ul', 'cdnv-seo__strengths');
			data.strengths.forEach(function (s) {
				good.appendChild(el('li', '', s));
			});
			resultsEl.appendChild(good);
		}
	}

	function renderImproved(data) {
		if (data.warning) {
			resultsEl.appendChild(el('p', 'cdnv-seo__warning', data.warning));
		}
		var preview = el('div', 'cdnv-seo__preview');
		// Cleaned on the server with wp_kses_post.
		preview.innerHTML = data.html;
		resultsEl.appendChild(preview);
		var actions = el('p', 'cdnv-seo__option-actions');
		actions.appendChild(button(t.replaceContent, function () {
			if (window.confirm(t.confirmReplace)) { // eslint-disable-line no-alert
				setContent(data.html);
				setStatus(t.applied, 'ok');
			}
		}, true));
		actions.appendChild(button(t.copyHtml, function (event) {
			copy(data.html, event.currentTarget);
		}));
		resultsEl.appendChild(actions);
	}

	function renderLinks(items) {
		if (!items.length) {
			resultsEl.appendChild(el('p', '', t.noLinks));
			return;
		}
		var list = el('ul', 'cdnv-seo__links');
		items.forEach(function (link) {
			var li = el('li');
			li.appendChild(el('strong', '', '"' + link.anchor + '"'));
			li.appendChild(document.createTextNode(' → '));
			var a = el('a', '', link.title);
			a.href = link.url;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			li.appendChild(a);
			if (link.reason) {
				li.appendChild(el('span', 'description', ' ' + t.linkReason + ': ' + link.reason));
			}
			var html = '<a href="' + link.url.replace(/"/g, '&quot;') + '">' + link.anchor.replace(/</g, '&lt;') + '</a>';
			li.appendChild(document.createTextNode(' '));
			li.appendChild(button(t.copyLink, function (event) {
				copy(html, event.currentTarget);
			}));
			list.appendChild(li);
		});
		resultsEl.appendChild(list);
	}

	function renderImage(data) {
		var figure = el('figure', 'cdnv-seo__image');
		var img = el('img');
		img.src = data.url;
		img.alt = data.alt;
		figure.appendChild(img);
		figure.appendChild(el('figcaption', '', t.altText + ': ' + data.alt));
		resultsEl.appendChild(figure);
		var actions = el('p', 'cdnv-seo__option-actions');
		actions.appendChild(button(t.setFeatured, function (event) {
			var btn = event.currentTarget;
			btn.disabled = true;
			post('featured', { post_id: postId, attachment_id: data.attachment_id }).then(function (result) {
				btn.disabled = false;
				if (result && result.ok) {
					if (blockEditor()) {
						wp.data.dispatch('core/editor').editPost({ featured_media: data.attachment_id });
					}
					setStatus(t.featuredSet, 'ok');
				} else {
					showError(result && result.error);
				}
			});
		}, true));
		resultsEl.appendChild(actions);
		var details = el('details');
		details.appendChild(el('summary', '', t.promptUsed));
		details.appendChild(el('p', 'description', data.prompt));
		resultsEl.appendChild(details);
	}

	/* Tasks */
	function runTask(task) {
		if (busy) {
			return;
		}
		var title = getTitle();
		var content = getContent();
		if (!title.trim() && !content.trim()) {
			setStatus(t.emptyContent, 'bad');
			return;
		}
		busy = true;
		root.classList.add('is-busy');
		Array.prototype.forEach.call(root.querySelectorAll('[data-seo-task]'), function (b) {
			b.disabled = true;
		});
		resultsEl.textContent = '';
		setStatus(task === 'image' ? t.imageWorking : t.working, 'busy');
		post('run', {
			post_id: postId,
			task: task,
			keyword: document.getElementById('cdnv-seo-keyword').value,
			model: document.getElementById('cdnv-seo-model').value,
			title: title,
			content: content
		}).then(function (data) {
			busy = false;
			root.classList.remove('is-busy');
			Array.prototype.forEach.call(root.querySelectorAll('[data-seo-task]'), function (b) {
				b.disabled = false;
			});
			setStatus('');
			if (!data || !data.ok) {
				showError(data && data.error);
				return;
			}
			if (task === 'titles' || task === 'meta') {
				renderOptions(data.items || [], task);
			} else if (task === 'audit') {
				renderAudit(data);
			} else if (task === 'improve') {
				renderImproved(data);
			} else if (task === 'links') {
				renderLinks(data.items || []);
			} else if (task === 'image') {
				renderImage(data);
			}
		});
	}

	root.addEventListener('click', function (event) {
		var taskButton = event.target.closest('[data-seo-task]');
		if (taskButton) {
			runTask(taskButton.getAttribute('data-seo-task'));
			return;
		}
		var keywordButton = event.target.closest('[data-seo-action="save-keyword"]');
		if (keywordButton) {
			var value = document.getElementById('cdnv-seo-keyword').value.trim();
			keywordButton.disabled = true;
			post('apply', { post_id: postId, field: 'keyword', value: value }).then(function (data) {
				keywordButton.disabled = false;
				if (data && data.ok) {
					fillSeoField('keyword', value);
					setStatus(t.keywordSaved, 'ok');
				} else {
					showError(data && data.error);
				}
			});
		}
	});
}());
