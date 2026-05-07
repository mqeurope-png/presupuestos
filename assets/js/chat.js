/* Bomedia Quote Wizard — chatbot frontend (v1.7.1, scripted backbone). */
(function () {
	'use strict';
	if (typeof window.BQW === 'undefined') return;
	var root = document.getElementById('bqw-chat-wrap');
	if (!root) return;

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
		state.pending_buttons = [];

		var allow_free = !!step.allow_free_text;
		var allow_skip = !!step.allow_skip;
		textInput.disabled = !allow_free;
		textInput.placeholder = allow_free ? (i18n.typeHere || 'Or type your answer freely…') : (i18n.optionsOnly || 'Pick an option above…');

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
					// Single-select: click immediately advances.
					optionsWrap.querySelectorAll('.is-selected').forEach(function (e) { e.classList.remove('is-selected'); });
					btn.classList.add('is-selected');
					state.pending_buttons = [opt.label];
					sendAdvance();
				}
			});
			optionsWrap.appendChild(btn);
		});

		// Confirm button for multi-select / when free text is allowed.
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

	function renderRecommendations(recs) {
		if (!recs || !recs.length) return;
		var box = document.createElement('div');
		box.className = 'bqw-bubble bqw-bubble-assistant bqw-bubble-recs';
		var avatar = document.createElement('span');
		avatar.className = 'bqw-bubble-avatar'; avatar.textContent = 'B'; avatar.setAttribute('aria-hidden', 'true');
		box.appendChild(avatar);
		var body = document.createElement('div');
		body.className = 'bqw-bubble-body bqw-rec-list';
		recs.forEach(function (r) { body.appendChild(buildRecCard(r)); });
		box.appendChild(body);
		stream.appendChild(box);
		stream.scrollTo({ top: stream.scrollHeight, behavior: 'smooth' });
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
		var text   = textInput.value.trim();
		if (opts.skip) { clicks = []; text = ''; }
		if (!opts.skip && !clicks.length && !text) return;

		appendBubble('user', text, { chips: clicks });
		textInput.value = '';
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

	function applyStep(step) {
		if (!step) return;
		root.classList.add('is-flow-active');

		state.current_step = step.id;
		state.current_step_meta = step;

		// Always render the assistant bubble first, then any add-ons.
		if (step.message) appendBubble('assistant', step.message);

		switch (step.type) {
			case 'options':
			case 'multi_options':
			case 'single_option':
				renderOptions(step);
				break;

			case 'recommendations':
				renderRecommendations(step.recommendations || []);
				optionsWrap.innerHTML = '';
				renderCtaToContact(step.cta);
				if (step.cta_to === 'free_chat') {
					// Allow free-text Q&A immediately.
					textInput.disabled = false;
					textInput.placeholder = step.free_chat_hint || (i18n.askAnything || 'Ask anything about these machines…');
				} else {
					textInput.disabled = true;
				}
				break;

			case 'free_chat':
				optionsWrap.innerHTML = '';
				renderCtaToContact(step.cta);
				textInput.disabled = false;
				textInput.placeholder = i18n.askAnything || 'Ask anything…';
				break;

			case 'form':
				revealContactForm({ withHint: false });
				if (step.origin_tag === 'knows-machine') {
					state.flow_origin = 'knows-machine';
					document.getElementById('bqw-flow').value = 'knows-machine';
				}
				break;

			case 'end':
			default:
				optionsWrap.innerHTML = '';
				textInput.disabled = true;
				break;
		}
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
		var sel  = document.getElementById('bqw-country');
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
			var dial = document.getElementById('bqw-dial').textContent.trim();
			fd.set('phone', dial + ' ' + (fd.get('phone') || ''));

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
	function init() {
		buildCountries();

		chatForm.addEventListener('submit', function (e) {
			e.preventDefault();
			sendAdvance();
		});
		textInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				chatForm.requestSubmit ? chatForm.requestSubmit() : sendAdvance();
			}
		});
		skipBtn.addEventListener('click', function () { revealContactForm({ withHint: true }); });
		backToChat.addEventListener('click', function (e) { e.preventDefault(); hideContactForm(); });

		form.addEventListener('submit', submitForm);

		// Auto-start the conversation. The server picks the first scripted step.
		callInit();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
