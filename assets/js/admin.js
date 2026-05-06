/* Bomedia Quote Wizard — admin: dual-list category picker + helpers. */
(function () {
	'use strict';

	var dual = document.getElementById('bqw-dual');
	if (dual) {
		initDualPicker(dual);
	}

	initTestConnection();

	/* =========================================================
	 * Dual list (Available ↔ Selected) with hierarchy + search.
	 * ========================================================= */
	function initDualPicker(root) {
		var search = root.querySelector('.bqw-dual-search');
		var emptyToggle = root.querySelector('.bqw-show-empty');
		var tree = root.querySelector('.bqw-dual-tree');
		var selectedList = root.querySelector('#bqw-selected-list');
		var emptyHint = root.querySelector('#bqw-empty-hint');
		var countEl = root.querySelector('#bqw-dual-count');
		var templates = document.getElementById('bqw-cat-templates');

		// Tree expand/collapse.
		tree.addEventListener('click', function (e) {
			var t = e.target;
			if (t.matches('.bqw-tree-toggle')) {
				if (t.classList.contains('is-leaf')) return;
				var node = t.closest('.bqw-tree-node');
				var kids = node.querySelector(':scope > .bqw-tree-children');
				if (!kids) return;
				kids.hidden = !kids.hidden;
				t.textContent = kids.hidden ? '▸' : '▾';
			} else if (t.matches('.bqw-tree-add')) {
				addCategory(parseInt(t.dataset.termId, 10));
			}
		});

		// Search filter.
		search.addEventListener('input', function () {
			filterTree(tree, search.value.trim().toLowerCase());
		});

		// Empty toggle.
		emptyToggle.addEventListener('change', function () {
			tree.classList.toggle('bqw-show-empty', emptyToggle.checked);
		});

		// Selected list: delegate clicks for remove/expand.
		selectedList.addEventListener('click', function (e) {
			var t = e.target;
			if (t.matches('.bqw-row-remove')) {
				var row = t.closest('.bqw-selected-row');
				removeCategory(row);
			} else if (t.matches('.bqw-row-toggle')) {
				var row = t.closest('.bqw-selected-row');
				var body = row.querySelector(':scope > .bqw-row-body');
				if (!body) return;
				body.hidden = !body.hidden;
				t.classList.toggle('is-open', !body.hidden);
				t.textContent = body.hidden ? '▸' : '▾';
			}
		});

		// Mode toggle (Todos los publicados ↔ Manual) — per row.
		// Drag inside product list with auto-switch to manual.
		bindRowBodyHandlers(selectedList);

		// Drag-and-drop for selected categories.
		makeSortable(selectedList, { onDrop: function () {} });

		// Initial counts and ordering.
		updateCount();

		function addCategory(termId) {
			if (!termId) return;
			// If a row for this term already exists in the selected list, do nothing.
			if (selectedList.querySelector('.bqw-selected-row[data-term-id="' + termId + '"]')) {
				markAdded(termId, true);
				return;
			}
			var tpl = templates && templates.querySelector('#bqw-cat-tpl-' + termId);
			if (!tpl) return;
			var fragment = tpl.content.cloneNode(true);
			selectedList.appendChild(fragment);
			markAdded(termId, true);
			bindRowBodyHandlers(selectedList);
			makeSortable(selectedList, { onDrop: function () {} });
			updateCount();
		}

		function removeCategory(row) {
			if (!row) return;
			var termId = parseInt(row.dataset.termId, 10);
			row.parentNode.removeChild(row);
			markAdded(termId, false);
			updateCount();
		}

		function markAdded(termId, isAdded) {
			var node = tree.querySelector('.bqw-tree-node[data-term-id="' + termId + '"]');
			if (!node) return;
			node.classList.toggle('is-added', isAdded);
			var btn = node.querySelector(':scope > .bqw-tree-row > .bqw-tree-add');
			if (btn) {
				btn.disabled = isAdded;
				btn.textContent = isAdded ? '✓ ' + label('Added', 'Añadido') : '+ ' + label('Add', 'Añadir');
			}
		}

		function updateCount() {
			var n = selectedList.querySelectorAll('.bqw-selected-row').length;
			countEl.textContent = n + ' ' + label('selected', 'seleccionadas');
			emptyHint.classList.toggle('is-hidden', n > 0);
		}
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

		// First pass: hide non-matches.
		nodes.forEach(function (n) {
			if ((n.dataset.name || '').indexOf(query) === -1) {
				n.classList.add('is-hidden');
			}
		});
		// Second pass: for each match, walk up ancestors to unhide and expand.
		var matches = tree.querySelectorAll('.bqw-tree-node:not(.is-hidden)');
		matches.forEach(function (n) {
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

	function bindRowBodyHandlers(selectedList) {
		selectedList.querySelectorAll('.bqw-row-body').forEach(function (body) {
			if (body.dataset.bqwBound === '1') return;
			body.dataset.bqwBound = '1';

			var modeCb = body.querySelector('.bqw-mode-cb');
			var prodList = body.querySelector('.bqw-cat-prod-list');
			var prodCbs = body.querySelectorAll('.bqw-prod-cb');

			if (modeCb) {
				modeCb.addEventListener('change', function () {
					prodCbs.forEach(function (c) {
						if (modeCb.checked) {
							c.checked = true;
							c.disabled = true;
						} else {
							c.disabled = false;
						}
					});
				});
			}

			if (prodList) {
				makeSortable(prodList, {
					onDrop: function () {
						// Auto-switch to manual when a drag happens in "Show all" mode.
						if (modeCb && modeCb.checked) {
							modeCb.checked = false;
							prodCbs.forEach(function (c) {
								c.checked = true;
								c.disabled = false;
							});
						}
					}
				});
			}
		});
	}

	/* =========================================================
	 * Generic HTML5 drag-and-drop reorder.
	 * Each direct [draggable=true] child is a sortable item.
	 * Stops propagation so nested sortables don't conflict.
	 * ========================================================= */
	function makeSortable(list, opts) {
		opts = opts || {};
		if (list.dataset.bqwSortableBound === '1') return;
		list.dataset.bqwSortableBound = '1';
		var dragged = null;
		list.addEventListener('dragstart', function (e) {
			var target = e.target;
			if (!target.matches || !target.matches(':scope > [draggable="true"]')) return;
			dragged = target;
			target.classList.add('bqw-dragging');
			if (e.dataTransfer) {
				e.dataTransfer.effectAllowed = 'move';
				try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
			}
			e.stopPropagation();
		});
		list.addEventListener('dragend', function (e) {
			if (dragged) dragged.classList.remove('bqw-dragging');
			var moved = dragged;
			dragged = null;
			if (moved && opts.onDrop) opts.onDrop();
			e.stopPropagation();
		});
		list.addEventListener('dragover', function (e) {
			if (!dragged) return;
			var over = e.target.closest(':scope > [draggable="true"]');
			// e.target.closest with :scope isn't ideal — fall back: walk up.
			if (!over) {
				var node = e.target;
				while (node && node !== list) {
					if (node.parentElement === list && node.draggable) { over = node; break; }
					node = node.parentElement;
				}
			}
			if (!over || over === dragged) return;
			e.preventDefault();
			if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
			var rect = over.getBoundingClientRect();
			var midpoint = rect.top + rect.height / 2;
			if (e.clientY < midpoint) {
				list.insertBefore(dragged, over);
			} else {
				list.insertBefore(dragged, over.nextSibling);
			}
			e.stopPropagation();
		});
		list.addEventListener('drop', function (e) { e.preventDefault(); e.stopPropagation(); });
	}

	/* =========================================================
	 * Test connection (existing).
	 * ========================================================= */
	function initTestConnection() {
		var btn = document.getElementById('bqw-test-connection');
		if (!btn) return;
		var result = document.getElementById('bqw-test-result');

		btn.addEventListener('click', function () {
			result.textContent = window.BQW_Admin.i18n.testing;
			result.style.color = '';
			var fd = new FormData();
			fd.append('action', 'bqw_test_connection');
			fd.append('nonce', window.BQW_Admin.nonce);

			fetch(window.BQW_Admin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: fd,
			})
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

	/* =========================================================
	 * Tiny i18n helper for in-JS labels (button text rebuilds).
	 * Falls back to English; uses Spanish if site language matches.
	 * ========================================================= */
	function label(en, es) {
		var L = (window.BQW_Admin && window.BQW_Admin.i18n) || {};
		// Use translated label if PHP sent it; otherwise pick by document lang.
		if (L[en]) return L[en];
		var lang = (document.documentElement.lang || '').toLowerCase();
		return lang.indexOf('es') === 0 ? es : en;
	}
})();
