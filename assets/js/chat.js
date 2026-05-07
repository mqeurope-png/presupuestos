/* Bomedia Quote Wizard — chatbot frontend (v1.7.2, scripted backbone). */
(function () {
	'use strict';
	// First-line breadcrumb so the file's mere presence is visible in dev tools.
	try { console.log('[bqw chat] script loaded'); } catch (e) {}

	if (typeof window.BQW === 'undefined') {
		try { console.warn('[bqw chat] window.BQW is undefined — wp_localize_script did not run before this file. Aborting.'); } catch (e) {}
		return;
	}
	var root = document.getElementById('bqw-chat-wrap');
	if (!root) {
		try { console.warn('[bqw chat] #bqw-chat-wrap not found — template did not render. Aborting.'); } catch (e) {}
		return;
	}

	var cfg       = window.BQW.config || {};
	var i18n      = window.BQW.i18n || {};
	var countries = window.BQW.countries || [];
	var detected  = window.BQW.detectedCountry || 'ES';

	var hero        = document.getElementById('bqw-chat-hero');
	var stream      = document.getElementById('bqw-chat-stream');
	var optionsWrap = document.getElementById('bqw-chat-options');
	var chatForm    = document.getElementById('bqw-chat-form');
	var textInput   = document.getElementById('bqw-chat-text');
	var skipBtn     = document.getElementById('bqw-skip-to-send');
	var contactPanel = document.getElementById('bqw-contact-panel');
	var contactHint  = document.getElementById('bqw-contact-hint');
	var backToChat   = document.getElementById('bqw-back-to-chat');
	var form         = document.getElementById('bqw-form');
	var thanksBox    = document.getElementById('bqw-thanks');
	var recPanel     = document.getElementById('bqw-rec-panel');
	var recPanelBody = document.getElementById('bqw-rec-panel-body');
	var recPanelFoot = document.getElementById('bqw-rec-panel-foot');
	var recCounter   = document.getElementById('bqw-rec-counter');
	var recCta       = document.getElementById('bqw-rec-cta');
	var recPanelClose = document.getElementById('bqw-rec-panel-close');
	var recFab       = document.getElementById('bqw-rec-fab');
	var recFabCount  = document.getElementById('bqw-rec-fab-count');
	var redirectURL  = root.dataset.redirect || '';
	var lang         = root.dataset.language || 'en';

	var state = {
		session_id: ensureSessionId(),
		current_step: null,        // server-side step id of the question on screen.
		current_step_meta: null,   // { allow_free_text, allow_skip, multi_select, type, cta_to }
		selection: [],             // recommendation picks (catalog products).
		pending_buttons: [],       // currently-toggled chip labels (multi-select).
		flow_origin: 'chat',
	};
	document.getElementById('bqw-session-id').value = state.session_id;

	/* =========================================================
	 * Session ID
	 * ========================================================= */
	function ensureSessionId() {
		try {
			var sid = sessionStorage.getItem('bqw_session_id');
			if (sid) return sid;
		} catch (e) {}
		var fresh = uuid();
		try { sessionStorage.setItem('bqw_session_id', fresh); } catch (e) {}
		return fresh;
	}
	function uuid() {
		if (window.crypto && window.crypto.getRandomValues) {
			var b = new Uint8Array(16);
			window.crypto.getRandomValues(b);
			b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
			var h = []; for (var i = 0; i < 16; i++) h.push(('0' + b[i].toString(16)).slice(-2));
			return h.join('').replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
		}
		return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
		});
	}

	/* =========================================================
	 * Bubbles + UI helpers
	 * ========================================================= */
	function appendBubble(role, text, opts) {
		opts = opts || {};
		var b = document.createElement('div');
		b.className = 'bqw-bubble bqw-bubble-' + role;
		if (role === 'assistant') {
			var av = document.createElement('span');
			av.className = 'bqw-bubble-avatar'; av.textContent = 'B'; av.setAttribute('aria-hidden', 'true');
			b.appendChild(av);
		}
		var body = document.createElement('div');
		body.className = 'bqw-bubble-body';
		if (opts.chips && opts.chips.length) {
			var chips = document.createElement('div');
			chips.className = 'bqw-bubble-chips';
			opts.chips.forEach(function (c) {
				var chip = document.createElement('span');
				chip.className = 'bqw-bubble-chip';
				chip.textContent = c;
				chips.appendChild(chip);
			});
			body.appendChild(chips);
		}
		if (text) {
			var p = document.createElement('p');
			p.className = 'bqw-bubble-text';
			p.textContent = text;
			body.appendChild(p);
		}
		b.appendChild(body);
		stream.appendChild(b);
		stream.scrollTo({ top: stream.scrollHeight, behavior: 'smooth' });
	}

	function appendTyping() {
		var b = document.createElement('div');
		b.className = 'bqw-bubble bqw-bubble-assistant bqw-typing';
		b.id = 'bqw-typing';
		b.innerHTML = '<span class="bqw-bubble-avatar" aria-hidden="true">B</span><div class="bqw-bubble-body"><span class="bqw-typing-dots"><span></span><span></span><span></span></span></div>';
		stream.appendChild(b);
		stream.scrollTo({ top: stream.scrollHeight, behavior: 'smooth' });
	}
	function removeTyping() {
		var t = document.getElementById('bqw-typing');
		if (t) t.parentNode.removeChild(t);
	}

	function renderOptions(step) {
		optionsWrap.innerHTML = '';
		optionsWrap.classList.add('bqw-opt-cards');
		state.pending_buttons = [];

		// Free text input is no longer accepted on option steps (v1.7.6 — closed dialog).
		if (textInput) textInput.disabled = true;

		var allow_skip = !!step.allow_skip;

		(step.options || []).forEach(function (opt) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'bqw-chip';
			btn.dataset.label = opt.label;
			btn.innerHTML = (opt.icon ? iconForHint(opt.icon) : '') + '<span class="bqw-chip-label">' + escapeHtml(opt.label) + '</span>';
			btn.addEventListener('click', function () {
				if (step.multi_select) {
					var idx = state.pending_buttons.indexOf(opt.label);
					if (idx >= 0) {
						state.pending_buttons.splice(idx, 1);
						btn.classList.remove('is-selected');
					} else {
						state.pending_buttons.push(opt.label);
						btn.classList.add('is-selected');
					}
				} else {
					optionsWrap.querySelectorAll('.is-selected').forEach(function (e) { e.classList.remove('is-selected'); });
					btn.classList.add('is-selected');
					state.pending_buttons = [opt.label];
					sendAdvance();
				}
			});
			optionsWrap.appendChild(btn);
		});

		if (step.multi_select && (step.options || []).length > 0) {
			var ok = document.createElement('button');
			ok.type = 'button';
			ok.className = 'bqw-btn bqw-btn-primary bqw-chip-send';
			ok.textContent = i18n.send || 'Send';
			ok.addEventListener('click', function () { sendAdvance(); });
			optionsWrap.appendChild(ok);
		}
		if (allow_skip) {
			var sk = document.createElement('button');
			sk.type = 'button';
			sk.className = 'bqw-chip bqw-chip-skip';
			sk.textContent = i18n.skipQuestion || 'Skip this question';
			sk.addEventListener('click', function () { sendAdvance({ skip: true }); });
			optionsWrap.appendChild(sk);
		}
	}

	function renderWelcomeCards(step) {
		optionsWrap.innerHTML = '';
		optionsWrap.classList.remove('bqw-opt-cards');
		var grid = document.createElement('div');
		grid.className = 'bqw-welcome-grid';
		(step.options || []).forEach(function (opt) {
			var card = document.createElement('button');
			card.type = 'button';
			card.className = 'bqw-welcome-card';
			card.innerHTML =
				'<span class="bqw-welcome-icon">' + (opt.icon ? iconForHint(opt.icon) : '★') + '</span>' +
				'<strong>' + escapeHtml(opt.label) + '</strong>' +
				(opt.subtitle ? '<span class="bqw-welcome-sub">' + escapeHtml(opt.subtitle) + '</span>' : '');
			card.addEventListener('click', function () {
				state.pending_buttons = [opt.label];
				sendAdvance();
			});
			grid.appendChild(card);
		});
		optionsWrap.appendChild(grid);
	}

	function renderBomediaCatalog(step) {
		optionsWrap.innerHTML = '';
		optionsWrap.classList.remove('bqw-opt-cards');
		var view = document.getElementById('bqw-browse-view');
		var grid = document.getElementById('bqw-browse-grid');
		var search = document.getElementById('bqw-browse-search');
		var brandSel = document.getElementById('bqw-browse-brand');
		var counter = document.getElementById('bqw-browse-counter');
		var cta = document.getElementById('bqw-browse-cta');
		view.hidden = false;

		var products = (step.products || []).slice();
		var brands   = step.brands || [];

		brandSel.innerHTML = '';
		brands.forEach(function (b) {
			var o = document.createElement('option');
			o.value = b.id; o.textContent = b.label || b.id;
			brandSel.appendChild(o);
		});

		function paint() {
			var q = (search.value || '').toLowerCase().trim();
			var picked = Array.from(brandSel.selectedOptions).map(function (o) { return o.value; });
			grid.innerHTML = '';
			products.forEach(function (p) {
				if (q && (p.name || '').toLowerCase().indexOf(q) === -1) return;
				if (picked.length && picked.indexOf(p.brand) === -1) return;
				grid.appendChild(buildBrowseCard(p));
			});
		}

		function buildBrowseCard(p) {
			var card = document.createElement('div');
			card.className = 'bqw-browse-card';
			card.dataset.productId = p.id;
			if (state.selection.some(function (s) { return s.id === p.id; })) card.classList.add('is-selected');

			var feats = [];
			if (p.area)  feats.push('<span>' + escapeHtml(p.area) + '</span>');
			if (p.feat1) feats.push('<span>' + escapeHtml(p.feat1) + '</span>');
			if (p.feat2) feats.push('<span>' + escapeHtml(p.feat2) + '</span>');

			var siteHost = (window.location && window.location.hostname) || '';
			var linkHost = '';
			try { linkHost = new URL(p.link).hostname; } catch (e) {}
			var offsiteLink = '';
			if (p.link && linkHost && linkHost.replace(/^www\./, '') !== siteHost.replace(/^www\./, '')) {
				offsiteLink = '<a href="' + p.link + '" target="_blank" rel="noopener">' + escapeHtml((i18n.viewOn || 'View on') + ' ' + linkHost) + ' ↗</a>';
			} else if (p.link) {
				offsiteLink = '<a href="' + p.link + '" target="_blank" rel="noopener">' + escapeHtml(i18n.viewProduct || 'View product →') + '</a>';
			}

			card.innerHTML =
				(p.img ? '<img class="bqw-browse-card-img" src="' + p.img + '" alt="" loading="lazy">' : '<div class="bqw-browse-card-img"></div>') +
				'<div class="bqw-browse-card-body">' +
					'<span class="bqw-browse-card-brand">' + escapeHtml(p.brand || '') + '</span>' +
					'<span class="bqw-browse-card-name">' + escapeHtml(p.name || '') + '</span>' +
					(feats.length ? '<div class="bqw-browse-card-feats">' + feats.join('') + '</div>' : '') +
				'</div>' +
				'<div class="bqw-browse-card-actions">' +
					'<button type="button" class="bqw-btn bqw-btn-primary bqw-browse-pick">' + escapeHtml(i18n.requestQuote || 'Request quote') + '</button>' +
					offsiteLink +
				'</div>';

			card.querySelector('.bqw-browse-pick').addEventListener('click', function () {
				var picked = !card.classList.contains('is-selected');
				if (picked) {
					card.classList.add('is-selected');
					addSelection({
						id: p.id, name: p.name, image: p.img || '', sku: '',
						brand: p.brand || '', price: '', area: p.area || '', link: p.link || '',
						source: 'catalog', categoryId: 0, categorySlug: p.brand || '', categoryName: p.brand || '',
					});
				} else {
					card.classList.remove('is-selected');
					removeSelection(p.id);
				}
				updateBrowseCounter();
			});
			return card;
		}

		function updateBrowseCounter() {
			counter.textContent = state.selection.length + (i18n.selectedSuffix ? ' ' + i18n.selectedSuffix : '');
			cta.disabled = state.selection.length === 0;
		}

		search.oninput = paint;
		brandSel.onchange = paint;
		cta.onclick = function () {
			view.hidden = true;
			revealContactForm({ withHint: false });
			document.getElementById('bqw-flow').value = 'direct-catalog';
			state.flow_origin = 'direct-catalog';
		};

		paint();
		updateBrowseCounter();
	}

	function renderSiteCatalogTodo() {
		// Camino B (WooCommerce categories): minimal v1.7.6 — point the user
		// to the contact form with a brief explanation. A full Woo browser
		// will land in a follow-up; the existing classic flow already covers
		// theme overrides for shops that need it.
		revealContactForm({ withHint: false });
		document.getElementById('bqw-flow').value = 'direct-catalog';
		state.flow_origin = 'direct-catalog';
	}

	function renderRecommendations(recs) {
		if (!recPanel || !recPanelBody) return;
		recPanelBody.innerHTML = '';
		if (!recs || !recs.length) {
			var p = document.createElement('p');
			p.className = 'bqw-rec-placeholder';
			p.textContent = i18n.noMatches || "Your case is specific. Let's talk directly.";
			recPanelBody.appendChild(p);
			recPanelFoot.hidden = true;
			return;
		}
		recs.forEach(function (r) { recPanelBody.appendChild(buildRecCard(r)); });
		recPanelFoot.hidden = false;
		updateRecCounter();
		// Mobile: open panel + show FAB.
		if (window.innerWidth < 900) {
			recPanel.classList.add('is-open');
			if (recFab) recFab.classList.add('is-shown');
		}
	}

	function updateRecCounter() {
		if (!recCounter || !recCta) return;
		var picked = state.selection.length;
		var total  = recPanelBody ? recPanelBody.querySelectorAll('.bqw-rec-card').length : 0;
		recCounter.textContent = picked + ' / ' + total;
		recCta.disabled = picked === 0;
		if (recFabCount) {
			recFabCount.textContent = picked > 0 ? String(picked) : '';
			recFabCount.style.display = picked > 0 ? '' : 'none';
		}
	}

	function buildRecCard(r) {
		var card = document.createElement('div');
		card.className = 'bqw-rec-card';
		card.dataset.productId = String(r.id);
		var image = r.img || r.image || '';
		var imgHtml = image
			? '<img class="bqw-rec-img" src="' + image + '" alt="" loading="lazy">'
			: '<span class="bqw-rec-img bqw-opt-img-fallback">★</span>';
		var brandHtml = r.brand ? '<span class="bqw-rec-brand">' + escapeHtml(r.brand) + '</span>' : '';
		var badgeHtml = r.badge ? '<span class="bqw-rec-badge">' + escapeHtml(r.badge) + '</span>' : '';
		var feats = [];
		if (r.area)  feats.push('<li>' + escapeHtml(r.area) + '</li>');
		if (r.feat1) feats.push('<li>' + escapeHtml(r.feat1) + '</li>');
		if (r.feat2) feats.push('<li>' + escapeHtml(r.feat2) + '</li>');
		var featsHtml = feats.length ? '<ul class="bqw-rec-feats">' + feats.join('') + '</ul>' : '';
		var reasonsHtml = (r.reasons && r.reasons.length)
			? '<ul class="bqw-rec-reasons">' + r.reasons.map(function (x) { return '<li>' + escapeHtml(x) + '</li>'; }).join('') + '</ul>'
			: '';
		var offsiteHtml = '';
		if (r.link) {
			var siteHost = (window.location && window.location.hostname) || '';
			var linkHost = '';
			try { linkHost = new URL(r.link).hostname; } catch (e) {}
			if (linkHost && siteHost && linkHost.replace(/^www\./, '') !== siteHost.replace(/^www\./, '')) {
				var tpl = i18n.availableOn || 'Available on %s';
				offsiteHtml = '<span class="bqw-rec-offsite"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M10 14L20 4"/><path d="M14 4v0H4v16h16V10"/></svg>' + escapeHtml(tpl.replace('%s', linkHost)) + '</span>';
			}
		}
		var linkHtml = r.link ? '<a class="bqw-rec-link" href="' + r.link + '" target="_blank" rel="noopener">' + escapeHtml(i18n.viewProduct || 'View product →') + '</a>' : '';

		card.innerHTML =
			'<div class="bqw-rec-imgwrap">' + imgHtml + '</div>' +
			'<div class="bqw-rec-body">' +
				'<div class="bqw-rec-head">' +
					'<div class="bqw-rec-titlewrap"><strong>' + escapeHtml(r.name) + '</strong>' + brandHtml + offsiteHtml + '</div>' +
					'<div class="bqw-rec-meta">' + badgeHtml + (r.score ? '<span class="bqw-rec-score">' + escapeHtml(i18n.matchScore || 'Matches at') + ' ' + r.score + '%</span>' : '') + '</div>' +
				'</div>' +
				featsHtml +
				reasonsHtml +
				(linkHtml ? '<div class="bqw-rec-footer">' + linkHtml + '</div>' : '') +
			'</div>' +
			'<button type="button" class="bqw-rec-pick" aria-pressed="false">' +
				'<span class="bqw-card-check" aria-hidden="true">✓</span>' +
				'<span class="bqw-rec-pick-label">' + escapeHtml(i18n.pickThis || 'Pick') + '</span>' +
			'</button>';

		var pickBtn = card.querySelector('.bqw-rec-pick');
		pickBtn.addEventListener('click', function () {
			var pick = !card.classList.contains('is-selected');
			if (pick) {
				card.classList.add('is-selected');
				pickBtn.setAttribute('aria-pressed', 'true');
				addSelection({
					id: r.id, name: r.name, image: image, sku: r.sku || '',
					brand: r.brand || '', price: r.price || '', area: r.area || '', link: r.link || '',
					source: 'catalog',
					categoryId: 0, categorySlug: r.brand || '', categoryName: r.brand || '',
				});
			} else {
				card.classList.remove('is-selected');
				pickBtn.setAttribute('aria-pressed', 'false');
				removeSelection(r.id);
			}
			updateRecCounter();
		});
		return card;
	}

	function addSelection(p) {
		if (state.selection.some(function (x) { return x.id === p.id; })) return;
		state.selection.push(p);
		document.getElementById('bqw-selected-products-json').value = JSON.stringify(state.selection);
	}
	function removeSelection(id) {
		state.selection = state.selection.filter(function (x) { return x.id !== id; });
		document.getElementById('bqw-selected-products-json').value = JSON.stringify(state.selection);
	}

	function renderCtaToContact(label) {
		var cta = document.createElement('button');
		cta.type = 'button';
		cta.className = 'bqw-btn bqw-btn-primary bqw-rec-continue';
		cta.textContent = label || (i18n.continueToQuote || 'Continue and request quote →');
		cta.addEventListener('click', function () { revealContactForm({ withHint: false }); });
		optionsWrap.appendChild(cta);
	}

	/* =========================================================
	 * Server protocol
	 * ========================================================= */
	function callInit() {
		// eslint-disable-next-line no-console
		try { console.log('[bqw chat] init request'); } catch (e) {}
		var fd = new FormData();
		fd.append('action', 'bqw_chat_init');
		fd.append('nonce', window.BQW.nonce);
		fd.append('session_id', state.session_id);
		fd.append('language', lang);
		appendTyping();
		fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				removeTyping();
				try { console.log('[bqw chat] first response received', json); } catch (e) {}
				if (!json || !json.success) {
					appendBubble('assistant', i18n.genericError || 'Something went wrong starting the chat.');
					return;
				}
				applyStep(json.data.step);
			})
			.catch(function (err) {
				removeTyping();
				try { console.log('[bqw chat] init failed', err); } catch (e) {}
				appendBubble('assistant', i18n.genericError || 'Something went wrong starting the chat.');
			});
	}

	function sendAdvance(opts) {
		opts = opts || {};
		if (!state.current_step) return;
		var clicks = state.pending_buttons.slice();
		var text   = textInput && textInput.value ? textInput.value.trim() : '';
		if (opts.skip) { clicks = []; text = ''; }
		if (!opts.skip && !clicks.length && !text) return;

		appendBubble('user', text, { chips: clicks });
		if (textInput) textInput.value = '';
		state.pending_buttons = [];
		optionsWrap.innerHTML = '';
		appendTyping();

		var fd = new FormData();
		fd.append('action', 'bqw_chat_advance');
		fd.append('nonce', window.BQW.nonce);
		fd.append('session_id', state.session_id);
		fd.append('step_id', state.current_step);
		fd.append('language', lang);
		clicks.forEach(function (c) { fd.append('selected[]', c); });
		if (text) fd.append('free_text', text);
		if (opts.skip) fd.append('skip', '1');

		fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				removeTyping();
				if (!json || !json.success) {
					appendBubble('assistant', (json && json.data && json.data.message) || i18n.genericError || 'Something went wrong.');
					return;
				}
				applyStep(json.data.step);
			})
			.catch(function () {
				removeTyping();
				appendBubble('assistant', i18n.genericError || 'Something went wrong.');
			});
	}

	function applyActions(actions) {
		actions.forEach(function (a) {
			if (!a || !a.type) return;
			if (a.type === 'update_recommendations' && Array.isArray(a.products)) {
				renderRecommendations(a.products);
			} else if (a.type === 'select_product' && a.product) {
				// Add to selection if not already; toggle the matching card on if visible.
				addSelection({
					id: a.product.id, name: a.product.name, image: a.product.img || a.product.image || '',
					sku: a.product.sku || '', brand: a.product.brand || '', price: a.product.price || '',
					area: a.product.area || '', link: a.product.link || '', source: 'catalog',
					categoryId: 0, categorySlug: a.product.brand || '', categoryName: a.product.brand || '',
				});
				var card = recPanelBody && recPanelBody.querySelector('.bqw-rec-card[data-product-id="' + a.product.id + '"]');
				if (card) {
					card.classList.add('is-selected');
					var btn = card.querySelector('.bqw-rec-pick');
					if (btn) btn.setAttribute('aria-pressed', 'true');
				}
				updateRecCounter();
			} else if (a.type === 'go_to_contact') {
				if (recPanel) recPanel.classList.remove('is-open');
				revealContactForm({ withHint: false });
			}
		});
	}

	function applyStep(step) {
		if (!step) return;
		root.classList.add('is-flow-active');

		state.current_step = step.id;
		state.current_step_meta = step;

		// Always render the assistant bubble first, then any add-ons.
		if (step.message) appendBubble('assistant', step.message);

		switch (step.type) {
			case 'welcome_cards':
				renderWelcomeCards(step);
				break;

			case 'options':
			case 'multi_options':
			case 'single_option':
				renderOptions(step);
				break;

			case 'site_catalog':
				renderSiteCatalogTodo();
				break;

			case 'bomedia_catalog':
				renderBomediaCatalog(step);
				break;

			case 'recommendations':
				renderRecommendations(step.recommendations || []);
				optionsWrap.innerHTML = '';
				renderPostRecActions(step);
				break;

			case 'form':
				revealContactForm({ withHint: false });
				break;

			case 'end':
			default:
				optionsWrap.innerHTML = '';
				break;
		}
	}

	function renderPostRecActions(step) {
		// Three buttons under the chat: primary "Request quote",
		// secondary "Start over", subtle "Not convinced — contact me".
		var bar = document.createElement('div');
		bar.className = 'bqw-post-recs';

		var primary = document.createElement('button');
		primary.type = 'button';
		primary.className = 'bqw-btn bqw-btn-primary';
		primary.textContent = step.cta || (i18n.requestQuote || 'Request quote') + ' →';
		primary.addEventListener('click', function () {
			if (recPanel) recPanel.classList.remove('is-open');
			revealContactForm({ withHint: false });
		});
		bar.appendChild(primary);

		var restart = document.createElement('button');
		restart.type = 'button';
		restart.className = 'bqw-link-btn';
		restart.textContent = i18n.startOver || '↺ Start over';
		restart.addEventListener('click', function () {
			try { sessionStorage.removeItem('bqw_session_id'); } catch (e) {}
			window.location.reload();
		});
		bar.appendChild(restart);

		var nope = document.createElement('button');
		nope.type = 'button';
		nope.className = 'bqw-link-btn';
		nope.textContent = i18n.notConvinced || 'Not convinced — contact me';
		nope.addEventListener('click', function () {
			document.getElementById('bqw-flow').value = 'not-convinced';
			state.flow_origin = 'not-convinced';
			revealContactForm({ withHint: false });
		});
		bar.appendChild(nope);

		optionsWrap.appendChild(bar);
	}

	/* =========================================================
	 * Contact panel
	 * ========================================================= */
	function revealContactForm(opts) {
		opts = opts || {};
		contactPanel.hidden = false;
		contactHint.hidden = !opts.withHint;
		if (opts.withHint) {
			document.getElementById('bqw-flow').value = 'skip';
			state.flow_origin = 'skip';
			document.getElementById('bqw-chat').hidden = true;
		}
		contactPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function hideContactForm() {
		contactPanel.hidden = true;
		document.getElementById('bqw-chat').hidden = false;
	}

	/* =========================================================
	 * Country select + dial code
	 * ========================================================= */
	function buildCountries() {
		var sel    = document.getElementById('bqw-country');
		var prefix = document.getElementById('bqw-dial-prefix');
		var dial   = document.getElementById('bqw-dial');

		(countries || []).forEach(function (c) {
			var opt = document.createElement('option');
			opt.value = c.code; opt.textContent = c.name; opt.dataset.dial = c.dial;
			if (c.code === detected) opt.selected = true;
			sel.appendChild(opt);

			if (prefix) {
				var p = document.createElement('option');
				p.value = c.dial;
				p.dataset.code = c.code;
				p.textContent = c.dial + ' — ' + c.name;
				if (c.code === detected) p.selected = true;
				prefix.appendChild(p);
			}
		});
		var match = (countries || []).find(function (c) { return c.code === detected; });
		if (match && dial) dial.value = match.dial;

		sel.addEventListener('change', function () {
			var opt = sel.options[sel.selectedIndex];
			var d = opt ? (opt.dataset.dial || '+') : '+';
			if (dial) dial.value = d;
			// Keep the prefix dropdown synced when the user picks a country.
			if (prefix) {
				for (var i = 0; i < prefix.options.length; i++) {
					if (prefix.options[i].value === d) { prefix.selectedIndex = i; break; }
				}
			}
		});
		if (prefix) {
			prefix.addEventListener('change', function () {
				var v = prefix.value;
				if (dial) dial.value = v;
			});
		}
	}

	/* =========================================================
	 * Submit (final form)
	 * ========================================================= */
	function ensureCaptchaToken() {
		var captcha = document.getElementById('bqw-captcha');
		if (!captcha) return Promise.resolve();
		var provider = captcha.dataset.provider;
		if (provider === 'recaptcha_v3' && cfg.captcha && cfg.captcha.site_key && window.grecaptcha) {
			return new Promise(function (resolve) {
				window.grecaptcha.ready(function () {
					window.grecaptcha.execute(cfg.captcha.site_key, { action: cfg.captcha.action || 'boprint_quote' })
						.then(function (token) { var t = document.getElementById('bqw-recaptcha-v3'); if (t) t.value = token; resolve(); })
						.catch(function () { resolve(); });
				});
			});
		}
		return Promise.resolve();
	}

	function submitForm(e) {
		e.preventDefault();
		var btn = document.getElementById('bqw-submit');
		var spinner = btn.querySelector('.bqw-spinner');
		var errorBox = document.getElementById('bqw-error');
		errorBox.hidden = true;
		btn.disabled = true;
		if (spinner) spinner.hidden = false;

		ensureCaptchaToken().then(function () {
			var fd = new FormData(form);
			var dialEl = document.getElementById('bqw-dial');
			var dial   = dialEl ? (dialEl.value || dialEl.textContent || '').trim() : '';
			fd.set('phone', (dial || '') + ' ' + (fd.get('phone') || ''));

			fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json && json.success) {
						if (json.data.redirect) { window.location.href = json.data.redirect; return; }
						root.querySelector('.bqw-chat').hidden = true;
						contactPanel.hidden = true;
						thanksBox.hidden = false;
						thanksBox.innerHTML = json.data.html || '';
						thanksBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
					} else {
						var msg = (json && json.data && json.data.message) || i18n.genericError || 'Error';
						errorBox.textContent = msg; errorBox.hidden = false;
					}
				})
				.catch(function () {
					errorBox.textContent = i18n.genericError || 'Error'; errorBox.hidden = false;
				})
				.finally(function () { btn.disabled = false; if (spinner) spinner.hidden = true; });
		});
	}

	/* =========================================================
	 * Helpers
	 * ========================================================= */
	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function iconForHint(hint) {
		var map = {
			'tshirt': '<path d="M16 4l4 3-2 4-2-1v10H8V10L6 11 4 7l4-3a4 4 0 008 0z"/>',
			'package': '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/>',
			'gift': '<rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M3 12h18v9H3z"/>',
			'factory': '<path d="M3 21V10l5 3V10l5 3V10l5 3v8H3z"/>',
			'home': '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
			'sign': '<path d="M4 4h12l4 4-4 4H4z"/><path d="M4 12v8"/>',
			'fabric': '<path d="M3 6c3 0 3 3 6 3s3-3 6-3 3 3 6 3M3 12c3 0 3 3 6 3s3-3 6-3 3 3 6 3"/>',
			'plastic': '<rect x="4" y="3" width="16" height="18" rx="2"/>',
			'wood': '<path d="M4 6c0 4 4 4 4 8s-4 4-4 8M10 6c0 4 4 4 4 8s-4 4-4 8M16 6c0 4 4 4 4 8s-4 4-4 8"/>',
			'metal': '<path d="M4 7l8-4 8 4-8 4z"/><path d="M4 12l8 4 8-4"/>',
			'glass': '<path d="M6 3h12l-2 11a4 4 0 01-8 0z"/><path d="M12 14v7"/>',
			'leather': '<path d="M5 4l3 3-1 4 4 2 5-1 3 3-3 4-4-1-1 4-4-2 1-5-3-3z"/>',
			'cardboard': '<path d="M3 7h18v14H3z"/><path d="M3 7l3-3h12l3 3"/>',
			'volume-low': '<circle cx="12" cy="12" r="3"/>',
			'volume-mid': '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7"/>',
			'volume-high': '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="9"/>',
			'format-small': '<rect x="9" y="6" width="6" height="12" rx="1"/>',
			'format-medium': '<rect x="6" y="5" width="12" height="14" rx="1"/>',
			'format-large': '<rect x="3" y="4" width="18" height="16" rx="1"/>',
			'budget-low': '<rect x="3" y="7" width="18" height="10" rx="2"/>',
			'budget-mid': '<circle cx="12" cy="12" r="9"/>',
			'budget-high': '<path d="M3 16l5-8 4 4 5-7 4 6"/>',
			'help': '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 0c0 1-1 1.5-2 2.2-.5.4-1 .9-1 1.6"/>',
		};
		var body = map[hint];
		if (!body) return '';
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
	}

	/* =========================================================
	 * Init
	 * ========================================================= */
	function resetRecPanel() {
		// Hard reset — clear any stale cards from a previous session.
		if (recPanelBody) {
			recPanelBody.innerHTML = '';
			var p = document.createElement('p');
			p.className = 'bqw-rec-placeholder';
			p.textContent = i18n.recPlaceholder || 'Your recommended machines will appear here as we chat.';
			recPanelBody.appendChild(p);
		}
		if (recPanelFoot) recPanelFoot.hidden = true;
		if (recFab) {
			recFab.classList.remove('is-shown');
			if (recFabCount) {
				recFabCount.textContent = '';
				recFabCount.style.display = 'none';
			}
		}
		if (recPanel) recPanel.classList.remove('is-open');
	}

	function init() {
		buildCountries();
		resetRecPanel();

		// v1.7.6 — chat input form was removed; nothing to wire here.
		skipBtn.addEventListener('click', function () { revealContactForm({ withHint: true }); });
		backToChat.addEventListener('click', function (e) { e.preventDefault(); hideContactForm(); });

		// Side-panel controls.
		if (recPanelClose) {
			recPanelClose.addEventListener('click', function () {
				if (recPanel) recPanel.classList.remove('is-open');
			});
		}
		if (recCta) {
			recCta.addEventListener('click', function () {
				if (recPanel) recPanel.classList.remove('is-open');
				revealContactForm({ withHint: false });
			});
		}
		if (recFab) {
			recFab.addEventListener('click', function () {
				if (recPanel) recPanel.classList.add('is-open');
			});
		}

		form.addEventListener('submit', submitForm);

		// Auto-start the conversation. The server picks the first scripted step.
		callInit();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
