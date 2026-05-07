/* Bomedia Quote Wizard — multi-screen wizard frontend (v1.7.9). */
(function () {
	'use strict';
	try { console.log('[bqw wizard] script loaded'); } catch (e) {}

	if (typeof window.BQW === 'undefined') {
		try { console.warn('[bqw wizard] window.BQW is undefined.'); } catch (e) {}
		return;
	}
	var root = document.getElementById('bqw-chat-wrap');
	if (!root) return;

	var cfg       = window.BQW.config || {};
	var i18n      = window.BQW.i18n || {};
	var countries = window.BQW.countries || [];
	var detected  = window.BQW.detectedCountry || 'ES';

	var screen     = document.getElementById('bqw-wiz-screen');
	var progress   = document.getElementById('bqw-wiz-progress');
	var dotsEl     = document.getElementById('bqw-wiz-dots');
	var stepLabel  = document.getElementById('bqw-wiz-step-label');
	var backBtn    = document.getElementById('bqw-wiz-back');
	// v1.7.10 — tray is rendered inline inside the active screen.
	var tray = null, trayLabel = null, trayPills = null, trayCta = null;
	var form       = document.getElementById('bqw-form');
	var thanksBox  = document.getElementById('bqw-thanks');
	var redirectURL = root.dataset.redirect || '';
	var lang       = root.dataset.language || 'en';

	// Question steps (matches Chat::script() ids for progress numbering).
	var QUESTION_STEPS = ['task_type', 'application', 'materials', 'volume', 'format', 'budget'];

	var state = {
		session_id: ensureSessionId(),
		contact_partial: { name: '', email: '' },
		selection: [],
		stack: [],          // [{kind: 'server'|'intro', step: {...}, picks: [labels]}]
		current: null,      // current frame
		flow_origin: 'wizard',
		question_steps_seen: [],
	};
	document.getElementById('bqw-session-id').value = state.session_id;

	backBtn.addEventListener('click', goBack);

	/* =========================================================
	 * Session
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
	 * Helpers
	 * ========================================================= */
	function el(tag, cls, html) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (html != null) e.innerHTML = html;
		return e;
	}
	function bqwInterpolate(text, vars) {
		if (!text || typeof text !== 'string') return text || '';
		var bag = vars || {
			nombre: state.contact_partial.name || '',
			site_display_name: (cfg.site_display_name || ''),
			bot_name: (cfg.bot_name || ''),
			n: state.selection.length,
		};
		return text.replace(/\{(\w+)\}/g, function (m, k) {
			return (bag[k] !== undefined && bag[k] !== null) ? String(bag[k]) : m;
		});
	}

	// v1.7.13 — bot avatar + name shown on the intro screen.
	function buildBotIdentity() {
		var wrap = el('div', 'bqw-bot-identity');
		var avatar;
		if (cfg.bot_avatar_url) {
			avatar = el('img', 'bqw-bot-avatar bqw-bot-avatar-img');
			avatar.src = cfg.bot_avatar_url;
			avatar.alt = '';
			avatar.loading = 'lazy';
		} else {
			avatar = el('span', 'bqw-bot-avatar', escapeHtml(cfg.bot_initial || 'B'));
			avatar.style.background = cfg.bot_avatar_color || '#0066cc';
		}
		wrap.appendChild(avatar);
		var name = el('span', 'bqw-bot-name', escapeHtml(cfg.bot_name || 'Bomedia'));
		wrap.appendChild(name);
		return wrap;
	}
	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function clearScreen() {
		// Quick flash transition (~200ms total). Replaces innerHTML synchronously;
		// the entering frame fades in via CSS .is-entering -> active.
		screen.classList.remove('is-leaving');
		screen.classList.remove('is-entering');
		screen.innerHTML = '';
		root.classList.add('is-flow-active');
		// Reflow + entering class to trigger the 150ms fade-in.
		// eslint-disable-next-line no-unused-expressions
		screen.offsetWidth;
		screen.classList.add('is-entering');
		requestAnimationFrame(function () { screen.classList.remove('is-entering'); });
	}
	function setProgress(curIdx, total) {
		if (total <= 0) { progress.hidden = true; return; }
		progress.hidden = false;
		dotsEl.innerHTML = '';
		for (var i = 0; i < total; i++) {
			var d = el('span', 'bqw-wiz-dot' + (i < curIdx ? ' is-done' : i === curIdx ? ' is-active' : ''));
			dotsEl.appendChild(d);
		}
		stepLabel.textContent = (i18n.stepCounter || 'Step %1$d of %2$d').replace('%1$d', curIdx + 1).replace('%2$d', total);
	}
	function showBack(show) { backBtn.hidden = !show; }

	// Inline tray (rendered inside the active screen, between filters and grid
	// on catalog screens; before the action row on recommendations).
	// Returns a DOM node or null when there is no selection.
	function buildInlineTray() {
		var n = state.selection.length;
		if (n === 0) return null;
		var box = el('div', 'bqw-tray-inline');
		var head = el('div', 'bqw-tray-inline-head');
		head.innerHTML = '<span class="bqw-tray-inline-label">' +
			escapeHtml(bqwInterpolate((i18n.trayN || 'Your request ({n}):'))) + '</span>';
		var cta = el('button', 'bqw-btn bqw-btn-primary bqw-tray-inline-cta');
		cta.type = 'button';
		cta.textContent = (i18n.requestQuote || 'Request quote') + ' →';
		cta.addEventListener('click', goToFinalForm);
		head.appendChild(cta);
		box.appendChild(head);

		var pillsRow = el('div', 'bqw-tray-inline-pills');
		state.selection.forEach(function (p) {
			var pill = el('button', 'bqw-wiz-pill');
			pill.type = 'button';
			pill.innerHTML = escapeHtml(p.name) + ' <span aria-hidden="true">×</span>';
			pill.addEventListener('click', function () {
				removeSelection(p.id);
				if (state.current && state.current.kind === 'server') {
					applyStep(state.current.step, /*replay*/ true);
				} else if (state.current && state.current.kind === 'form') {
					goToFinalForm(/*replay*/ true);
				}
			});
			pillsRow.appendChild(pill);
		});
		box.appendChild(pillsRow);
		return box;
	}

	// v1.7.11 — soft reset: returns to the welcome step keeping the captured
	// name + email so the user does not retype them. To change those, the
	// user can press Back from the welcome screen.
	function softReset() {
		state.selection = [];
		state.stack = [];
		state.current = null;
		state.question_steps_seen = [];
		document.getElementById('bqw-selected-products-json').value = '[]';
		document.getElementById('bqw-flow').value = 'wizard';
		state.flow_origin = 'wizard';
		// The intro frame represents the captured name+email screen.
		state.stack.push({ kind: 'intro', payload: null, picks: [] });
		callInit();
	}

	function repaintCurrentInlineTray() {
		var anchor = screen.querySelector('.bqw-tray-anchor');
		if (!anchor) return;
		anchor.innerHTML = '';
		var t = buildInlineTray();
		if (t) anchor.appendChild(t);
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

	function questionProgress(stepId) {
		// Compute index of this step in the enabled chain so the dots reflect
		// the ACTUAL questions the user will see. We learn the chain on the fly.
		if (state.question_steps_seen.indexOf(stepId) === -1) state.question_steps_seen.push(stepId);
		var idx = state.question_steps_seen.indexOf(stepId);
		// Total = max(seen so far, default 6 if before we know).
		var total = Math.max(state.question_steps_seen.length, 4);
		// Cap to QUESTION_STEPS length.
		total = Math.min(total, QUESTION_STEPS.length);
		return { idx: idx, total: total };
	}

	function stripDiacritics(s) {
		try { return s.normalize('NFD').replace(/[̀-ͯ]/g, ''); } catch (e) { return s; }
	}
	// v1.7.11 — keyword → icon hint (used when the server-side option has no
	// explicit icon). Returns null when no match so the card renders icon-less.
	function guessIconHint(label) {
		if (!label) return null;
		var s = stripDiacritics(String(label).toLowerCase());
		var rules = [
			[/\b(personalizac|personal|custom)/, 'sparkles'],
			[/\b(industrial|industria|fabrica|factory)/, 'factory'],
			[/\b(senaletic|signage|sign|cartel|rotulo)/, 'sign'],
			[/\b(decorac|decor|interior)/, 'home'],
			[/\b(merchan|publicidad)/, 'megaphone'],
			[/\b(gadget|regalo|gift)/, 'gift'],
			[/\b(envase|caja|box|packag)/, 'package'],
			[/\b(etiqueta|label|sticker)/, 'tag'],
			[/\b(funda|case|movil|phone)/, 'phone'],
			[/\b(joya|joyer|jewel)/, 'gem'],
			[/\b(art|arte|creativ)/, 'palette'],
			[/\b(moda|fashion|ropa|textil|camiseta|tshirt|t-shirt)/, 'tshirt'],
			[/\b(precision|micro)/, 'crosshair'],
			[/\b(rapido|fast|alta velocidad|velocidad)/, 'zap'],
			[/\b(uv-led|uv led|uv)/, 'package'],
			[/\b(laser|laser)/, 'metal'],
			[/\b(madera|wood)/, 'wood'],
			[/\b(metal|aluminio|acero)/, 'metal'],
			[/\b(cristal|vidrio|glass)/, 'glass'],
			[/\b(cuero|piel|leather)/, 'leather'],
			[/\b(carton|cardboard)/, 'cardboard'],
			[/\b(plastic|acrilico)/, 'plastic'],
			[/\b(papel|paper)/, 'cardboard'],
			[/\b(tela|fabric|tejido)/, 'fabric'],
			[/\b(pequen|pequeñ|low|peque)/, 'minus-circle'],
			[/\b(medio|mid|medium)/, 'circle'],
			[/\b(grande|gran|big|alto|high)/, 'plus-circle'],
			[/\b(a4|a3|formato pequeno|peq)/, 'format-small'],
			[/\b(60|format-medium|mediano)/, 'format-medium'],
			[/\b(grande|format-large|mayor|xl)/, 'format-large'],
			[/\b(presupuesto|euros|€|low|hasta)/, 'budget-low'],
		];
		for (var i = 0; i < rules.length; i++) {
			if (rules[i][0].test(s)) return rules[i][1];
		}
		return null;
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
			'sparkles': '<path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z"/><path d="M19 15l.7 2.1L22 18l-2.3.9L19 21l-.7-2.1L16 18l2.3-.9L19 15z"/>',
			'megaphone': '<path d="M3 11v2a3 3 0 003 3h1l4 4V4l-4 4H6a3 3 0 00-3 3z"/><path d="M14 8a4 4 0 010 8"/>',
			'tag': '<path d="M20 12V4h-8L3 13l8 8 9-9z"/><circle cx="15" cy="9" r="1.2" fill="currentColor"/>',
			'phone': '<rect x="7" y="2" width="10" height="20" rx="2"/><circle cx="12" cy="18" r="1" fill="currentColor"/>',
			'gem': '<path d="M6 3h12l3 6-9 12L3 9z"/><path d="M3 9h18"/><path d="M12 3l-3 6 3 12 3-12-3-6z"/>',
			'palette': '<path d="M12 3a9 9 0 100 18c1 0 2-1 1-2-.5-.5-.5-1.5 0-2 1-1 3 0 5-1a8 8 0 00-6-13z"/><circle cx="8" cy="10" r="1" fill="currentColor"/><circle cx="12" cy="6" r="1" fill="currentColor"/><circle cx="16" cy="10" r="1" fill="currentColor"/>',
			'crosshair': '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M1 12h4M19 12h4"/>',
			'zap': '<path d="M13 2L4 14h7l-2 8 9-12h-7l2-8z"/>',
			'minus-circle': '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
			'circle': '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4" fill="currentColor"/>',
			'plus-circle': '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
		};
		var body = map[hint];
		if (!body) return '';
		return '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
	}

	/* =========================================================
	 * Screen 1 — Contact intro (early lead capture)
	 * ========================================================= */
	function renderContactIntro() {
		clearScreen();
		showBack(false);
		// tray inline only when needed
		progress.hidden = true;

		var wrap = el('div', 'bqw-wiz-card bqw-wiz-intro');
		wrap.appendChild(buildBotIdentity());
		var hbox = el('div');
		hbox.innerHTML =
			'<h2 class="bqw-wiz-h">' + escapeHtml(bqwInterpolate(i18n.introTitle || 'Before we start, what should we call you?')) + '</h2>' +
			'<p class="bqw-wiz-sub">' + escapeHtml(bqwInterpolate(i18n.introSub || 'It takes 2 minutes. No spam — we only reply to your enquiry.')) + '</p>';
		wrap.appendChild(hbox);
		// (the rest of the intro card follows below)
		var fields = document.createElement('div');
		fields.innerHTML =
			'<label class="bqw-wiz-field"><span>' + escapeHtml(i18n.firstName || 'First name') + ' *</span>' +
				'<input type="text" id="bqw-intro-name" autocomplete="given-name" value="' + escapeHtml(state.contact_partial.name) + '" required></label>' +
			'<label class="bqw-wiz-field"><span>' + escapeHtml(i18n.email || 'Email') + ' *</span>' +
				'<input type="email" id="bqw-intro-email" autocomplete="email" value="' + escapeHtml(state.contact_partial.email) + '" required></label>' +
			'<p class="bqw-wiz-error" id="bqw-intro-error" hidden></p>' +
			'<div class="bqw-wiz-actions"><button type="button" class="bqw-btn bqw-btn-primary" id="bqw-intro-continue">' + escapeHtml(i18n.continue || 'Continue') + ' →</button></div>';
		wrap.appendChild(fields);
		screen.appendChild(wrap);

		var nameEl = wrap.querySelector('#bqw-intro-name');
		var mailEl = wrap.querySelector('#bqw-intro-email');
		var errEl  = wrap.querySelector('#bqw-intro-error');
		var btn    = wrap.querySelector('#bqw-intro-continue');
		nameEl.focus();

		btn.addEventListener('click', function () {
			var name  = nameEl.value.trim();
			var email = mailEl.value.trim();
			if (!name) { errEl.hidden = false; errEl.textContent = i18n.errName || 'First name is required.'; return; }
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { errEl.hidden = false; errEl.textContent = i18n.errEmail || 'Please enter a valid email.'; return; }
			errEl.hidden = true;
			state.contact_partial.name  = name;
			state.contact_partial.email = email;
			document.getElementById('bqw-first-name').value = name;
			document.getElementById('bqw-email').value = email;
			btn.disabled = true;
			savePartial(name, email).then(function () {
				state.stack.push({ kind: 'intro', payload: null, picks: [] });
				callInit();
			}).catch(function () {
				// Even if save fails we proceed; the lead is captured at submit anyway.
				state.stack.push({ kind: 'intro', payload: null, picks: [] });
				callInit();
			});
		});
		[nameEl, mailEl].forEach(function (n) {
			n.addEventListener('keydown', function (e) { if (e.key === 'Enter') btn.click(); });
		});
	}

	function savePartial(name, email) {
		var fd = new FormData();
		fd.append('action', 'bqw_partial_save');
		fd.append('nonce', window.BQW.nonce);
		fd.append('session_id', state.session_id);
		fd.append('name', name);
		fd.append('email', email);
		return fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}
	function logPartialStep(stepId) {
		try {
			var fd = new FormData();
			fd.append('action', 'bqw_partial_step');
			fd.append('nonce', window.BQW.nonce);
			fd.append('session_id', state.session_id);
			fd.append('step', stepId);
			fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd, keepalive: true });
		} catch (e) {}
	}

	/* =========================================================
	 * Server protocol
	 * ========================================================= */
	function callInit() {
		var fd = new FormData();
		fd.append('action', 'bqw_chat_init');
		fd.append('nonce', window.BQW.nonce);
		fd.append('session_id', state.session_id);
		fd.append('language', lang);
		fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || !json.success) { renderError(); return; }
				applyStep(json.data.step);
			})
			.catch(renderError);
	}

	function callAdvance(currentStep, picks, freeText, skip) {
		var fd = new FormData();
		fd.append('action', 'bqw_chat_advance');
		fd.append('nonce', window.BQW.nonce);
		fd.append('session_id', state.session_id);
		fd.append('step_id', currentStep);
		fd.append('language', lang);
		picks.forEach(function (p) { fd.append('selected[]', p); });
		if (freeText) fd.append('free_text', freeText);
		if (skip) fd.append('skip', '1');
		return fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}

	// v1.7.11 — fire OpenAI in background as soon as the user enters the
	// last question step so the recommendations land instantly when they
	// click Next. The server caches the result by session_id (10 min TTL).
	var prefetchTriggered = {};
	function maybePrefetchRecs(currentStepId) {
		var lastQuestions = ['budget', 'format', 'volume'];
		if (lastQuestions.indexOf(currentStepId) === -1) return;
		if (prefetchTriggered[state.session_id]) return;
		prefetchTriggered[state.session_id] = true;
		try {
			var fd = new FormData();
			fd.append('action', 'bqw_prefetch_recs');
			fd.append('nonce', window.BQW.nonce);
			fd.append('session_id', state.session_id);
			fd.append('language', lang);
			fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd, keepalive: true });
		} catch (e) {}
	}
	function invalidatePrefetch() { prefetchTriggered[state.session_id] = false; }

	function fetchMoreRecs(excludeIds) {
		var fd = new FormData();
		fd.append('action', 'bqw_more_recs');
		fd.append('nonce', window.BQW.nonce);
		fd.append('session_id', state.session_id);
		fd.append('language', lang);
		excludeIds.forEach(function (id) { fd.append('exclude_ids[]', String(id)); });
		return fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}

	function applyStep(step, replay) {
		if (!step) { renderError(); return; }
		if (!replay) {
			state.current = { kind: 'server', step: step, picks: [] };
		} else {
			// Replaying same screen (e.g. tray pill removal). Keep frame, re-render.
		}
		logPartialStep(step.id);

		// Pre-launch the OpenAI call when the user lands on the last enabled
		// question step (we can't know in advance which step is "last", so we
		// trigger on any of the late steps; the server transient is keyed by
		// session_id and TTLs out in 10 minutes).
		maybePrefetchRecs(step.id);

		switch (step.type) {
			case 'welcome_cards':       return renderWelcome(step);
			case 'options':
			case 'multi_options':
			case 'single_option':       return renderQuestion(step);
			case 'recommendations':     return renderRecommendationsScreen(step);
			case 'site_catalog':        return renderSiteCatalog(step);
			case 'bomedia_catalog':     return renderBomediaCatalog(step);
			case 'form':                return renderFinalForm(step);
			case 'end':
			default:                    return renderError();
		}
	}

	function pushAndAdvance(currentStep, picks, freeText, skip) {
		// Snapshot current frame onto the stack before transitioning.
		if (state.current) state.stack.push(state.current);
		callAdvance(currentStep, picks, freeText, skip).then(function (json) {
			if (!json || !json.success) {
				// Roll back stack push.
				state.stack.pop();
				renderError(json && json.data && json.data.message);
				return;
			}
			applyStep(json.data.step);
		}).catch(function () {
			state.stack.pop();
			renderError();
		});
	}

	function goBack() {
		// Going back may invalidate the in-flight OpenAI prefetch (answers
		// could change). The server transient still expires; this only
		// resets the client trigger so we re-fire when the user reaches the
		// late question again.
		invalidatePrefetch();
		var prev = state.stack.pop();
		if (!prev) { renderContactIntro(); return; }
		if (prev.kind === 'intro') { renderContactIntro(); return; }
		if (prev.kind === 'server') {
			state.current = prev;
			applyStep(prev.step, /*replay*/ true);
		}
	}

	function renderError(msg) {
		clearScreen();
		showBack(state.stack.length > 0);
		// tray inline only when needed
		progress.hidden = true;
		screen.appendChild(el('div', 'bqw-wiz-card', '<p class="bqw-wiz-error">' + escapeHtml(msg || i18n.genericError || 'Something went wrong.') + '</p>'));
	}

	/* =========================================================
	 * Screen — Welcome (3 cards)
	 * ========================================================= */
	function renderWelcome(step) {
		clearScreen();
		showBack(true);
		// tray inline only when needed
		progress.hidden = true;

		var card = el('div', 'bqw-wiz-card');
		// Server already produced step.message from the editable copy; we still
		// run it through bqwInterpolate so {nombre}/{n} variables resolve.
		var greet = bqwInterpolate(step.message || i18n.welcomeGreetingTpl || 'Hi {nombre}, how would you like to choose?');
		card.appendChild(el('h2', 'bqw-wiz-h', escapeHtml(greet)));
		var grid = el('div', 'bqw-welcome-grid');
		(step.options || []).forEach(function (opt) {
			var c = el('button', 'bqw-welcome-card');
			c.type = 'button';
			var accent = opt.value || '';
			if (accent) c.classList.add('bqw-welcome-card--' + accent.replace(/_/g, '-'));
			c.innerHTML = '<span class="bqw-welcome-icon">' + welcomeIcon(opt.value, opt.icon) + '</span>'
				+ '<strong>' + escapeHtml(opt.label) + '</strong>'
				+ (opt.subtitle ? '<span class="bqw-welcome-sub">' + escapeHtml(opt.subtitle) + '</span>' : '');
			c.addEventListener('click', function () { pushAndAdvance(step.id, [opt.label]); });
			grid.appendChild(c);
		});
		card.appendChild(grid);
		screen.appendChild(card);
	}

	function welcomeIcon(value, fallbackHint) {
		// Distinct silhouettes per welcome card so the 3 options read differently.
		var shape = '';
		if (value === 'guided') {
			// Magic wand + sparkles.
			shape = '<path d="M5 19l9-9"/><path d="M14 5l5 5"/><path d="M14 5l-1.5-2 2 .5L16 2l.5 2 2-.5L17 5l1.5 2-2-.5L16 8l-.5-2-2 .5L14 5z" fill="currentColor" stroke="none"/><circle cx="20" cy="14" r="1" fill="currentColor"/><circle cx="3" cy="9" r="1" fill="currentColor"/>';
		} else if (value === 'site_catalog') {
			// Storefront + awning.
			shape = '<path d="M3 9h18l-1 11H4z"/><path d="M3 9l2-5h14l2 5"/><path d="M9 14h6v6H9z"/>';
		} else if (value === 'bomedia_catalog') {
			// Globe with meridians.
			shape = '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c3 3 3 15 0 18"/><path d="M12 3c-3 3-3 15 0 18"/>';
		} else {
			return fallbackHint ? iconForHint(fallbackHint) : '★';
		}
		return '<svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + shape + '</svg>';
	}

	/* =========================================================
	 * Screen — Question (multi/single options)
	 * ========================================================= */
	function renderQuestion(step) {
		clearScreen();
		showBack(true);
		// tray inline only when needed
		var pg = questionProgress(step.id);
		setProgress(pg.idx, pg.total);

		var card = el('div', 'bqw-wiz-card');
		card.appendChild(el('h2', 'bqw-wiz-h', escapeHtml(bqwInterpolate(step.message || ''))));
		var grid = el('div', 'bqw-q-grid');
		var multi = !!step.multi_select;
		var picks = (state.current.picks || []).slice();

		(step.options || []).forEach(function (opt) {
			var c = el('button', 'bqw-q-card');
			c.type = 'button';
			var hint = opt.icon || guessIconHint(opt.label);
			var iconHtml = hint ? iconForHint(hint) : '';
			c.innerHTML = (iconHtml ? '<span class="bqw-q-icon">' + iconHtml + '</span>' : '')
				+ '<span class="bqw-q-label">' + escapeHtml(opt.label) + '</span>';
			if (!iconHtml) c.classList.add('bqw-q-card--noicon');
			if (picks.indexOf(opt.label) >= 0) c.classList.add('is-selected');
			c.addEventListener('click', function () {
				if (multi) {
					var idx = picks.indexOf(opt.label);
					if (idx >= 0) { picks.splice(idx, 1); c.classList.remove('is-selected'); }
					else { picks.push(opt.label); c.classList.add('is-selected'); }
				} else {
					grid.querySelectorAll('.bqw-q-card.is-selected').forEach(function (x) { x.classList.remove('is-selected'); });
					c.classList.add('is-selected');
					picks = [opt.label];
				}
				state.current.picks = picks;
				nextBtn.disabled = picks.length === 0;
			});
			grid.appendChild(c);
		});
		card.appendChild(grid);

		var actions = el('div', 'bqw-wiz-actions bqw-wiz-actions-row');
		if (step.allow_skip) {
			var skipBtn = el('button', 'bqw-link-btn');
			skipBtn.type = 'button';
			skipBtn.textContent = i18n.skipQuestion || 'Skip this question';
			skipBtn.addEventListener('click', function () { pushAndAdvance(step.id, [], '', true); });
			actions.appendChild(skipBtn);
		}
		var nextBtn = el('button', 'bqw-btn bqw-btn-primary');
		nextBtn.type = 'button';
		nextBtn.textContent = (i18n.next || 'Next') + ' →';
		nextBtn.disabled = picks.length === 0;
		nextBtn.addEventListener('click', function () { pushAndAdvance(step.id, picks); });
		actions.appendChild(nextBtn);
		card.appendChild(actions);
		screen.appendChild(card);
	}

	/* =========================================================
	 * Screen — Recommendations (top 6, mosaic 3x2)
	 * ========================================================= */
	function renderRecommendationsScreen(step) {
		clearScreen();
		showBack(true);
		// tray rendered inline in this screen
		progress.hidden = true;

		var card = el('div', 'bqw-wiz-card bqw-wiz-card-wide');
		var recs = step.recommendations || [];
		var heading = recs.length
			? (i18n.recsTitle || 'Your top matches')
			: (i18n.noMatches || "Your case is specific. Let's talk directly.");
		card.appendChild(el('h2', 'bqw-wiz-h', escapeHtml(heading)));
		if (recs.length) {
			card.appendChild(el('p', 'bqw-wiz-sub', escapeHtml(bqwInterpolate((i18n.recsSub || 'We found {n} machines for you'), { n: recs.length, nombre: state.contact_partial.name || '', site_display_name: cfg.site_display_name || '' }))));
		}

		var grid = el('div', 'bqw-rec-mosaic');
		var moreBtn = el('button', 'bqw-link-btn bqw-rec-more');
		moreBtn.type = 'button';
		var moreUsed = 0;
		var shownIds = recs.map(function (r) { return String(r.id); });
		moreBtn.textContent = '↻ ' + (i18n.viewMoreRecs || 'View other options');

		if (!recs.length) {
			var msg = el('p', 'bqw-wiz-sub', escapeHtml(bqwInterpolate(step.message || '')));
			card.appendChild(msg);
		} else {
			recs.forEach(function (r) { grid.appendChild(buildRecCard(r)); });
			card.appendChild(grid);
		}

		function loadMore() {
			moreBtn.disabled = true;
			moreBtn.textContent = '…';
			fetchMoreRecs(shownIds).then(function (json) {
				var more = (json && json.success && json.data && json.data.recommendations) || [];
				if (!more.length) {
					moreBtn.replaceWith(el('p', 'bqw-wiz-sub bqw-rec-no-more', escapeHtml(i18n.noMoreRecs || "You've seen all the available options.")));
					return;
				}
				grid.innerHTML = '';
				more.forEach(function (r) { grid.appendChild(buildRecCard(r)); shownIds.push(String(r.id)); });
				moreUsed++;
				moreBtn.disabled = false;
				moreBtn.textContent = '↻ ' + (i18n.anotherSpin || 'Another spin');
			}).catch(function () {
				moreBtn.disabled = false;
				moreBtn.textContent = '↻ ' + (i18n.viewMoreRecs || 'View other options');
			});
		}
		moreBtn.addEventListener('click', loadMore);
		if (recs.length) card.appendChild(moreBtn);

		var trayAnchor = el('div', 'bqw-tray-anchor');
		var t = buildInlineTray(); if (t) trayAnchor.appendChild(t);
		card.appendChild(trayAnchor);

		var actions = el('div', 'bqw-wiz-actions bqw-wiz-actions-row');
		var restart = el('button', 'bqw-link-btn');
		restart.type = 'button';
		restart.textContent = i18n.startOver || '↺ Start over';
		restart.addEventListener('click', softReset);
		actions.appendChild(restart);

		var nope = el('button', 'bqw-link-btn');
		nope.type = 'button';
		nope.textContent = i18n.notConvinced || 'Not convinced — contact me';
		nope.addEventListener('click', function () {
			document.getElementById('bqw-flow').value = 'not-convinced';
			state.flow_origin = 'not-convinced';
			pushAndAdvance(step.id, [], '', true);
		});
		actions.appendChild(nope);

		var primary = el('button', 'bqw-btn bqw-btn-primary');
		primary.type = 'button';
		primary.textContent = (i18n.continueWith || 'Continue with selected') + ' →';
		primary.addEventListener('click', goToFinalForm);
		actions.appendChild(primary);
		card.appendChild(actions);

		screen.appendChild(card);
	}

	function buildRecCard(r) {
		var card = el('div', 'bqw-rec-card-compact');
		card.dataset.productId = String(r.id);
		var image    = r.img || r.image || '';
		var imgHtml  = image
			? '<img class="bqw-rec-thumb" src="' + image + '" alt="" loading="lazy">'
			: '<span class="bqw-rec-thumb bqw-rec-thumb-fallback">★</span>';
		var taskBadge = r.task_label
			? '<span class="bqw-rec-thumb-badge"><span class="bqw-rec-task" data-task="' + escapeHtml(r.task_type || '') + '">' + escapeHtml(r.task_label) + '</span></span>'
			: '';
		var brandHtml = r.brand ? '<span class="bqw-rec-brand">' + escapeHtml(r.brand) + '</span>' : '';
		var scoreHtml = r.score
			? '<span class="bqw-rec-score">' + escapeHtml(i18n.matchScore || 'Matches at') + ' ' + r.score + '%</span>'
			: '';
		card.innerHTML =
			'<div class="bqw-rec-thumbwrap">' + imgHtml + taskBadge + '</div>' +
			'<div class="bqw-rec-compact-body">' +
				'<strong class="bqw-rec-title">' + escapeHtml(r.name) + '</strong>' +
				brandHtml +
				scoreHtml +
			'</div>' +
			'<button type="button" class="bqw-rec-pick-compact" aria-pressed="false">' +
				'<span class="bqw-rec-pick-icon" aria-hidden="true">+</span>' +
				'<span class="bqw-rec-pick-label">' + escapeHtml(i18n.addToRequest || 'Add to request') + '</span>' +
			'</button>';

		var pickBtn = card.querySelector('.bqw-rec-pick-compact');
		var isPicked = state.selection.some(function (s) { return s.id === r.id; });
		if (isPicked) {
			card.classList.add('selected');
			pickBtn.setAttribute('aria-pressed', 'true');
			pickBtn.querySelector('.bqw-rec-pick-icon').textContent = '✓';
			pickBtn.querySelector('.bqw-rec-pick-label').textContent = i18n.added || 'Added';
		}
		card.addEventListener('click', function () {
			var pick = !card.classList.contains('selected');
			if (pick) {
				card.classList.add('selected');
				pickBtn.setAttribute('aria-pressed', 'true');
				pickBtn.querySelector('.bqw-rec-pick-icon').textContent = '✓';
				pickBtn.querySelector('.bqw-rec-pick-label').textContent = i18n.added || 'Added';
				addSelection({
					id: r.id, name: r.name, image: image, sku: r.sku || '',
					brand: r.brand || '', price: r.price || '', area: r.area || '', link: r.link || '',
					source: 'catalog',
					categoryId: 0, categorySlug: r.brand || '', categoryName: r.brand || '',
				});
			} else {
				card.classList.remove('selected');
				pickBtn.setAttribute('aria-pressed', 'false');
				pickBtn.querySelector('.bqw-rec-pick-icon').textContent = '+';
				pickBtn.querySelector('.bqw-rec-pick-label').textContent = i18n.addToRequest || 'Add to request';
				removeSelection(r.id);
			}
		repaintCurrentInlineTray();
		});
		return card;
	}

	/* =========================================================
	 * Chips multi-select filter (catalog screens)
	 * ========================================================= */
	function buildChipFilter(opts) {
		var wrap = el('div', 'bqw-chip-row');
		wrap.appendChild(el('span', 'bqw-chip-label', escapeHtml(opts.label)));
		var collapseAfter = opts.collapseAfter || 8;
		var visibleCount  = opts.items.length > collapseAfter ? 6 : opts.items.length;
		var collapsed     = opts.items.length > collapseAfter;
		function paint() {
			wrap.querySelectorAll('.bqw-chip-filter, .bqw-chip-more').forEach(function (n) { n.remove(); });
			var limit = collapsed ? visibleCount : opts.items.length;
			for (var i = 0; i < limit; i++) (function (it) {
				var c = el('button', 'bqw-chip bqw-chip-filter');
				c.type = 'button';
				c.dataset.value = it.value;
				c.textContent = (opts.active.has(it.value) ? '× ' : '') + it.label;
				if (opts.active.has(it.value)) c.classList.add('bqw-chip--active');
				c.addEventListener('click', function () {
					if (opts.active.has(it.value)) opts.active.delete(it.value);
					else opts.active.add(it.value);
					paint();
					opts.onChange();
				});
				wrap.appendChild(c);
			})(opts.items[i]);
			if (collapsed && opts.items.length > visibleCount) {
				var more = el('button', 'bqw-chip bqw-chip-more');
				more.type = 'button';
				more.textContent = '+ ' + (opts.items.length - visibleCount) + ' ' + (i18n.moreSuffix || 'more');
				more.addEventListener('click', function () { collapsed = false; paint(); });
				wrap.appendChild(more);
			}
		}
		paint();
		return wrap;
	}

	/* =========================================================
	 * Screen — Site catalog (Woo full screen)
	 * ========================================================= */
	function renderSiteCatalog(step) {
		clearScreen();
		showBack(true);
		// tray rendered inline in this screen
		progress.hidden = true;

		var card = el('div', 'bqw-wiz-card bqw-wiz-card-wide bqw-wiz-card-tall');
		card.appendChild(el('h2', 'bqw-wiz-h', escapeHtml(bqwInterpolate(step.message || ''))));

		var filters = el('div', 'bqw-browse-filters');
		var search  = el('input', null);
		search.type = 'search';
		search.id   = 'bqw-browse-search';
		search.placeholder = i18n.searchMachines || 'Search machines…';
		filters.appendChild(search);
		var chipBox = el('div', 'bqw-chip-filters');
		filters.appendChild(chipBox);
		card.appendChild(filters);

		var trayAnchor = el('div', 'bqw-tray-anchor');
		var t0 = buildInlineTray(); if (t0) trayAnchor.appendChild(t0);
		card.appendChild(trayAnchor);

		var grid = el('div', 'bqw-browse-grid');
		card.appendChild(grid);
		screen.appendChild(card);

		var products = (step.products || []).slice();
		var cats     = step.categories || [];
		var activeCats = new Set();

		function paint() {
			var q = (search.value || '').toLowerCase().trim();
			grid.innerHTML = '';
			products.forEach(function (p) {
				if (q && (p.name || '').toLowerCase().indexOf(q) === -1) return;
				if (activeCats.size) {
					var slugs = p.category_slugs && p.category_slugs.length ? p.category_slugs : [p.category_slug];
					var hit = false;
					for (var i = 0; i < slugs.length; i++) { if (activeCats.has(slugs[i])) { hit = true; break; } }
					if (!hit) return;
				}
				grid.appendChild(buildBrowseCard(p, /*woo*/true));
			});
		}
		var catItems = cats.map(function (c) { return { value: c.slug, label: c.name + ' (' + c.count + ')' }; });
		if (catItems.length) {
			chipBox.appendChild(buildChipFilter({
				label: (i18n.categoryLabel || 'Category') + ':',
				items: catItems, active: activeCats, onChange: paint,
			}));
		}
		search.oninput = paint;
		paint();
	}

	/* =========================================================
	 * Screen — Bomedia catalog (Supabase full screen)
	 * ========================================================= */
	function renderBomediaCatalog(step) {
		clearScreen();
		showBack(true);
		// tray rendered inline in this screen
		progress.hidden = true;

		var card = el('div', 'bqw-wiz-card bqw-wiz-card-wide bqw-wiz-card-tall');
		card.appendChild(el('h2', 'bqw-wiz-h', escapeHtml(bqwInterpolate(step.message || ''))));

		var filters = el('div', 'bqw-browse-filters');
		var search  = el('input', null);
		search.type = 'search';
		search.placeholder = i18n.searchMachines || 'Search machines…';
		filters.appendChild(search);
		var chipBox = el('div', 'bqw-chip-filters');
		filters.appendChild(chipBox);
		card.appendChild(filters);

		var trayAnchor = el('div', 'bqw-tray-anchor');
		var t0 = buildInlineTray(); if (t0) trayAnchor.appendChild(t0);
		card.appendChild(trayAnchor);

		var grid = el('div', 'bqw-browse-grid');
		card.appendChild(grid);
		screen.appendChild(card);

		var products = (step.products || []).slice();
		var brands   = step.brands || [];
		var tasks    = step.tasks  || [];
		var activeBrands = new Set();
		var activeTasks  = new Set();

		function paint() {
			var q = (search.value || '').toLowerCase().trim();
			var taskBrands = new Set();
			if (activeTasks.size) tasks.forEach(function (t) {
				if (activeTasks.has(t.value)) (t.brands || []).forEach(function (b) { taskBrands.add(b); });
			});
			grid.innerHTML = '';
			products.forEach(function (p) {
				var bl = (p.brand || '').toLowerCase();
				if (q && (p.name || '').toLowerCase().indexOf(q) === -1) return;
				if (activeBrands.size && !activeBrands.has(p.brand)) return;
				if (activeTasks.size && !taskBrands.has(bl)) return;
				grid.appendChild(buildBrowseCard(p, /*woo*/false));
			});
		}
		var taskItems = (tasks || []).filter(function (t) { return t.brands && t.brands.length; })
			.map(function (t) { return { value: t.value, label: t.label }; });
		if (taskItems.length) {
			chipBox.appendChild(buildChipFilter({
				label: (i18n.typeLabel || 'Type') + ':',
				items: taskItems, active: activeTasks, onChange: paint,
			}));
		}
		var brandItems = (brands || []).filter(function (b) { return b.id; })
			.map(function (b) { return { value: b.id, label: b.label || b.id }; });
		if (brandItems.length) {
			chipBox.appendChild(buildChipFilter({
				label: (i18n.brandLabel || 'Brand') + ':',
				items: brandItems, active: activeBrands, onChange: paint,
			}));
		}
		search.oninput = paint;
		paint();
	}

	function buildBrowseCard(p, isWoo) {
		var card = el('div', 'bqw-browse-card');
		card.dataset.productId = p.id;
		if (state.selection.some(function (s) { return s.id === p.id; })) card.classList.add('is-selected');

		var img      = isWoo ? p.image : p.img;
		var brandTxt = isWoo ? (p.category_name || '') : (p.brand || '');
		var siteHost = (window.location && window.location.hostname) || '';
		var permalink = isWoo ? p.permalink : p.link;
		var linkHost = '';
		try { linkHost = new URL(permalink).hostname; } catch (e) {}
		var linkLabel = (linkHost && linkHost.replace(/^www\./, '') !== siteHost.replace(/^www\./, ''))
			? (i18n.viewOn || 'View on') + ' ' + linkHost + ' ↗'
			: (i18n.viewProduct || 'View product →');
		var linkHtml = permalink ? '<a href="' + permalink + '" target="_blank" rel="noopener">' + escapeHtml(linkLabel) + '</a>' : '';

		card.innerHTML =
			(img ? '<img class="bqw-browse-card-img" src="' + img + '" alt="" loading="lazy">' : '<div class="bqw-browse-card-img"></div>') +
			'<div class="bqw-browse-card-body">' +
				'<span class="bqw-browse-card-brand">' + escapeHtml(brandTxt) + '</span>' +
				'<span class="bqw-browse-card-name">' + escapeHtml(p.name || '') + '</span>' +
			'</div>' +
			'<div class="bqw-browse-card-actions">' +
				'<button type="button" class="bqw-btn bqw-btn-primary bqw-browse-pick">' + escapeHtml(i18n.addToRequest || 'Add to request') + '</button>' +
				linkHtml +
			'</div>';

		card.querySelector('.bqw-browse-pick').addEventListener('click', function (e) {
			e.stopPropagation();
			var picked = !card.classList.contains('is-selected');
			if (picked) {
				card.classList.add('is-selected');
				addSelection({
					id: p.id, name: p.name, image: img || '', sku: '',
					brand: isWoo ? '' : (p.brand || ''), price: '',
					area: isWoo ? '' : (p.area || ''),
					link: permalink || '',
					source: isWoo ? 'woo' : 'catalog',
					categoryId: 0,
					categorySlug: isWoo ? (p.category_slug || '') : (p.brand || ''),
					categoryName: isWoo ? (p.category_name || '') : (p.brand || ''),
				});
			} else {
				card.classList.remove('is-selected');
				removeSelection(p.id);
			}
		repaintCurrentInlineTray();
			// Update button label.
			var btn = card.querySelector('.bqw-browse-pick');
			btn.textContent = card.classList.contains('is-selected')
				? '✓ ' + (i18n.added || 'Added')
				: (i18n.addToRequest || 'Add to request');
		});
		// Initial label state.
		if (card.classList.contains('is-selected')) {
			card.querySelector('.bqw-browse-pick').textContent = '✓ ' + (i18n.added || 'Added');
		}
		return card;
	}

	/* =========================================================
	 * Final form
	 * ========================================================= */
	function goToFinalForm(replay) {
		// Move directly to the contact form (skip server "form" round-trip — same outcome).
		clearScreen();
		showBack(true);
		progress.hidden = true;

		if (!replay) {
			if (state.current) state.stack.push(state.current);
			state.current = { kind: 'form', step: { id: 'contact' }, picks: [] };
		}

		var name = state.contact_partial.name || '';
		var wrap = el('div', 'bqw-wiz-card bqw-wiz-card-wide bqw-final-wrap');

		var heading = bqwInterpolate(i18n.finalTitleTpl || 'Almost done, {nombre}')
			.replace('%s', name);
		if (!name) heading = i18n.almostDone || 'Almost done';
		wrap.appendChild(el('h2', 'bqw-wiz-h bqw-final-h', escapeHtml(heading)));
		wrap.appendChild(el('p', 'bqw-wiz-sub bqw-final-sub', escapeHtml(i18n.justTwoMore || 'We just need a couple more details.')));

		var grid = el('div', 'bqw-final-grid');

		// LEFT — TUS DATOS.
		var left = el('section', 'bqw-final-col bqw-final-col-data');
		left.appendChild(el('h3', 'bqw-final-section-h', escapeHtml(i18n.sectionData || 'Your details')));
		var fields = el('div', 'bqw-wiz-fields');
		fields.innerHTML =
			'<label class="bqw-wiz-field"><span>' + escapeHtml(i18n.phone || 'Phone') + ' *</span>' +
				'<div class="bqw-phone-wrap"><select id="bqw-dial-prefix" class="bqw-dial-select" aria-label="' + escapeHtml(i18n.countryCode || 'Country code') + '"></select>' +
				'<input type="tel" id="bqw-phone-input" autocomplete="tel" required></div></label>' +
			'<label class="bqw-wiz-field"><span>' + escapeHtml(i18n.country || 'Country') + ' *</span>' +
				'<select id="bqw-country-input" required></select></label>' +
			'<label class="bqw-wiz-field"><span>' + escapeHtml(i18n.message || 'Message (optional)') + '</span>' +
				'<textarea id="bqw-message-input" rows="3" placeholder="' + escapeHtml(i18n.tellUs || 'Tell us what you need…') + '"></textarea></label>';
		left.appendChild(fields);

		var legal = el('div', 'bqw-wiz-legal');
		var privacyUrl = cfg.privacy_url || '';
		var privacyHtml = privacyUrl
			? '<a href="' + privacyUrl + '" target="_blank" rel="noopener">' + escapeHtml(i18n.privacyPolicy || 'privacy policy') + '</a>'
			: escapeHtml(i18n.privacyPolicy || 'privacy policy');
		legal.innerHTML =
			'<label class="bqw-wiz-check"><input type="checkbox" id="bqw-privacy-input" required>' +
				'<span>' + escapeHtml(i18n.acceptPrivacyPrefix || 'I accept the ') + privacyHtml + escapeHtml(i18n.acceptPrivacySuffix || ' and the processing of my data to receive a quote.') + ' *</span></label>';
		if (cfg.enable_email_optin) {
			legal.innerHTML +=
				'<label class="bqw-wiz-check"><input type="checkbox" id="bqw-optin-input">' +
					'<span>' + escapeHtml(cfg.email_optin_label || i18n.optinDefault || 'I want to receive product updates from Bomedia.') + '</span></label>';
		}
		left.appendChild(legal);

		// v1.7.12 — render the captcha widget inside the left column (the
		// previous version left it inside a hidden <form> so it never showed).
		var captchaCfg = cfg.captcha || null;
		if (cfg.enable_captcha && captchaCfg) {
			var captchaBox = el('div', 'bqw-captcha-inline');
			captchaBox.dataset.provider = captchaCfg.provider || 'math';
			left.insertBefore(captchaBox, legal);
			renderCaptcha(captchaBox, captchaCfg);
		}

		var actions = el('div', 'bqw-wiz-actions bqw-wiz-actions-row bqw-final-actions');
		var submitBtn = el('button', 'bqw-btn bqw-btn-primary');
		submitBtn.type = 'button';
		submitBtn.id   = 'bqw-final-submit';
		submitBtn.innerHTML = '<span class="bqw-submit-label">' + escapeHtml(i18n.send || 'Send') + ' →</span><span class="bqw-spinner" hidden></span>';
		actions.appendChild(submitBtn);
		left.appendChild(actions);

		var errBox = el('p', 'bqw-wiz-error', '');
		errBox.id = 'bqw-final-error';
		errBox.hidden = true;
		left.appendChild(errBox);
		grid.appendChild(left);

		// RIGHT — TU CONSULTA.
		var right = el('section', 'bqw-final-col bqw-final-col-request');
		right.appendChild(el('h3', 'bqw-final-section-h', escapeHtml(i18n.sectionRequest || 'Your request')));
		if (state.selection.length === 0) {
			right.appendChild(el('p', 'bqw-final-empty', escapeHtml(i18n.emptyRequest || "You haven't added any machines yet. Go back to choose.")));
		} else {
			var list = el('div', 'bqw-final-machines');
			state.selection.forEach(function (p) {
				var row = el('div', 'bqw-final-machine');
				var img = p.image
					? '<img class="bqw-final-machine-thumb" src="' + p.image + '" alt="" loading="lazy">'
					: '<span class="bqw-final-machine-thumb bqw-final-machine-thumb-fallback">★</span>';
				row.innerHTML = img +
					'<div class="bqw-final-machine-meta">' +
						'<strong>' + escapeHtml(p.name) + '</strong>' +
						(p.brand ? '<span>' + escapeHtml(p.brand) + '</span>' : '') +
					'</div>';
				list.appendChild(row);
			});
			right.appendChild(list);
			right.appendChild(el('p', 'bqw-final-hint', escapeHtml(i18n.removeHint || 'Want to remove one? Go back.')));
		}
		grid.appendChild(right);

		wrap.appendChild(grid);
		screen.appendChild(wrap);

		buildCountriesInto(document.getElementById('bqw-country-input'), document.getElementById('bqw-dial-prefix'));
		submitBtn.addEventListener('click', submitFinalForm);
	}

	function buildCountriesInto(countrySel, prefixSel) {
		countrySel.innerHTML = '';
		prefixSel.innerHTML = '';
		(countries || []).forEach(function (c) {
			var o = el('option');
			o.value = c.code; o.textContent = c.name; o.dataset.dial = c.dial;
			if (c.code === detected) o.selected = true;
			countrySel.appendChild(o);

			var p = el('option');
			p.value = c.dial;
			p.dataset.code = c.code;
			p.textContent = c.dial + ' — ' + c.name;
			if (c.code === detected) p.selected = true;
			prefixSel.appendChild(p);
		});
		countrySel.addEventListener('change', function () {
			var opt = countrySel.options[countrySel.selectedIndex];
			var d = opt ? (opt.dataset.dial || '+') : '+';
			for (var i = 0; i < prefixSel.options.length; i++) {
				if (prefixSel.options[i].value === d) { prefixSel.selectedIndex = i; break; }
			}
		});
	}

	// v1.7.12 — captcha widget state (set by renderCaptcha based on provider).
	var captchaState = { provider: '', widgetId: null, mathReady: false };

	function renderCaptcha(box, c) {
		var prov = c.provider || 'math';
		captchaState = { provider: prov, widgetId: null, mathReady: false };
		box.innerHTML = '';

		if (prov === 'math') {
			box.innerHTML =
				'<label class="bqw-wiz-field"><span>' + escapeHtml(i18n.captchaQuick || 'Quick check') + ' *</span>' +
					'<div class="bqw-captcha-math"><strong>' + escapeHtml(c.question || '') + ' = ?</strong>' +
						'<input type="number" id="bqw-captcha-input" inputmode="numeric" autocomplete="off" required></div></label>' +
				'<p class="bqw-captcha-help">' + escapeHtml(i18n.captchaHelp || 'Helps us avoid spam.') + '</p>';
			captchaState.mathReady = true;
			return;
		}
		if (prov === 'recaptcha_v3') {
			box.innerHTML = '<p class="bqw-captcha-help">' + escapeHtml(i18n.captchaV3Note || 'Protected by Google reCAPTCHA.') + '</p>';
			return;
		}
		if (prov === 'recaptcha_v2') {
			var div = el('div', 'g-recaptcha');
			div.dataset.sitekey = c.site_key || '';
			box.appendChild(div);
			waitFor(function () { return window.grecaptcha && window.grecaptcha.render; }, function () {
				try { captchaState.widgetId = window.grecaptcha.render(div, { sitekey: c.site_key }); } catch (e) {}
			});
			return;
		}
		if (prov === 'turnstile') {
			var t = el('div', 'cf-turnstile');
			t.dataset.sitekey = c.site_key || '';
			box.appendChild(t);
			waitFor(function () { return window.turnstile && window.turnstile.render; }, function () {
				try { captchaState.widgetId = window.turnstile.render(t, { sitekey: c.site_key }); } catch (e) {}
			});
			return;
		}
		if (prov === 'hcaptcha') {
			var h = el('div', 'h-captcha');
			h.dataset.sitekey = c.site_key || '';
			box.appendChild(h);
			waitFor(function () { return window.hcaptcha && window.hcaptcha.render; }, function () {
				try { captchaState.widgetId = window.hcaptcha.render(h, { sitekey: c.site_key }); } catch (e) {}
			});
			return;
		}
	}

	function waitFor(predicate, cb) {
		if (predicate()) return cb();
		var tries = 0;
		var timer = setInterval(function () {
			tries++;
			if (predicate()) { clearInterval(timer); cb(); }
			else if (tries > 60) { clearInterval(timer); }
		}, 100);
	}

	// Returns Promise<string> with the captcha token/answer ready to submit,
	// or rejects with a user-facing message when the user must complete it.
	function ensureCaptchaToken() {
		var prov = captchaState.provider;
		if (!prov) return Promise.resolve();
		if (prov === 'math') {
			var input = document.getElementById('bqw-captcha-input');
			var ans   = input ? input.value.trim() : '';
			if ('' === ans) return Promise.reject(new Error(i18n.errCaptcha || 'Please complete the verification.'));
			document.getElementById('bqw-captcha-answer').value = ans;
			return Promise.resolve();
		}
		if (prov === 'recaptcha_v3') {
			if (!(window.grecaptcha && cfg.captcha && cfg.captcha.site_key)) {
				return Promise.reject(new Error(i18n.errCaptcha || 'Captcha not ready.'));
			}
			return new Promise(function (resolve, reject) {
				window.grecaptcha.ready(function () {
					window.grecaptcha.execute(cfg.captcha.site_key, { action: cfg.captcha.action || 'boprint_quote' })
						.then(function (token) {
							var t = document.getElementById('bqw-recaptcha-v3'); if (t) t.value = token;
							resolve();
						})
						.catch(function () { reject(new Error(i18n.errCaptcha || 'Captcha failed. Please retry.')); });
				});
			});
		}
		if (prov === 'recaptcha_v2') {
			var resp = window.grecaptcha && captchaState.widgetId !== null
				? window.grecaptcha.getResponse(captchaState.widgetId)
				: '';
			if (!resp) return Promise.reject(new Error(i18n.errCaptcha || 'Please complete the captcha.'));
			document.getElementById('bqw-recaptcha-v3').value = resp;
			return Promise.resolve();
		}
		if (prov === 'turnstile') {
			var tr = window.turnstile && captchaState.widgetId !== null
				? window.turnstile.getResponse(captchaState.widgetId)
				: '';
			if (!tr) return Promise.reject(new Error(i18n.errCaptcha || 'Please complete the captcha.'));
			// Turnstile token goes via 'cf-turnstile-response' field; reuse the
			// server's existing 'g-recaptcha-response' channel by submitting in FormData manually.
			document.getElementById('bqw-recaptcha-v3').value = '';
			window.__bqwTurnstileToken = tr;
			return Promise.resolve();
		}
		if (prov === 'hcaptcha') {
			var hr = window.hcaptcha && captchaState.widgetId !== null
				? window.hcaptcha.getResponse(captchaState.widgetId)
				: '';
			if (!hr) return Promise.reject(new Error(i18n.errCaptcha || 'Please complete the captcha.'));
			window.__bqwHcaptchaToken = hr;
			return Promise.resolve();
		}
		return Promise.resolve();
	}

	function submitFinalForm() {
		var phone   = document.getElementById('bqw-phone-input').value.trim();
		var country = document.getElementById('bqw-country-input').value;
		var message = document.getElementById('bqw-message-input').value.trim();
		var privacy = document.getElementById('bqw-privacy-input').checked;
		var optin   = document.getElementById('bqw-optin-input');
		var optinV  = optin ? optin.checked : false;
		var dial    = document.getElementById('bqw-dial-prefix').value || '';
		var errBox  = document.getElementById('bqw-final-error');
		errBox.hidden = true;

		if (!phone) { errBox.hidden = false; errBox.textContent = i18n.errPhone || 'Phone is required.'; return; }
		if (!country) { errBox.hidden = false; errBox.textContent = i18n.errCountry || 'Country is required.'; return; }
		if (!privacy) { errBox.hidden = false; errBox.textContent = i18n.errPrivacy || 'Please accept the privacy policy.'; return; }

		var btn = document.getElementById('bqw-final-submit');
		var spinner = btn.querySelector('.bqw-spinner');
		btn.disabled = true; if (spinner) spinner.hidden = false;

		ensureCaptchaToken().then(function () {
			var fd = new FormData(form);
			fd.set('first_name', state.contact_partial.name || '');
			fd.set('email', state.contact_partial.email || '');
			fd.set('last_name', '');
			fd.set('company', '');
			fd.set('phone', (dial || '') + ' ' + phone);
			fd.set('country', country);
			fd.set('message', message);
			fd.set('privacy', privacy ? '1' : '');
			if (optin) fd.set('email_optin', optinV ? '1' : '');
			if (window.__bqwTurnstileToken) fd.set('cf-turnstile-response', window.__bqwTurnstileToken);
			if (window.__bqwHcaptchaToken)  fd.set('h-captcha-response', window.__bqwHcaptchaToken);

			fetch(window.BQW.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json && json.success) {
						if (json.data.redirect) { window.location.href = json.data.redirect; return; }
						root.querySelector('.bqw-wiz-screen').hidden = true;
						progress.hidden = true;
						thanksBox.hidden = false;
						thanksBox.innerHTML = json.data.html || '';
						thanksBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
					} else {
						errBox.hidden = false;
						errBox.textContent = (json && json.data && json.data.message) || i18n.genericError || 'Error';
						resetCaptcha();
					}
				})
				.catch(function () { errBox.hidden = false; errBox.textContent = i18n.genericError || 'Error'; resetCaptcha(); })
				.finally(function () { btn.disabled = false; if (spinner) spinner.hidden = true; });
		}).catch(function (err) {
			errBox.hidden = false;
			errBox.textContent = (err && err.message) || (i18n.errCaptcha || 'Please complete the verification.');
			btn.disabled = false; if (spinner) spinner.hidden = true;
		});
	}

	function resetCaptcha() {
		var prov = captchaState.provider;
		try {
			if (prov === 'recaptcha_v2' && window.grecaptcha && captchaState.widgetId !== null) window.grecaptcha.reset(captchaState.widgetId);
			if (prov === 'turnstile' && window.turnstile && captchaState.widgetId !== null) window.turnstile.reset(captchaState.widgetId);
			if (prov === 'hcaptcha' && window.hcaptcha && captchaState.widgetId !== null) window.hcaptcha.reset(captchaState.widgetId);
		} catch (e) {}
		window.__bqwTurnstileToken = '';
		window.__bqwHcaptchaToken = '';
	}

	/* =========================================================
	 * Init
	 * ========================================================= */
	function init() {
		renderContactIntro();
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
