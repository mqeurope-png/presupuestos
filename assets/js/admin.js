/* Bomedia Quote Wizard — admin: dual-list category picker. */
(function ($) {
	'use strict';

	$(function () {
		var $dual = $('#bqw-dual');
		if ($dual.length) initDualPicker($dual);
		initTestConnection();
		initOpenAITest();
		initMediaPickers();
	});

	function initOpenAITest() {
		var btn = document.getElementById('bqw-openai-test');
		if (!btn) return;
		var result = document.getElementById('bqw-openai-test-result');
		btn.addEventListener('click', function () {
			result.textContent = (window.BQW_Admin && window.BQW_Admin.i18n.testing) || 'Testing…';
			result.style.color = '';
			var fd = new FormData();
			fd.append('action', 'bqw_test_openai');
			fd.append('nonce', window.BQW_Admin.nonce);
			fetch(window.BQW_Admin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json && json.success) {
						result.textContent = '✓ ' + (json.data && json.data.message ? json.data.message : 'OK');
						result.style.color = '#46b450';
					} else {
						var msg = (json && json.data && json.data.message) || 'Error';
						result.textContent = '✗ ' + msg;
						result.style.color = '#dc3232';
					}
				})
				.catch(function (e) {
					result.textContent = '✗ ' + e;
					result.style.color = '#dc3232';
				});
		});
	}

	/* =========================================================
	 * Media library pickers (hero image, option images, …)
	 * ========================================================= */
	function initMediaPickers() {
		if (typeof wp === 'undefined' || !wp.media) return;
		$(document).on('click', '.bqw-media-pick', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.bqw-media-picker');
			var frame = wp.media({
				title: 'Select image',
				multiple: false,
				library: { type: 'image' },
				button: { text: 'Use this image' }
			});
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				$wrap.find('.bqw-media-id').val(att.id);
				var $img = $wrap.find('.bqw-media-preview');
				$img.attr('src', (att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url)).show();
				$wrap.find('.bqw-media-clear').show();
			});
			frame.open();
		});
		$(document).on('click', '.bqw-media-clear', function (e) {
			e.preventDefault();
			var $wrap = $(this).closest('.bqw-media-picker');
			$wrap.find('.bqw-media-id').val('0');
			$wrap.find('.bqw-media-preview').hide().attr('src', '');
			$(this).hide();
		});
	}

	/* =========================================================
	 * Dual list (Available ↔ Selected) with hierarchy + search.
	 * ========================================================= */
	function initDualPicker($root) {
		var $tree = $root.find('.bqw-dual-tree');
		var $search = $root.find('.bqw-dual-search');
		var $emptyToggle = $root.find('.bqw-show-empty');
		var $selectedList = $('#bqw-selected-list');
		var $emptyHint = $('#bqw-empty-hint');
		var $countEl = $('#bqw-dual-count');
		var $templates = $('#bqw-cat-templates');

		// Tree expand/collapse + Add button.
		$tree.on('click', '.bqw-tree-toggle', function () {
			var $t = $(this);
			if ($t.hasClass('is-leaf')) return;
			var $kids = $t.closest('.bqw-tree-node').children('.bqw-tree-children');
			if (!$kids.length) return;
			var hidden = $kids.prop('hidden');
			$kids.prop('hidden', !hidden);
			$t.text(hidden ? '▾' : '▸');
		});

		$tree.on('click', '.bqw-tree-add', function () {
			addCategory(parseInt($(this).data('term-id'), 10));
		});

		// Search.
		$search.on('input', function () {
			filterTree($tree[0], $.trim(this.value).toLowerCase());
		});

		// Empty toggle.
		$emptyToggle.on('change', function () {
			$tree.toggleClass('bqw-show-empty', this.checked);
		});

		// Selected list: remove + expand.
		$selectedList.on('click', '.bqw-row-remove', function () {
			removeCategory($(this).closest('.bqw-selected-row'));
		});
		$selectedList.on('click', '.bqw-row-toggle', function () {
			var $btn = $(this);
			var $row = $btn.closest('.bqw-selected-row');
			var $body = $row.children('.bqw-row-body');
			var hidden = $body.prop('hidden');
			$body.prop('hidden', !hidden);
			$btn.toggleClass('is-open', hidden).text(hidden ? '▾' : '▸');
		});

		// Initial bindings for already-rendered rows.
		bindRowBodies($selectedList);
		makeCategorySortable($selectedList);

		updateCount();

		function addCategory(termId) {
			if (!termId) return;
			if ($selectedList.find('.bqw-selected-row[data-term-id="' + termId + '"]').length) {
				markAdded(termId, true);
				return;
			}
			var $tpl = $templates.find('#bqw-cat-tpl-' + termId);
			if (!$tpl.length) return;
			var html = $tpl[0].innerHTML; // template inner HTML.
			$selectedList.append(html);
			markAdded(termId, true);
			bindRowBodies($selectedList);
			// Re-init sortable so new row participates.
			if ($selectedList.hasClass('ui-sortable')) {
				$selectedList.sortable('refresh');
			}
			updateCount();
		}

		function removeCategory($row) {
			if (!$row.length) return;
			var termId = parseInt($row.data('term-id'), 10);
			$row.remove();
			markAdded(termId, false);
			updateCount();
		}

		function markAdded(termId, isAdded) {
			var $node = $tree.find('.bqw-tree-node[data-term-id="' + termId + '"]');
			$node.toggleClass('is-added', isAdded);
			var $btn = $node.children('.bqw-tree-row').children('.bqw-tree-add');
			$btn.prop('disabled', isAdded);
			$btn.text(isAdded ? '✓ ' + label('Added', 'Añadido') : '+ ' + label('Add', 'Añadir'));
		}

		function updateCount() {
			var n = $selectedList.children('.bqw-selected-row').length;
			$countEl.text(n + ' ' + label('selected', 'seleccionadas'));
			$emptyHint.toggleClass('is-hidden', n > 0);
		}
	}

	function bindRowBodies($list) {
		$list.find('.bqw-row-body').each(function () {
			var $body = $(this);
			if ($body.data('bqwBound')) return;
			$body.data('bqwBound', true);

			var $modeCb = $body.find('.bqw-mode-cb');
			var $prodList = $body.find('.bqw-cat-prod-list');
			var $prodCbs = $body.find('.bqw-prod-cb');

			$modeCb.on('change', function () {
				if (this.checked) {
					$prodCbs.prop('checked', true).prop('disabled', true);
				} else {
					$prodCbs.prop('disabled', false);
				}
			});

			if ($prodList.length) {
				makeProductSortable($prodList, $modeCb, $prodCbs);
			}
		});
	}

	function makeCategorySortable($list) {
		if (!$.fn.sortable) return;
		$list.sortable({
			handle: '.bqw-drag-handle',
			items: '> .bqw-selected-row',
			placeholder: 'bqw-sort-placeholder',
			tolerance: 'pointer',
			axis: 'y',
			helper: 'clone',
			forcePlaceholderSize: true,
			start: function (e, ui) { ui.item.addClass('bqw-dragging'); },
			stop: function (e, ui) { ui.item.removeClass('bqw-dragging'); },
		});
	}

	function makeProductSortable($list, $modeCb, $prodCbs) {
		if (!$.fn.sortable) return;
		$list.sortable({
			handle: '.bqw-drag-handle-prod',
			items: '> li',
			placeholder: 'bqw-sort-placeholder',
			tolerance: 'pointer',
			axis: 'y',
			helper: 'clone',
			forcePlaceholderSize: true,
			start: function (e, ui) { ui.item.addClass('bqw-dragging'); },
			stop: function (e, ui) {
				ui.item.removeClass('bqw-dragging');
				// Auto-switch to manual when a drag happens in "Show all" mode.
				if ($modeCb.length && $modeCb[0].checked) {
					$modeCb.prop('checked', false);
					$prodCbs.prop('checked', true).prop('disabled', false);
				}
			},
		});
	}

	function filterTree(tree, query) {
		var nodes = tree.querySelectorAll('.bqw-tree-node');
		nodes.forEach(function (n) {
			n.classList.remove('is-hidden');
			var children = n.querySelector(':scope > .bqw-tree-children');
			if (children) children.hidden = true;
			var toggle = n.querySelector(':scope > .bqw-tree-row > .bqw-tree-toggle');
			if (toggle && !toggle.classList.contains('is-leaf')) toggle.textContent = '▸';
		});
		if (!query) return;

		nodes.forEach(function (n) {
			if ((n.dataset.name || '').indexOf(query) === -1) n.classList.add('is-hidden');
		});
		tree.querySelectorAll('.bqw-tree-node:not(.is-hidden)').forEach(function (n) {
			var p = n.parentElement;
			while (p && !p.classList.contains('bqw-tree-root')) {
				if (p.classList.contains('bqw-tree-children')) {
					p.hidden = false;
					var parentNode = p.parentElement;
					if (parentNode && parentNode.classList.contains('bqw-tree-node')) {
						parentNode.classList.remove('is-hidden');
						var t = parentNode.querySelector(':scope > .bqw-tree-row > .bqw-tree-toggle');
						if (t && !t.classList.contains('is-leaf')) t.textContent = '▾';
					}
				}
				p = p.parentElement;
			}
		});
	}

	/* =========================================================
	 * Test connection (existing).
	 * ========================================================= */
	function initTestConnection() {
		var btn = document.getElementById('bqw-test-connection');
		if (!btn) return;
		var result = document.getElementById('bqw-test-result');

		$(btn).on('click', function () {
			result.textContent = window.BQW_Admin.i18n.testing;
			result.style.color = '';
			var fd = new FormData();
			fd.append('action', 'bqw_test_connection');
			fd.append('nonce', window.BQW_Admin.nonce);
			fetch(window.BQW_Admin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json && json.success) {
						result.textContent = '✓ ' + window.BQW_Admin.i18n.ok;
						result.style.color = '#46b450';
					} else {
						var msg = (json && json.data && json.data.message) || 'Error';
						result.textContent = '✗ ' + window.BQW_Admin.i18n.fail + msg;
						result.style.color = '#dc3232';
					}
				})
				.catch(function (e) {
					result.textContent = '✗ ' + window.BQW_Admin.i18n.fail + e;
					result.style.color = '#dc3232';
				});
		});
	}

	function label(en, es) {
		var L = (window.BQW_Admin && window.BQW_Admin.i18n) || {};
		if (L[en]) return L[en];
		var lang = (document.documentElement.lang || '').toLowerCase();
		return lang.indexOf('es') === 0 ? es : en;
	}
})(jQuery);
