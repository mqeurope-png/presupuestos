/* Bomedia Quote Wizard — frontend (v1.4). */
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
	var hero = document.getElementById('bqw-hero');
	var progressWrap = document.getElementById('bqw-progress');
	var progressFill = document.getElementById('bqw-progress-fill');
	var progressPct  = document.getElementById('bqw-progress-pct');
	var thanksBox = document.getElementById('bqw-thanks');
	var errorBox  = null; // resolved per step.
	var microcopyEl = document.getElementById('bqw-microcopy');

	var microcopyMsgs = parseJSON(root.dataset.microcopy) || [];
	var microcopyOn   = root.dataset.microcopyOn === '1';
	var matchmakerOn  = root.dataset.matchmakerOn === '1';
	var redirectURL   = root.dataset.redirect || '';

	var state = {
		flow: 'classic',          // 'classic' | 'matchmaker'
		step: null,               // current data-step value
		// classic state
		currentCategory: null,
		selection: [],
		unsure: false,
		// matchmaker state
		mmAnswers: { application: [], materials: [], volume: '', format: '', budget: '' },
		mmRecommendations: [],
	};
	var productsCache = {};

	/* =============================================================
	 * Step navigation map
	 * ============================================================= */

	function classicSteps()    { return ['1', '2', '3', '4']; }
	function matchmakerSteps() { return ['m1', 'm2', 'm3', 'm4', 'm5', 'rec', '3', '4']; }

	function currentSteps() { return state.flow === 'matchmaker' ? matchmakerSteps() : classicSteps(); }

	function goToStep(step) {
		root.querySelectorAll('.bqw-step').forEach(function (s) {
			s.hidden = s.dataset.step !== step;
		});
		state.step = step;
		updateProgress();
		var headEl = root.querySelector('.bqw-step:not([hidden]) .bqw-step-title');
		if (headEl) {
			headEl.setAttribute('tabindex', '-1');
			try { headEl.focus({ preventScroll: false }); } catch (e) { headEl.focus(); }
		}
		errorBox = root.querySelector('.bqw-step:not([hidden]) .bqw-error');
		if (step === '4') renderSummary();
	}

	function updateProgress() {
		if (!progressFill || !progressPct) return;
		var steps = currentSteps();
		var idx = steps.indexOf(state.step);
		var pct = idx < 0 ? 0 : Math.round(((idx + 1) / steps.length) * 100);
		progressFill.style.width = pct + '%';
		progressPct.textContent = pct + '%';
	}

	function showMicrocopy(idx) {
		if (!microcopyOn || !microcopyEl || !microcopyMsgs.length) return;
		var msg = microcopyMsgs[idx];
		if (!msg) return;
		microcopyEl.textContent = msg;
		microcopyEl.hidden = false;
		microcopyEl.classList.add('is-shown');
		setTimeout(function () {
			microcopyEl.classList.remove('is-shown');
			setTimeout(function () { microcopyEl.hidden = true; }, 250);
		}, 1500);
	}

	/* =============================================================
	 * Hero
	 * ============================================================= */

	function initHero() {
		if (!hero) {
			startFlow('classic');
			return;
		}
		hero.querySelectorAll('[data-flow]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				startFlow(btn.dataset.flow);
				showMicrocopy(0);
			});
		});
	}

	function startFlow(flow) {
		state.flow = flow === 'matchmaker' && matchmakerOn ? 'matchmaker' : 'classic';
		document.getElementById('bqw-flow').value = state.flow;
		if (hero) hero.hidden = true;
		if (progressWrap) progressWrap.hidden = false;
		if (form) form.hidden = false;
		goToStep(state.flow === 'matchmaker' ? 'm1' : '1');
	}

	/* =============================================================
	 * Classic flow — step 1 (cats & products)
	 * ============================================================= */

	function isSelected(id) { return state.selection.some(function (p) { return p.id === id; }); }

	function addToSelection(p, cat) {
		if (state.unsure) state.unsure = false;
		if (isSelected(p.id)) return;
		state.selection.push({
			id: p.id, name: p.name, image: p.image || '', sku: p.sku || '',
			attributes: p.attributes || [],
			categoryId: cat ? cat.id : 0,
			categorySlug: cat ? cat.slug : '',
			categoryName: cat ? cat.name : '',
		});
		updateSelectionUI();
	}

	function removeFromSelection(id) {
		state.selection = state.selection.filter(function (p) { return p.id !== id; });
		updateSelectionUI();
		var card = document.querySelector('.bqw-product-card[data-product-id="' + id + '"]');
		if (card) card.classList.remove('is-selected');
	}

	function setUnsure(on) {
		state.unsure = !!on;
		if (state.unsure) {
			state.selection = [];
			document.querySelectorAll('.bqw-product-card.is-selected').forEach(function (el) { el.classList.remove('is-selected'); });
		}
		updateSelectionUI();
	}

	function updateSelectionUI() {
		document.getElementById('bqw-selected-products-json').value = JSON.stringify(state.selection.map(function (p) {
			return { id: p.id, name: p.name, sku: p.sku, categoryId: p.categoryId, categorySlug: p.categorySlug, categoryName: p.categoryName };
		}));
		document.getElementById('bqw-unsure').value = state.unsure ? '1' : '0';

		var strip = document.getElementById('bqw-selection-strip');
		var avatars = document.getElementById('bqw-selection-avatars');
		var countEl = document.getElementById('bqw-selection-count');

		if (!state.selection.length) {
			if (strip) strip.hidden = true;
		} else if (strip) {
			strip.hidden = false;
			countEl.textContent = (state.selection.length === 1
				? (i18n.selectedOne || 'You picked 1 machine')
				: (i18n.selectedMany || 'You picked %d machines').replace('%d', state.selection.length));
			avatars.innerHTML = '';
			state.selection.slice(0, 5).forEach(function (p) {
				var img = document.createElement('span');
				img.className = 'bqw-avatar';
				img.title = p.name;
				if (p.image) img.style.backgroundImage = 'url(' + p.image + ')';
				else { img.textContent = (p.name || '?').slice(0, 2).toUpperCase(); img.classList.add('bqw-avatar-text'); }
				avatars.appendChild(img);
			});
			if (state.selection.length > 5) {
				var more = document.createElement('span');
				more.className = 'bqw-avatar bqw-avatar-more';
				more.textContent = '+' + (state.selection.length - 5);
				avatars.appendChild(more);
			}
		}
		var nextBtn = document.getElementById('bqw-next-1');
		if (nextBtn) nextBtn.disabled = !(state.selection.length || state.unsure);
	}

	function renderCategories() {
		var wrap = document.getElementById('bqw-step1-categories');
		if (!wrap) return;
		wrap.innerHTML = '';

		if (cfg.forced_product) {
			addToSelection(cfg.forced_product, { id: 0, slug: '', name: '' });
			goToStep('2');
			return;
		}
		if (cfg.forced_category_id && cfg.categories.length === 1) {
			showProducts(cfg.categories[0]);
			return;
		}

		var grid = document.createElement('div');
		grid.className = 'bqw-cards';
		(cfg.categories || []).forEach(function (cat) {
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
		if (productsCache[cat.id]) renderProductsList(productsCache[cat.id], body, cat);
		else {
			body.innerHTML = '<p class="bqw-loading">' + escapeHtml(i18n.loading || 'Loading…') + '</p>';
			fetchProducts(cat.id, body, cat);
		}
	}

	function fetchProducts(catId, body, cat) {
		var fd = new FormData();
		fd.append('action', 'bqw_get_products_by_category');
		fd.append('nonce', window.BQW.nonce);
		fd.append('category_id', String(catId));
		fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
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
				if (nowSelected) { card.classList.add('is-selected'); addToSelection(p, cat); }
				else { card.classList.remove('is-selected'); removeFromSelection(p.id); }
			});
			grid.appendChild(card);
		});
		var unsure = buildCard('', i18n.unsure || "I'm not sure");
		unsure.classList.add('bqw-unsure-card');
		if (state.unsure) unsure.classList.add('is-selected');
		unsure.addEventListener('click', function () {
			var on = !unsure.classList.contains('is-selected');
			document.querySelectorAll('.bqw-unsure-card').forEach(function (el) { el.classList.toggle('is-selected', on); });
			setUnsure(on);
		});
		grid.appendChild(unsure);
		body.appendChild(grid);
	}

	function buildCard(img, name) {
		var card = document.createElement('button');
		card.type = 'button'; card.className = 'bqw-card';
		if (img) {
			var im = document.createElement('img');
			im.className = 'bqw-card-img'; im.src = img; im.alt = name; im.loading = 'lazy';
			card.appendChild(im);
		}
		var label = document.createElement('span');
		label.className = 'bqw-card-name'; label.textContent = name;
		card.appendChild(label);
		return card;
	}

	function buildProductCard(p) {
		var card = buildCard(p.image, p.name);
		card.classList.add('bqw-product-card');
		var check = document.createElement('span');
		check.className = 'bqw-card-check'; check.setAttribute('aria-hidden', 'true'); check.textContent = '✓';
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

	/* =============================================================
	 * Selection modal
	 * ============================================================= */

	function openSelectionModal() {
		var modal = document.getElementById('bqw-selection-modal');
		var list = document.getElementById('bqw-selection-modal-list');
		list.innerHTML = '';
		if (!state.selection.length) {
			var empty = document.createElement('li');
			empty.className = 'bqw-modal-empty';
			empty.textContent = i18n.noSelection || 'No machines selected.';
			list.appendChild(empty);
		} else state.selection.forEach(function (p) {
			var li = document.createElement('li'); li.className = 'bqw-modal-item';
			if (p.image) { var im = document.createElement('img'); im.src = p.image; im.alt = p.name; li.appendChild(im); }
			var info = document.createElement('div'); info.className = 'bqw-modal-item-info';
			var n = document.createElement('strong'); n.textContent = p.name; info.appendChild(n);
			if (p.categoryName) { var c = document.createElement('span'); c.className = 'bqw-modal-item-cat'; c.textContent = p.categoryName; info.appendChild(c); }
			li.appendChild(info);
			var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'bqw-modal-item-remove';
			rm.setAttribute('aria-label', (i18n.remove || 'Remove') + ' ' + p.name);
			rm.textContent = '×';
			rm.addEventListener('click', function () { removeFromSelection(p.id); openSelectionModal(); });
			li.appendChild(rm);
			list.appendChild(li);
		});
		modal.hidden = false;
	}

	function closeSelectionModal() { document.getElementById('bqw-selection-modal').hidden = true; }

	/* =============================================================
	 * Country select
	 * ============================================================= */

	function buildCountries() {
		var sel = document.getElementById('bqw-country');
		var dial = document.getElementById('bqw-dial');
		(countries || []).forEach(function (c) {
			var opt = document.createElement('option');
			opt.value = c.code; opt.textContent = c.name; opt.dataset.dial = c.dial;
			if (c.code === detected) opt.selected = true;
			sel.appendChild(opt);
		});
		var match = (countries || []).find(function (c) { return c.code === detected; });
		if (match) dial.textContent = match.dial;
		sel.addEventListener('change', function () {
			var opt = sel.options[sel.selectedIndex];
			dial.textContent = opt ? (opt.dataset.dial || '+') : '+';
		});
	}

	/* =============================================================
	 * Validation
	 * ============================================================= */

	function validateStep2() {
		var fs = document.getElementById('bqw-application-fieldset');
		var err = document.getElementById('bqw-application-error');
		if (!fs || !err) return true;
		var checked = fs.querySelectorAll('input[name="application[]"]:checked').length;
		if (cfg.enable_application && checked < 1) {
			err.hidden = false; fs.classList.add('bqw-has-error'); return false;
		}
		err.hidden = true; fs.classList.remove('bqw-has-error');
		return true;
	}

	function validateStep3() {
		var ok = true;
		['bqw-first-name','bqw-last-name','bqw-company','bqw-email','bqw-phone','bqw-country'].forEach(function (id) {
			var el = document.getElementById(id);
			var field = el.closest('.bqw-field');
			if (!el.value.trim()) { ok = false; field.classList.add('bqw-has-error'); ensureFieldError(field, i18n.requiredField || 'Required.'); }
			else { field.classList.remove('bqw-has-error'); removeFieldError(field); }
		});
		var emailEl = document.getElementById('bqw-email');
		if (emailEl.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailEl.value)) {
			ok = false; var f = emailEl.closest('.bqw-field'); f.classList.add('bqw-has-error'); ensureFieldError(f, i18n.invalidEmail || 'Invalid email.');
		}
		var privacy = document.getElementById('bqw-privacy');
		var pField = privacy.closest('.bqw-field');
		if (!privacy.checked) { ok = false; pField.classList.add('bqw-has-error'); ensureFieldError(pField, i18n.requiredField || 'Required.'); }
		else { pField.classList.remove('bqw-has-error'); removeFieldError(pField); }
		return ok;
	}

	function ensureFieldError(field, msg) {
		var ex = field.querySelector('.bqw-field-error');
		if (!ex) { ex = document.createElement('div'); ex.className = 'bqw-field-error'; field.appendChild(ex); }
		ex.textContent = msg;
	}
	function removeFieldError(field) { var ex = field.querySelector('.bqw-field-error'); if (ex) ex.remove(); }

	/* =============================================================
	 * Summary (step 4)
	 * ============================================================= */

	function renderSummary() {
		var box = document.getElementById('bqw-summary');
		var rows = [];
		function add(k, v) { if (v) rows.push('<dt>' + escapeHtml(k) + '</dt><dd>' + escapeHtml(v) + '</dd>'); }

		if (state.unsure) add(i18n.machinesOfInterest || 'Machines of interest', i18n.unsure || "I'm not sure");
		else if (state.selection.length) add(i18n.machinesOfInterest || 'Machines of interest', state.selection.map(function (p) { return p.name; }).join(', '));

		if (state.flow === 'classic') {
			var apps = Array.from(form.querySelectorAll('input[name="application[]"]:checked')).map(function (i) { return i.value; });
			if (apps.length) add(i18n.application || 'Application', apps.join(', '));
			var mats = Array.from(form.querySelectorAll('input[name="materials[]"]:checked')).map(function (i) { return i.value; });
			if (mats.length) add(i18n.materials || 'Materials', mats.join(', '));
			var vol = (form.querySelector('input[name="volume"]:checked') || {}).value;
			add(i18n.volume || 'Monthly volume', vol);
		} else {
			if (state.mmAnswers.application.length) add(i18n.application || 'Application', state.mmAnswers.application.join(', '));
			if (state.mmAnswers.materials.length)   add(i18n.materials || 'Materials',  state.mmAnswers.materials.join(', '));
			add(i18n.volume || 'Monthly volume', state.mmAnswers.volume);
			if (state.mmAnswers.format) add(i18n.format || 'Format', state.mmAnswers.format);
			if (state.mmAnswers.budget) add(i18n.budget || 'Budget', state.mmAnswers.budget);
		}

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
	function parseJSON(s) { try { return JSON.parse(s); } catch (e) { return null; } }

	/* =============================================================
	 * Matchmaker flow
	 * ============================================================= */

	function captureMmStep(step) {
		if (step === 'm1') state.mmAnswers.application = pickValues('mm_application[]');
		if (step === 'm2') state.mmAnswers.materials   = pickValues('mm_materials[]');
		if (step === 'm3') state.mmAnswers.volume      = pickValue('mm_volume');
		if (step === 'm4') state.mmAnswers.format      = pickValue('mm_format');
		if (step === 'm5') state.mmAnswers.budget      = pickValue('mm_budget');
	}

	function pickValues(name) {
		return Array.from(form.querySelectorAll('input[name="' + name + '"]:checked')).map(function (i) { return i.value; });
	}
	function pickValue(name) {
		var el = form.querySelector('input[name="' + name + '"]:checked');
		return el ? el.value : '';
	}

	function fetchRecommendations() {
		var listEl = document.getElementById('bqw-rec-list');
		listEl.innerHTML = '<p class="bqw-loading">' + escapeHtml(i18n.loading || 'Loading…') + '</p>';

		var fd = new FormData();
		fd.append('action', 'bqw_matchmaker');
		fd.append('nonce', window.BQW.nonce);
		Object.keys(state.mmAnswers).forEach(function (k) {
			var v = state.mmAnswers[k];
			if (Array.isArray(v)) v.forEach(function (x) { fd.append('answers[' + k + '][]', x); });
			else if (v) fd.append('answers[' + k + ']', v);
		});

		fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				var data = (json && json.success && json.data) || {};
				var recs = data.recommendations || [];
				state.mmRecommendations = recs;
				if (data.ai_failed && !recs.length) {
					renderAIFailed(listEl, data.fallback);
				} else {
					renderRecommendations(recs, listEl, data.fallback);
				}
			})
			.catch(function () { renderAIFailed(listEl); });
	}

	function renderAIFailed(listEl, fallbackMsg) {
		listEl.innerHTML = '';
		var p = document.createElement('p');
		p.className = 'bqw-empty';
		p.textContent = fallbackMsg || (i18n.aiFailed || "We've received your answers. We'll get back to you with a personalized recommendation.");
		listEl.appendChild(p);
		// Allow the user to proceed even without recommendations.
		document.getElementById('bqw-rec-next').disabled = false;
	}

	function renderRecommendations(recs, listEl, fallbackMsg) {
		listEl.innerHTML = '';
		if (!recs.length) {
			var p = document.createElement('p');
			p.className = 'bqw-empty';
			p.textContent = fallbackMsg || i18n.noMatches || "Your case is specific. Let's talk directly.";
			listEl.appendChild(p);
			document.getElementById('bqw-rec-next').disabled = false;
			return;
		}
		recs.forEach(function (r) {
			var card = document.createElement('button');
			card.type = 'button';
			card.className = 'bqw-rec-card';
			card.dataset.productId = String(r.id);
			card.innerHTML =
				(r.image ? '<img class="bqw-rec-img" src="' + r.image + '" alt="">' : '<span class="bqw-rec-img bqw-opt-img-fallback">★</span>') +
				'<div class="bqw-rec-body">' +
					'<div class="bqw-rec-head"><strong>' + escapeHtml(r.name) + '</strong>' +
						'<span class="bqw-rec-score">' + (i18n.matchScore || 'Matches at') + ' ' + r.score + '%</span></div>' +
					(r.reasons && r.reasons.length ? '<ul class="bqw-rec-reasons">' + r.reasons.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul>' : '') +
				'</div>' +
				'<span class="bqw-card-check" aria-hidden="true">✓</span>';
			card.addEventListener('click', function () {
				var pick = !card.classList.contains('is-selected');
				if (pick) { card.classList.add('is-selected'); addToSelection(r, { id: r.categoryId, slug: r.categorySlug, name: r.categoryName }); }
				else { card.classList.remove('is-selected'); removeFromSelection(r.id); }
				document.getElementById('bqw-rec-next').disabled = !state.selection.length;
			});
			listEl.appendChild(card);
		});
	}

	/* =============================================================
	 * Submit
	 * ============================================================= */

	function ensureCaptchaToken() {
		// reCAPTCHA v3: execute and write token before submit.
		var captcha = document.getElementById('bqw-captcha');
		if (!captcha) return Promise.resolve();
		var provider = captcha.dataset.provider;
		if (provider === 'recaptcha_v3' && cfg.captcha && cfg.captcha.site_key && window.grecaptcha) {
			return new Promise(function (resolve) {
				window.grecaptcha.ready(function () {
					window.grecaptcha.execute(cfg.captcha.site_key, { action: cfg.captcha.action || 'boprint_quote' })
						.then(function (token) {
							var tEl = document.getElementById('bqw-recaptcha-v3');
							if (tEl) tEl.value = token;
							resolve();
						})
						.catch(function () { resolve(); });
				});
			});
		}
		return Promise.resolve();
	}

	function submit(e) {
		e.preventDefault();
		if (errorBox) errorBox.hidden = true;

		var btn = document.getElementById('bqw-submit');
		btn.disabled = true;
		var spinner = btn.querySelector('.bqw-spinner');
		if (spinner) spinner.hidden = false;

		// Refresh hidden fields.
		document.getElementById('bqw-selected-products-json').value = JSON.stringify(state.selection.map(function (p) {
			return { id: p.id, name: p.name, sku: p.sku, categoryId: p.categoryId, categorySlug: p.categorySlug, categoryName: p.categoryName };
		}));
		document.getElementById('bqw-unsure').value = state.unsure ? '1' : '0';
		document.getElementById('bqw-mm-answers-json').value = state.flow === 'matchmaker' ? JSON.stringify(state.mmAnswers) : '';

		showMicrocopy(microcopyMsgs.length - 1);

		ensureCaptchaToken().then(function () {
			var fd = new FormData(form);
			var dial = document.getElementById('bqw-dial').textContent.trim();
			fd.set('phone', dial + ' ' + (fd.get('phone') || ''));

			fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json && json.success) {
						if (json.data.redirect) {
							window.location.href = json.data.redirect;
							return;
						}
						form.style.display = 'none';
						if (progressWrap) progressWrap.style.display = 'none';
						thanksBox.hidden = false;
						thanksBox.innerHTML = json.data.html || '';
						thanksBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
					} else {
						var msg = (json && json.data && json.data.message) || i18n.genericError || 'Error';
						if (errorBox) { errorBox.textContent = msg; errorBox.hidden = false; }
					}
				})
				.catch(function () {
					if (errorBox) { errorBox.textContent = i18n.genericError || 'Error'; errorBox.hidden = false; }
				})
				.finally(function () {
					btn.disabled = false;
					if (spinner) spinner.hidden = true;
				});
		});
	}

	/* =============================================================
	 * Init
	 * ============================================================= */

	function init() {
		buildCountries();
		renderCategories();
		updateSelectionUI();
		initHero();

		if (!hero) {
			// Hero disabled: show form immediately and start classic.
			startFlow('classic');
		}

		// Classic: Next button on step 1.
		var n1 = document.getElementById('bqw-next-1');
		if (n1) n1.addEventListener('click', function () { showMicrocopy(1); goToStep('2'); });

		// Classic: data-next/data-prev declared inline.
		root.querySelectorAll('[data-next]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var target = btn.dataset.next;
				if (state.step === '2' && !validateStep2()) return;
				if (state.step === '3' && !validateStep3()) return;
				if (state.step === '2') showMicrocopy(2);
				if (state.step === '3') showMicrocopy(3);
				goToStep(target);
			});
		});
		root.querySelectorAll('[data-prev]').forEach(function (btn) {
			btn.addEventListener('click', function () { goToStep(btn.dataset.prev); });
		});

		// Step 3 "Back" depending on flow.
		root.querySelectorAll('[data-prev-flow]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				goToStep(state.flow === 'matchmaker' ? 'rec' : '2');
			});
		});

		// Matchmaker navigation.
		if (matchmakerOn) {
			root.querySelectorAll('[data-mm-next]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					captureMmStep(state.step);
					var target = btn.dataset.mmNext;
					goToStep(target);
					if (target === 'rec') fetchRecommendations();
				});
			});
			root.querySelectorAll('[data-mm-back]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var target = btn.dataset.mmBack;
					if (target === 'hero' && hero) {
						// back to flow choice.
						hero.hidden = false;
						if (progressWrap) progressWrap.hidden = true;
						form.hidden = true;
					} else {
						goToStep(target);
					}
				});
			});
			var recNext = document.getElementById('bqw-rec-next');
			if (recNext) recNext.addEventListener('click', function () { goToStep('3'); });
		}

		// Back to categories within step 1.
		var backCats = document.getElementById('bqw-back-to-categories');
		if (backCats) backCats.addEventListener('click', backToCategories);
		var addMore = document.getElementById('bqw-add-from-other');
		if (addMore) addMore.addEventListener('click', backToCategories);

		// Selection modal.
		var editBtn = document.getElementById('bqw-selection-edit');
		if (editBtn) editBtn.addEventListener('click', openSelectionModal);
		var modal = document.getElementById('bqw-selection-modal');
		if (modal) modal.addEventListener('click', function (e) {
			if (e.target.dataset && e.target.dataset.close === '1') closeSelectionModal();
		});

		form.addEventListener('submit', submit);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
