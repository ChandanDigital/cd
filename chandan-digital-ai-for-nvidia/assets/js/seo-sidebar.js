/**
 * Chandan Digital AI for NVIDIA - "Open SEO Assistant" panel in the block editor's Post sidebar.
 *
 * The assistant itself is a meta box. Since WordPress 6.6 meta boxes live in a pane at the bottom
 * of the editor that starts collapsed, so this button opens the pane and scrolls to the box.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.plugins || !wp.element || !wp.components || !wp.data) {
		return;
	}
	var cfg = window.cdnvSeoSidebar || {};
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	if (!Panel) {
		return;
	}
	var h = wp.element.createElement;

	function openAssistant() {
		var prefs = wp.data.dispatch('core/preferences');
		if (prefs && prefs.set) {
			prefs.set('core/edit-post', 'metaBoxesMainIsOpen', true);
		}
		var tries = 0;
		(function reveal() {
			var box = document.getElementById('cdnv-seo');
			if (box && box.offsetHeight > 0) {
				box.scrollIntoView({ behavior: 'smooth', block: 'start' });
				var keyword = document.getElementById('cdnv-seo-keyword');
				if (keyword) {
					keyword.focus({ preventScroll: true });
				}
				return;
			}
			// The pane is rendered by React, so wait a moment for it to open.
			if (tries++ < 20) {
				window.setTimeout(reveal, 100);
			} else if (box) {
				box.scrollIntoView({ block: 'start' });
			}
		})();
	}

	// Open the panel the first time someone sees it in this browser; after that WordPress remembers
	// whether they keep it open or closed.
	try {
		if (!window.localStorage.getItem('cdnvSeoPanelSeen')) {
			window.localStorage.setItem('cdnvSeoPanelSeen', '1');
			var editor = wp.data.select('core/editor');
			var panel = 'cdnv-seo-assistant/cdnv-seo-assistant';
			if (editor && editor.isEditorPanelOpened && !editor.isEditorPanelOpened(panel)) {
				wp.data.dispatch('core/editor').toggleEditorPanelOpened(panel);
			}
		}
	} catch (e) {
		// Storage blocked: leave the panel as WordPress shows it.
	}

	wp.plugins.registerPlugin('cdnv-seo-assistant', {
		render: function () {
			return h(
				Panel,
				{ name: 'cdnv-seo-assistant', title: cfg.title || 'SEO Assistant' },
				h('p', null, cfg.text || ''),
				h(wp.components.Button, { variant: 'secondary', onClick: openAssistant, className: 'cdnv-seo-open' }, cfg.button || 'Open')
			);
		}
	});
})(window.wp);
