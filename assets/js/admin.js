/* Bomedia Quote Wizard — admin helpers. */
(function () {
	'use strict';

	// Hide/show per-category product accordion when the category checkbox toggles.
	document.querySelectorAll('.bqw-cat-row').forEach(function (row) {
		var cb = row.querySelector('.bqw-cat-cb');
		var acc = row.querySelector('.bqw-cat-acc');
		if (!cb || !acc) return;
		cb.addEventListener('change', function () {
			acc.style.display = cb.checked ? '' : 'none';
			if (!cb.checked) acc.removeAttribute('open');
		});
	});

	// "Show all published" toggle: lock/unlock the per-product checkboxes.
	document.querySelectorAll('.bqw-cat-acc-body').forEach(function (body) {
		var modeCb = body.querySelector('.bqw-mode-cb');
		var prodCbs = body.querySelectorAll('.bqw-prod-cb');
		if (!modeCb) return;
		var apply = function () {
			prodCbs.forEach(function (c) {
				if (modeCb.checked) {
					c.checked = true;
					c.disabled = true;
				} else {
					c.disabled = false;
				}
			});
		};
		modeCb.addEventListener('change', apply);
	});

	// Generic HTML5 drag-and-drop reorder for [data-bqw-sortable] containers.
	document.querySelectorAll('[data-bqw-sortable]').forEach(function (list) {
		var dragged = null;
		list.querySelectorAll('[draggable="true"]').forEach(function (item) {
			item.addEventListener('dragstart', function (e) {
				dragged = item;
				item.classList.add('bqw-dragging');
				if (e.dataTransfer) {
					e.dataTransfer.effectAllowed = 'move';
					try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
				}
			});
			item.addEventListener('dragend', function () {
				item.classList.remove('bqw-dragging');
				dragged = null;
			});
			item.addEventListener('dragover', function (e) {
				if (!dragged || dragged === item) return;
				e.preventDefault();
				if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
				var rect = item.getBoundingClientRect();
				var midpoint = rect.top + rect.height / 2;
				if (e.clientY < midpoint) {
					list.insertBefore(dragged, item);
				} else {
					list.insertBefore(dragged, item.nextSibling);
				}
			});
			item.addEventListener('drop', function (e) { e.preventDefault(); });
		});
	});

	// Test connection button.
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
})();
