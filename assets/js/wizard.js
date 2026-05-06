/* Bomedia Quote Wizard — vanilla JS frontend. Multi-select edition. */
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

	// State.
	var state = {
		step: 1,
		currentCategory: null, // {id, slug, name}
		// selection: array of {id, name, image, sku, categoryId, categorySlug, categoryName}
		selection: [],
		unsure: false,
	};

	// In-memory cache: { [catId]: products[] }.
	var productsCache = {};

	/* ============================================================
	 * Selection helpers
	 * ============================================================ */
	function isSelected(id) {
		return state.selection.some(function (p) { return p.id === id; });
	}

	function addToSelection(p, cat) {
		if (state.unsure) {
			state.unsure = false;
		}
		if (isSelected(p.id)) return;
		state.selection.push({
			id: p.id,
			name: p.name,
			image: p.image || '',
			sku: p.sku || '',
			attributes: p.attributes || [],
			categoryId: cat.id,
			categorySlug: cat.slug,
			categoryName: cat.name,
		});
		updateSelectionUI();
	}

	function removeFromSelection(id) {
		state.selection = state.selection.filter(function (p) { return p.id !== id; });
		updateSelectionUI();
		// Also un-tick the matching product card if visible.
		var card = document.querySelector('.bqw-product-card[data-product-id="' + id + '"]');
		if (card) card.classList.remove('is-selected');
	}

	function setUnsure(on) {
		state.unsure = !!on;
		if (state.unsure) {
			state.selection = [];
			document.querySelectorAll('.bqw-product-card.is-selected').forEach(function (el) {
				el.classList.remove('is-selected');
			});
		}
		updateSelectionUI();
	}

	function updateSelectionUI() {
		// Hidden form values.
		document.getElementById('bqw-selected-products-json').value = JSON.stringify(state.selection.map(function (p) {
			return { id: p.id, name: p.name, sku: p.sku, categoryId: p.categoryId, categorySlug: p.categorySlug, categoryName: p.categoryName };
		}));
		document.getElementById('bqw-unsure').value = state.unsure ? '1' : '0';

		// Selection strip visibility.
		var strip = document.getElementById('bqw-selection-strip');
		var avatars = document.getElementById('bqw-selection-avatars');
		var countEl = document.getElementById('bqw-selection-count');

		if (!state.selection.length) {
			strip.hidden = true;
		} else {
			strip.hidden = false;
			countEl.textContent = (state.selection.length === 1
				? (i18n.selectedOne || 'You picked 1 machine')
				: (i18n.selectedMany || 'You picked %d machines').replace('%d', state.selection.length));
			avatars.innerHTML = '';
			var visible = state.selection.slice(0, 5);
			visible.forEach(function (p) {
				var img = document.createElement('span');
				img.className = 'bqw-avatar';
				img.title = p.name;
				if (p.image) {
					img.style.backgroundImage = 'url(' + p.image + ')';
				} else {
					img.textContent = (p.name || '?').slice(0, 2).toUpperCase();
					img.classList.add('bqw-avatar-text');
				}
				avatars.appendChild(img);
			});
			if (state.selection.length > 5) {
				var more = document.createElement('span');
				more.className = 'bqw-avatar bqw-avatar-more';
				more.textContent = '+' + (state.selection.length - 5);
				avatars.appendChild(more);
			}
		}

		// Next button state.
		document.getElementById('bqw-next-1').disabled = !(state.selection.length || state.unsure);
	}

	/* ============================================================
	 * Step 1 — categories / products
	 * ============================================================ */
	function renderCategories() {
		var wrap = document.getElementById('bqw-step1-categories');
		wrap.innerHTML = '';

		if (cfg.forced_product) {
			addToSelection(cfg.forced_product, { id: 0, slug: '', name: '' });
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
			card.addEventListener('click', function () { showProducts(cat); });
			grid.appendChild(card);
		});
		wrap.appendChild(grid);
	}

	function showProducts(cat) {
		state.currentCategory = cat;
		var prodWrap = document.getElementById('bqw-step1-products');
		var catWrap = document.getElementById('bqw-step1-categories');
		var title = document.getElementById('bqw-products-title');
		var body = document.getElementById('bqw-products-body');

		title.textContent = (i18n.modelOf || 'Model of') + ' ' + cat.name;
		catWrap.hidden = true;
		prodWrap.hidden = false;

		if (productsCache[cat.id]) {
			renderProductsList(productsCache[cat.id], body, cat);
		} else {
			body.innerHTML = '<p class="bqw-loading">' + escapeHtml(i18n.loading || 'Loading…') + '</p>';
			fetchProducts(cat.id, body, cat);
		}
	}

	function fetchProducts(catId, body, cat) {
		var fd = new FormData();
		fd.append('action', 'bqw_get_products_by_category');
		fd.append('nonce', window.BQW.nonce);
		fd.append('category_id', String(catId));
		fetch(window.BQW.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				var products = (json && json.success && json.data && json.data.products) || [];
				productsCache[catId] = products;
				renderProductsList(products, body, cat);
			})
			.catch(function () { renderProductsList([], body, cat); });
	}

	function renderProductsList(products, body, cat) {
		body.innerHTML = '';

		if (!products.length) {
			var msg = document.createElement('p');
			msg.className = 'bqw-empty';
			msg.textContent = i18n.noProducts || 'No machines available.';
			body.appendChild(msg);
			if (cfg.fallback_email) {
				var a = document.createElement('a');
				a.className = 'bqw-btn bqw-btn-primary';
				a.href = 'mailto:' + cfg.fallback_email;
				a.textContent = i18n.contactUs || 'Contact us';
				body.appendChild(a);
			}
			return;
		}

		var grid = document.createElement('div');
		grid.className = 'bqw-cards';

		products.forEach(function (p) {
			var card = buildProductCard(p);
			card.dataset.productId = String(p.id);
			if (isSelected(p.id)) card.classList.add('is-selected');
			card.addEventListener('click', function () {
				var nowSelected = !card.classList.contains('is-selected');
				if (nowSelected) {
					card.classList.add('is-selected');
					addToSelection(p, cat);
				} else {
					card.classList.remove('is-selected');
					removeFromSelection(p.id);
				}
			});
			grid.appendChild(card);
		});

		// "I'm not sure" — mutually exclusive with picks.
		var unsure = buildCard('', i18n.unsure || "I'm not sure");
		unsure.classList.add('bqw-unsure-card');
		if (state.unsure) unsure.classList.add('is-selected');
		unsure.addEventListener('click', function () {
			var on = !unsure.classList.contains('is-selected');
			document.querySelectorAll('.bqw-unsure-card').forEach(function (el) {
				el.classList.toggle('is-selected', on);
			});
			setUnsure(on);
		});
		grid.appendChild(unsure);

		body.appendChild(grid);
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

	function buildProductCard(p) {
		var card = buildCard(p.image, p.name);
		card.classList.add('bqw-product-card');
		var check = document.createElement('span');
		check.className = 'bqw-card-check';
		check.setAttribute('aria-hidden', 'true');
		check.textContent = '✓';
		card.appendChild(check);
		if (p.attributes && p.attributes.length) {
			var attrs = document.createElement('span');
			attrs.className = 'bqw-card-attrs';
			p.attributes.forEach(function (a) {
				var line = document.createElement('span');
				line.className = 'bqw-card-attr';
				line.textContent = a.label + ': ' + a.value;
				attrs.appendChild(line);
			});
			card.appendChild(attrs);
		}
		return card;
	}

	function backToCategories() {
		document.getElementById('bqw-step1-products').hidden = true;
		document.getElementById('bqw-step1-categories').hidden = false;
	}

	/* ============================================================
	 * Selection modal
	 * ============================================================ */
	function openSelectionModal() {
		var modal = document.getElementById('bqw-selection-modal');
		var list = document.getElementById('bqw-selection-modal-list');
		list.innerHTML = '';
		if (!state.selection.length) {
			var empty = document.createElement('li');
			empty.className = 'bqw-modal-empty';
			empty.textContent = i18n.noSelection || 'No machines selected.';
			list.appendChild(empty);
		} else {
			state.selection.forEach(function (p) {
				var li = document.createElement('li');
				li.className = 'bqw-modal-item';
				if (p.image) {
					var im = document.createElement('img');
					im.src = p.image; im.alt = p.name;
					li.appendChild(im);
				}
				var info = document.createElement('div');
				info.className = 'bqw-modal-item-info';
				var n = document.createElement('strong');
				n.textContent = p.name;
				info.appendChild(n);
				if (p.categoryName) {
					var c = document.createElement('span');
					c.className = 'bqw-modal-item-cat';
					c.textContent = p.categoryName;
					info.appendChild(c);
				}
				li.appendChild(info);
				var rm = document.createElement('button');
				rm.type = 'button';
				rm.className = 'bqw-modal-item-remove';
				rm.setAttribute('aria-label', (i18n.remove || 'Remove') + ' ' + p.name);
				rm.textContent = '×';
				rm.addEventListener('click', function () {
					removeFromSelection(p.id);
					openSelectionModal(); // refresh
				});
				li.appendChild(rm);
				list.appendChild(li);
			});
		}
		modal.hidden = false;
	}

	function closeSelectionModal() {
		document.getElementById('bqw-selection-modal').hidden = true;
	}

	/* ============================================================
	 * Country select
	 * ============================================================ */
	function buildCountries() {
		var sel = document.getElementById('bqw-country');
		var dial = document.getElementById('bqw-dial');
		countries.forEach(function (c) {
			var opt = document.createElement('option');
			opt.value = c.code;
			opt.textContent = c.name;
			opt.dataset.dial = c.dial;
			if (c.code === detected) opt.selected = true;
			sel.appendChild(opt);
		});
		var match = countries.find(function (c) { return c.code === detected; });
		if (match) dial.textContent = match.dial;
		sel.addEventListener('change', function () {
			var opt = sel.options[sel.selectedIndex];
			dial.textContent = opt ? (opt.dataset.dial || '+') : '+';
		});
	}

	/* ============================================================
	 * Step navigation
	 * ============================================================ */
	function goToStep(n) {
		root.querySelectorAll('.bqw-step').forEach(function (s) {
			var step = parseInt(s.dataset.step, 10);
			s.hidden = step !== n;
			s.classList.toggle('is-active', step === n);
		});
		root.querySelectorAll('.bqw-progress-step').forEach(function (d) {
			var step = parseInt(d.dataset.step, 10);
			d.classList.toggle('is-active', step === n);
			d.classList.toggle('is-done', step < n);
		});
		state.step = n;
		var heading = root.querySelector('.bqw-step:not([hidden]) .bqw-step-title');
		if (heading) {
			heading.setAttribute('tabindex', '-1');
			try { heading.focus({ preventScroll: false }); } catch (e) { heading.focus(); }
		}
		if (n === 4) renderSummary();
	}

	function validateStep2() {
		var fs = document.getElementById('bqw-application-fieldset');
		var err = document.getElementById('bqw-application-error');
		if (!fs || !err) return true;
		var checked = fs.querySelectorAll('input[name="application[]"]:checked').length;
		if (cfg.enable_application && checked < 1) {
			err.hidden = false;
			fs.classList.add('bqw-has-error');
			return false;
		}
		err.hidden = true;
		fs.classList.remove('bqw-has-error');
		return true;
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

	/* ============================================================
	 * Summary (step 4)
	 * ============================================================ */
	function renderSummary() {
		var box = document.getElementById('bqw-summary');
		var rows = [];
		function add(k, v) {
			if (v) rows.push('<dt>' + escapeHtml(k) + '</dt><dd>' + escapeHtml(v) + '</dd>');
		}

		if (state.unsure) {
			add(i18n.machinesOfInterest || 'Machines of interest', i18n.unsure || "I'm not sure");
		} else if (state.selection.length) {
			add(i18n.machinesOfInterest || 'Machines of interest', state.selection.map(function (p) { return p.name; }).join(', '));
		}

		var apps = Array.from(form.querySelectorAll('input[name="application[]"]:checked')).map(function (i) { return i.value; });
		if (apps.length) add(i18n.application || 'Application', apps.join(', '));

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

	/* ============================================================
	 * Submit
	 * ============================================================ */
	function submit(e) {
		e.preventDefault();
		errorBox.hidden = true;

		var btn = document.getElementById('bqw-submit');
		btn.disabled = true;
		btn.querySelector('.bqw-spinner').hidden = false;

		// Refresh hidden field with latest selection.
		document.getElementById('bqw-selected-products-json').value = JSON.stringify(state.selection.map(function (p) {
			return { id: p.id, name: p.name, sku: p.sku, categoryId: p.categoryId, categorySlug: p.categorySlug, categoryName: p.categoryName };
		}));
		document.getElementById('bqw-unsure').value = state.unsure ? '1' : '0';

		var fd = new FormData(form);
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

	/* ============================================================
	 * Init
	 * ============================================================ */
	function init() {
		buildCountries();
		renderCategories();
		updateSelectionUI();

		root.querySelectorAll('[data-next]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var target = parseInt(btn.dataset.next, 10);
				if (state.step === 2 && !validateStep2()) return;
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
		if (backCats) backCats.addEventListener('click', backToCategories);

		var addMore = document.getElementById('bqw-add-from-other');
		if (addMore) addMore.addEventListener('click', backToCategories);

		var editBtn = document.getElementById('bqw-selection-edit');
		if (editBtn) editBtn.addEventListener('click', openSelectionModal);

		document.getElementById('bqw-selection-modal').addEventListener('click', function (e) {
			if (e.target.dataset && e.target.dataset.close === '1') closeSelectionModal();
		});

		form.addEventListener('submit', submit);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
