/* Bomedia Quote Wizard — vanilla JS frontend. */
(function () {
	'use strict';

	if (typeof window.BQW === 'undefined') return;

	var root = document.getElementById('bqw-wizard');
	if (!root) return;

	var cfg = window.BQW.config || {};
	var i18n = window.BQW.i18n || {};
	var countries = window.BQW.countries || [];
	var detected = window.BQW.detectedCountry || 'ES';

	var form = document.getElementById('bqw-form');
	var thanksBox = document.getElementById('bqw-thanks');
	var errorBox = document.getElementById('bqw-error');

	var state = {
		step: 1,
		categoryId: 0,
		categorySlug: '',
		categoryName: '',
		productId: 0,
		productName: '',
	};

	// ---------- Step 1: categories / products ----------
	function renderCategories() {
		var wrap = document.getElementById('bqw-step1-categories');
		wrap.innerHTML = '';

		// If a category is forced and there are no others, skip directly to products.
		if (cfg.forced_product) {
			selectProduct(cfg.forced_product);
			goToStep(2);
			return;
		}

		if (cfg.forced_category_id && cfg.categories.length === 1) {
			showProducts(cfg.categories[0]);
			return;
		}

		var grid = document.createElement('div');
		grid.className = 'bqw-cards';
		cfg.categories.forEach(function (cat) {
			var card = buildCard(cat.image, cat.name);
			card.addEventListener('click', function () {
				showProducts(cat);
			});
			grid.appendChild(card);
		});
		wrap.appendChild(grid);
	}

	function showProducts(cat) {
		state.categoryId = cat.id;
		state.categorySlug = cat.slug;
		state.categoryName = cat.name;
		document.getElementById('bqw-category-id').value = cat.id;
		document.getElementById('bqw-category-slug').value = cat.slug;
		document.getElementById('bqw-category-name').value = cat.name;

		var products = (cfg.products_by_cat && cfg.products_by_cat[cat.id]) || [];
		var prodWrap = document.getElementById('bqw-step1-products');
		var catWrap = document.getElementById('bqw-step1-categories');
		var backBtn = document.getElementById('bqw-back-to-categories');

		prodWrap.innerHTML = '';
		var grid = document.createElement('div');
		grid.className = 'bqw-cards';
		products.forEach(function (p) {
			var card = buildCard(p.image, p.name);
			card.addEventListener('click', function () {
				clearSelected(grid);
				card.classList.add('is-selected');
				selectProduct(p);
				document.getElementById('bqw-next-1').disabled = false;
			});
			grid.appendChild(card);
		});

		// Unsure card
		var unsure = buildCard('', i18n.unsure || "I'm not sure");
		unsure.addEventListener('click', function () {
			clearSelected(grid);
			unsure.classList.add('is-selected');
			selectProduct({ id: 0, name: '' });
			document.getElementById('bqw-next-1').disabled = false;
		});
		grid.appendChild(unsure);

		prodWrap.appendChild(grid);
		prodWrap.hidden = false;
		catWrap.hidden = true;

		// Show "back to categories" only if there are multiple categories.
		if (cfg.categories.length > 1 && !cfg.forced_category_id) {
			backBtn.hidden = false;
		}
	}

	function selectProduct(p) {
		state.productId = p.id || 0;
		state.productName = p.name || '';
		document.getElementById('bqw-product-id').value = state.productId;
		document.getElementById('bqw-product-name').value = state.productName;
	}

	function buildCard(img, name) {
		var card = document.createElement('button');
		card.type = 'button';
		card.className = 'bqw-card';
		if (img) {
			var im = document.createElement('img');
			im.className = 'bqw-card-img';
			im.src = img;
			im.alt = name;
			im.loading = 'lazy';
			card.appendChild(im);
		}
		var label = document.createElement('span');
		label.className = 'bqw-card-name';
		label.textContent = name;
		card.appendChild(label);
		return card;
	}

	function clearSelected(grid) {
		grid.querySelectorAll('.is-selected').forEach(function (el) {
			el.classList.remove('is-selected');
		});
	}

	// ---------- Country select ----------
	function buildCountries() {
		var sel = document.getElementById('bqw-country');
		var dial = document.getElementById('bqw-dial');
		var preferred = detected;
		countries.forEach(function (c) {
			var opt = document.createElement('option');
			opt.value = c.code;
			opt.textContent = c.name;
			opt.dataset.dial = c.dial;
			if (c.code === preferred) opt.selected = true;
			sel.appendChild(opt);
		});
		var match = countries.find(function (c) { return c.code === preferred; });
		if (match) dial.textContent = match.dial;
		sel.addEventListener('change', function () {
			var opt = sel.options[sel.selectedIndex];
			dial.textContent = opt ? (opt.dataset.dial || '+') : '+';
		});
	}

	// ---------- Step navigation ----------
	function goToStep(n) {
		var steps = root.querySelectorAll('.bqw-step');
		steps.forEach(function (s) {
			var step = parseInt(s.dataset.step, 10);
			s.hidden = step !== n;
			s.classList.toggle('is-active', step === n);
		});
		var dots = root.querySelectorAll('.bqw-progress-step');
		dots.forEach(function (d) {
			var step = parseInt(d.dataset.step, 10);
			d.classList.toggle('is-active', step === n);
			d.classList.toggle('is-done', step < n);
		});
		state.step = n;
		// Move focus to step heading.
		var heading = root.querySelector('.bqw-step:not([hidden]) .bqw-step-title');
		if (heading) {
			heading.setAttribute('tabindex', '-1');
			heading.focus({ preventScroll: false });
		}
		// Render summary if entering step 4.
		if (n === 4) renderSummary();
	}

	function validateStep3() {
		var ok = true;
		var requiredIds = ['bqw-first-name', 'bqw-last-name', 'bqw-company', 'bqw-email', 'bqw-phone', 'bqw-country'];
		requiredIds.forEach(function (id) {
			var el = document.getElementById(id);
			var field = el.closest('.bqw-field');
			if (!el.value.trim()) {
				ok = false;
				field.classList.add('bqw-has-error');
				ensureFieldError(field, i18n.requiredField || 'Required.');
			} else {
				field.classList.remove('bqw-has-error');
				removeFieldError(field);
			}
		});
		var emailEl = document.getElementById('bqw-email');
		if (emailEl.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailEl.value)) {
			ok = false;
			var f = emailEl.closest('.bqw-field');
			f.classList.add('bqw-has-error');
			ensureFieldError(f, i18n.invalidEmail || 'Invalid email.');
		}
		var privacy = document.getElementById('bqw-privacy');
		var pField = privacy.closest('.bqw-field');
		if (!privacy.checked) {
			ok = false;
			pField.classList.add('bqw-has-error');
			ensureFieldError(pField, i18n.requiredField || 'Required.');
		} else {
			pField.classList.remove('bqw-has-error');
			removeFieldError(pField);
		}
		return ok;
	}

	function ensureFieldError(field, msg) {
		var existing = field.querySelector('.bqw-field-error');
		if (!existing) {
			existing = document.createElement('div');
			existing.className = 'bqw-field-error';
			field.appendChild(existing);
		}
		existing.textContent = msg;
	}
	function removeFieldError(field) {
		var ex = field.querySelector('.bqw-field-error');
		if (ex) ex.remove();
	}

	// ---------- Summary ----------
	function renderSummary() {
		var box = document.getElementById('bqw-summary');
		var rows = [];
		function add(k, v) {
			if (v) rows.push('<dt>' + escapeHtml(k) + '</dt><dd>' + escapeHtml(v) + '</dd>');
		}

		add(i18n.pickCategory || 'Category', state.categoryName);
		add(i18n.pickProduct || 'Model', state.productName);

		var app = (form.querySelector('input[name="application"]:checked') || {}).value;
		add(i18n.application || 'Application', app);

		var mats = Array.from(form.querySelectorAll('input[name="materials[]"]:checked')).map(function (i) { return i.value; });
		if (mats.length) add(i18n.materials || 'Materials', mats.join(', '));

		var vol = (form.querySelector('input[name="volume"]:checked') || {}).value;
		add(i18n.volume || 'Monthly volume', vol);

		add(i18n.firstName || 'First name', form.first_name.value + ' ' + form.last_name.value);
		add(i18n.company || 'Company', form.company.value);
		add(i18n.email || 'Email', form.email.value);
		var dial = document.getElementById('bqw-dial').textContent;
		add(i18n.phone || 'Phone', dial + ' ' + form.phone.value);
		add(i18n.country || 'Country', form.country.options[form.country.selectedIndex].textContent);
		if (form.message.value) add(i18n.message || 'Message', form.message.value);

		box.innerHTML = '<dl>' + rows.join('') + '</dl>';
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// ---------- Submit ----------
	function submit(e) {
		e.preventDefault();
		errorBox.hidden = true;

		var btn = document.getElementById('bqw-submit');
		btn.disabled = true;
		btn.querySelector('.bqw-spinner').hidden = false;

		var fd = new FormData(form);
		// Prefix phone with dial code.
		var dial = document.getElementById('bqw-dial').textContent.trim();
		fd.set('phone', dial + ' ' + (fd.get('phone') || ''));

		fetch(window.BQW.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success) {
					form.style.display = 'none';
					var progress = root.querySelector('.bqw-progress');
					if (progress) progress.style.display = 'none';
					thanksBox.hidden = false;
					thanksBox.innerHTML = json.data.html || '';
					thanksBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
				} else {
					var msg = (json && json.data && json.data.message) || i18n.genericError || 'Error';
					errorBox.textContent = msg;
					errorBox.hidden = false;
				}
			})
			.catch(function () {
				errorBox.textContent = i18n.genericError || 'Error';
				errorBox.hidden = false;
			})
			.finally(function () {
				btn.disabled = false;
				btn.querySelector('.bqw-spinner').hidden = true;
			});
	}

	// ---------- Init ----------
	function init() {
		buildCountries();
		renderCategories();

		// Step navigation buttons.
		root.querySelectorAll('[data-next]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var target = parseInt(btn.dataset.next, 10);
				if (state.step === 3 && !validateStep3()) return;
				goToStep(target);
			});
		});
		root.querySelectorAll('[data-prev]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				goToStep(parseInt(btn.dataset.prev, 10));
			});
		});

		document.getElementById('bqw-next-1').addEventListener('click', function () {
			goToStep(2);
		});

		var backCats = document.getElementById('bqw-back-to-categories');
		if (backCats) {
			backCats.addEventListener('click', function () {
				document.getElementById('bqw-step1-categories').hidden = false;
				document.getElementById('bqw-step1-products').hidden = true;
				backCats.hidden = true;
				selectProduct({ id: 0, name: '' });
				state.categoryId = 0;
				state.categoryName = '';
				state.categorySlug = '';
				document.getElementById('bqw-next-1').disabled = true;
			});
		}

		form.addEventListener('submit', submit);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
