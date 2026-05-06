/* Bomedia Quote Wizard — admin AJAX helpers. */
(function () {
	'use strict';
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
