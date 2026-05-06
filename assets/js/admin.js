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
