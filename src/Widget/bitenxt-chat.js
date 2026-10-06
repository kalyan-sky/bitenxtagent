/*
 * <bitenxt-chat> - BiteNXT support chat as a web component.
 *
 * Framework-free (custom element + Shadow DOM), so it works the same in
 * React, Angular, Vue or plain HTML, and Pro's CSS can't break it (or the
 * other way round). Theme it from the host page with CSS custom properties
 * and ::part(); see docs/WEB-COMPONENT.md for the full list.
 *
 *   <script src="https://YOUR-SERVICE/bitenxt-chat.js" defer></script>
 *   <bitenxt-chat token-key="customerToken"></bitenxt-chat>
 *
 * Chat is for logged-in Pro customers only: nothing is shown until the
 * component has the customer's Magento token, via one of
 *   - token-key (+ token-source, token-path): read from storage/cookie
 *   - element.token = '...'          (set null on logout)
 *   - element.getToken = () => '...' (called whenever a token is needed)
 *
 * Replies are rendered as text nodes (plus <strong> for **bold**), never innerHTML, so a reply
 * can't inject markup or scripts into the page.
 */
(function () {
  'use strict';
  if (!window.customElements || customElements.get('bitenxt-chat')) return;

  var SCRIPT = document.currentScript;
  var DEFAULT_API = SCRIPT && SCRIPT.src ? new URL('/chat', SCRIPT.src).href : '/chat';
  // Filled in by the server from SUPPORT_PHONE when it serves this file.
  var SERVER_PHONE = '__BNX_SUPPORT_PHONE__';
  if (SERVER_PHONE.indexOf('__') === 0) SERVER_PHONE = '';

  var DEFAULT_SUGGESTIONS = [
    'Track my order', 'My recent orders', 'How many orders do I have?', 'Any coupons for me?',
    "What's in my cart?", 'How do I place an order?', 'What products are available?', 'Talk to support'
  ];

  var CSS = [
    ':host{',
    '  --bnx-primary:#d9518e; --bnx-primary-contrast:#ffffff; --bnx-primary-soft:#fbe9f1;',
    '  --bnx-font:inherit; --bnx-font-size:14px; --bnx-text:#1d2327; --bnx-muted:#6b7680;',
    '  --bnx-bg:#ffffff; --bnx-log-bg:#f7f8fa; --bnx-border:#e4e7eb;',
    '  --bnx-bot-bg:#ffffff; --bnx-bot-text:var(--bnx-text);',
    '  --bnx-user-bg:var(--bnx-primary); --bnx-user-text:var(--bnx-primary-contrast);',
    '  --bnx-radius:14px; --bnx-bubble-radius:12px; --bnx-shadow:0 10px 32px rgba(0,0,0,.18);',
    '  --bnx-width:380px; --bnx-height:560px; --bnx-offset-x:20px; --bnx-offset-y:20px; --bnx-z-index:2147483000;',
    '  display:contents; font-family:var(--bnx-font); font-size:var(--bnx-font-size); color:var(--bnx-text);',
    '}',
    ':host([hidden]){display:none}',
    '*{box-sizing:border-box}',
    '.launcher{position:fixed;right:var(--bnx-offset-x);bottom:var(--bnx-offset-y);z-index:var(--bnx-z-index);',
    '  border:0;border-radius:999px;padding:14px 20px;background:var(--bnx-primary);color:var(--bnx-primary-contrast);',
    '  font:600 15px/1 var(--bnx-font);cursor:pointer;box-shadow:var(--bnx-shadow);display:none;align-items:center;gap:8px}',
    '.launcher.ready{display:inline-flex}',
    ':host([position=left]) .launcher, :host([position=left]) .panel{right:auto;left:var(--bnx-offset-x)}',
    '.panel{position:fixed;right:var(--bnx-offset-x);bottom:calc(var(--bnx-offset-y) + 64px);z-index:var(--bnx-z-index);',
    '  width:var(--bnx-width);max-width:calc(100vw - 24px);height:var(--bnx-height);max-height:calc(100vh - 110px);',
    '  display:none;flex-direction:column;background:var(--bnx-bg);border-radius:var(--bnx-radius);',
    '  box-shadow:var(--bnx-shadow);overflow:hidden;font-family:var(--bnx-font);color:var(--bnx-text)}',
    ':host([open]) .panel{display:flex}',
    ':host([mode=inline]) .launcher{display:none}',
    ':host([mode=inline]){display:block;height:100%}',
    ':host([mode=inline]) .panel{position:static;display:flex;width:100%;max-width:none;height:100%;max-height:none;box-shadow:none;',
    '  border:1px solid var(--bnx-border)}',
    '.header{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px 16px;',
    '  background:var(--bnx-primary);color:var(--bnx-primary-contrast);font-weight:600}',
    '.close{background:none;border:0;color:inherit;font-size:22px;line-height:1;cursor:pointer;padding:0 4px}',
    ':host([mode=inline]) .close{display:none}',
    '.call{display:block;padding:7px 16px;background:var(--bnx-primary-soft);color:var(--bnx-primary);',
    '  text-decoration:none;font-size:13px;border-bottom:1px solid var(--bnx-border)}',
    '.log{flex:1;overflow-y:auto;padding:12px;background:var(--bnx-log-bg)}',
    '.msg{max-width:85%;margin:6px 0;padding:9px 12px;border-radius:var(--bnx-bubble-radius);white-space:pre-wrap;',
    '  overflow-wrap:anywhere;line-height:1.45}',
    '.bot{background:var(--bnx-bot-bg);color:var(--bnx-bot-text);border:1px solid var(--bnx-border)}',
    '.user{background:var(--bnx-user-bg);color:var(--bnx-user-text);margin-left:auto}',
    '.typing{color:var(--bnx-muted);font-style:italic}',
    '.separator{text-align:center;color:var(--bnx-muted);font-size:12px;margin:12px 0 4px}',
    '.chips{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}',
    '.chip{border:1px solid var(--bnx-primary);background:var(--bnx-bg);color:var(--bnx-primary);border-radius:16px;',
    '  padding:5px 11px;font:inherit;font-size:13px;cursor:pointer}',
    '.chip:hover{background:var(--bnx-primary-soft)}',
    '.rating{display:flex;align-items:center;gap:4px;margin:-2px 0 6px}',
    '.rating button{border:0;background:none;cursor:pointer;font-size:14px;opacity:.55;padding:2px 4px}',
    '.rating button:hover{opacity:1}',
    '.rating span{color:var(--bnx-muted);font-size:12px}',
    '.form{display:flex;border-top:1px solid var(--bnx-border);background:var(--bnx-bg)}',
    '.input{flex:1;border:0;padding:12px;resize:none;font:inherit;color:inherit;background:transparent;outline:none}',
    '.send{border:0;background:var(--bnx-primary);color:var(--bnx-primary-contrast);padding:0 18px;font:inherit;',
    '  font-weight:600;cursor:pointer}',
    '.send:disabled{opacity:.5;cursor:default}',
    '@media (max-width:480px){.panel{right:8px;left:8px;width:auto;bottom:calc(var(--bnx-offset-y) + 60px)}}'
  ].join('\n');

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    if (text !== undefined) node.textContent = text;
    return node;
  }

  // Shows **bold** as bold. Built from text nodes and <strong> elements only
  // (never innerHTML), so a reply still can't inject markup.
  function renderText(node, text) {
    node.textContent = '';
    String(text).split(/\*\*([^*\n][^*]*?)\*\*/).forEach(function (part, i) {
      if (part === '') return;
      if (i % 2 === 1) {
        var strong = document.createElement('strong');
        strong.textContent = part;
        node.appendChild(strong);
      } else {
        node.appendChild(document.createTextNode(part));
      }
    });
  }

  // Pro may store the token as a plain string, a JSON string or inside a JSON
  // object; token-path picks the field ("token", "auth.token"). "Bearer " is removed.
  function normaliseToken(raw, path) {
    if (raw === null || raw === undefined || raw === '') return null;
    var value = raw;
    if (typeof value === 'string') {
      var t = value.trim();
      if (t.charAt(0) === '{' || t.charAt(0) === '"') { try { value = JSON.parse(t); } catch (e) { value = t; } }
    }
    if (path && value && typeof value === 'object') {
      path.split('.').forEach(function (p) { value = value == null ? null : value[p]; });
    }
    if (typeof value !== 'string') return null;
    value = value.replace(/^Bearer\s+/i, '').trim();
    return value === '' || value === 'null' || value === 'undefined' ? null : value;
  }

  function readStored(source, key) {
    try {
      if (source === 'cookie') {
        var parts = document.cookie ? document.cookie.split('; ') : [];
        for (var i = 0; i < parts.length; i++) {
          var eq = parts[i].indexOf('=');
          if (decodeURIComponent(parts[i].slice(0, eq)) === key) return decodeURIComponent(parts[i].slice(eq + 1));
        }
        return null;
      }
      return (source === 'sessionStorage' ? sessionStorage : localStorage).getItem(key);
    } catch (e) { return null; }
  }

  function separatorLabel(at) {
    var d = new Date(at * 1000), now = new Date();
    var time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    if (d.getTime() >= today) return 'Today ' + time;
    if (d.getTime() >= today - 86400000) return 'Yesterday ' + time;
    return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' }) + ', ' + time;
  }

  class BitenxtChat extends HTMLElement {
    static get observedAttributes() {
      return ['open', 'heading', 'support-phone', 'api-url', 'token-key', 'token-source', 'token-path'];
    }

    constructor() {
      super();
      // Frameworks may set token/getToken before this script loads; those land
      // as plain properties on the element. Pick them up instead of losing them.
      var early = {};
      ['token', 'getToken', 'suggestions'].forEach(function (p) {
        if (Object.prototype.hasOwnProperty.call(this, p)) { early[p] = this[p]; delete this[p]; }
      }, this);
      this._token = null;
      this.getToken = typeof early.getToken === 'function' ? early.getToken : null;
      this._known = undefined;
      this._historyFor = null;
      this._lastAt = 0;
      this._busy = false;
      this._suggestions = null;
      this._root = this.attachShadow({ mode: 'open' });
      this._build();
      if ('token' in early) this._token = normaliseToken(early.token, '');
      if ('suggestions' in early) this.suggestions = early.suggestions;
    }

    // ---- public API ---------------------------------------------------------

    /** The customer's Magento token; set null on logout. Never rendered or stored by the component. */
    get token() { return this._currentToken(); }
    set token(value) { this._token = normaliseToken(value, ''); this._sync(); }

    /** Quick-reply chips: an array of strings ([] to hide them). */
    get suggestions() { return this._suggestions || this._attrSuggestions() || DEFAULT_SUGGESTIONS; }
    set suggestions(list) { this._suggestions = Array.isArray(list) ? list.map(String) : null; }

    open() { if (this._currentToken()) this.setAttribute('open', ''); }
    close() { this.removeAttribute('open'); }
    toggle() { this.hasAttribute('open') ? this.close() : this.open(); }
    /** Sends a message as if the customer typed it (e.g. from a "Need help?" link on an order page). */
    send(text) { this.open(); this._input.value = String(text || ''); this._submit(); }

    // ---- lifecycle ----------------------------------------------------------

    connectedCallback() {
      this._applyText();
      this._sync();
      this._timer = setInterval(this._sync.bind(this), 2000);
      this._onStorage = this._sync.bind(this);
      window.addEventListener('storage', this._onStorage);
      window.addEventListener('focus', this._onStorage);
      if (this.getAttribute('mode') === 'inline') this._loadHistory();
    }

    disconnectedCallback() {
      clearInterval(this._timer);
      window.removeEventListener('storage', this._onStorage);
      window.removeEventListener('focus', this._onStorage);
    }

    attributeChangedCallback(name, oldValue, value) {
      if (!this._panel) return;
      if (name === 'open') {
        var isOpen = value !== null;
        if (isOpen && !this._currentToken()) { this.removeAttribute('open'); return; }
        if (isOpen) { this._loadHistory(); setTimeout(this._input.focus.bind(this._input), 0); }
        if ((oldValue !== null) !== isOpen) this._emit(isOpen ? 'bitenxt-open' : 'bitenxt-close', {});
        return;
      }
      if (name.indexOf('token-') === 0) { this._sync(); return; }
      this._applyText();
    }

    // ---- rendering ----------------------------------------------------------

    _build() {
      var root = this._root;
      root.appendChild(el('style', {}, CSS));

      this._launcher = el('button', { 'class': 'launcher', part: 'launcher', type: 'button', 'aria-label': 'Open support chat' });
      var slot = el('slot', { name: 'launcher' });
      slot.appendChild(el('span', {}, 'Chat with us'));
      this._launcher.appendChild(slot);

      this._panel = el('div', { 'class': 'panel', part: 'panel', role: 'dialog' });
      var header = el('div', { 'class': 'header', part: 'header' });
      this._title = el('span', { part: 'title' });
      var titleSlot = el('slot', { name: 'heading' });
      titleSlot.appendChild(this._title);
      this._closeBtn = el('button', { 'class': 'close', part: 'close', type: 'button', 'aria-label': 'Close chat' }, '\u00D7');
      header.appendChild(titleSlot);
      header.appendChild(this._closeBtn);
      this._call = el('a', { 'class': 'call', part: 'call' });
      this._log = el('div', { 'class': 'log', part: 'log', 'aria-live': 'polite' });
      this._form = el('form', { 'class': 'form', part: 'form' });
      this._input = el('textarea', { 'class': 'input', part: 'input', rows: '2', maxlength: '2000', 'aria-label': 'Message' });
      this._sendBtn = el('button', { 'class': 'send', part: 'send', type: 'submit' }, 'Send');
      this._form.appendChild(this._input);
      this._form.appendChild(this._sendBtn);
      this._panel.appendChild(header);
      this._panel.appendChild(this._call);
      this._panel.appendChild(this._log);
      this._panel.appendChild(this._form);
      root.appendChild(this._panel);
      root.appendChild(this._launcher);

      var self = this;
      this._launcher.addEventListener('click', function () { self.toggle(); });
      this._closeBtn.addEventListener('click', function () { self.close(); });
      this._input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); self._submit(); }
      });
      this._form.addEventListener('submit', function (e) { e.preventDefault(); self._submit(); });
    }

    _applyText() {
      var heading = this.getAttribute('heading') || 'BiteNXT Support';
      this._title.textContent = heading;
      this._panel.setAttribute('aria-label', heading);
      this._input.setAttribute('placeholder', this.getAttribute('placeholder') || 'Type your message\u2026');
      var phone = this.getAttribute('support-phone') || SERVER_PHONE;
      this._call.hidden = !phone;
      if (phone) {
        this._call.textContent = '\uD83D\uDCDE Urgent? Call us: ' + phone;
        this._call.setAttribute('href', 'tel:' + phone.replace(/[^\d+]/g, ''));
      }
    }

    _add(text, who, at) {
      at = at || Math.floor(Date.now() / 1000);
      if (!this._lastAt || at - this._lastAt > 1800) {
        this._log.appendChild(el('div', { 'class': 'separator', part: 'separator' }, separatorLabel(at)));
      }
      this._lastAt = at;
      var role = who === 'user' ? 'user' : 'bot';
      var msg = el('div', { 'class': 'msg ' + role, part: 'message message-' + role });
      if (role === 'bot') renderText(msg, text); else msg.textContent = text;
      this._log.appendChild(msg);
      this._log.scrollTop = this._log.scrollHeight;
      return msg;
    }

    _clear() { this._log.textContent = ''; this._lastAt = 0; }

    _addChips(list) {
      list = list || this.suggestions;
      if (!list.length) return;
      var self = this;
      var chips = el('div', { 'class': 'chips', part: 'chips' });
      list.forEach(function (text) {
        var chip = el('button', { 'class': 'chip', part: 'chip', type: 'button' }, text);
        chip.addEventListener('click', function () {
          if (self._busy) return;
          chips.remove();
          if (text === 'Track my order') {
            self._add('Sure! Send me the order number (for example 671).', 'bot');
            self._input.focus();
            return;
          }
          self._input.value = text;
          self._submit();
        });
        chips.appendChild(chip);
      });
      this._log.appendChild(chips);
      this._log.scrollTop = this._log.scrollHeight;
    }

    _addRating(at) {
      var token = this._currentToken();
      if (!token || !at) return;
      var self = this;
      var bar = el('div', { 'class': 'rating', part: 'rating' });
      [['up', '\uD83D\uDC4D', 'Helpful'], ['down', '\uD83D\uDC4E', 'Not helpful']].forEach(function (r) {
        var b = el('button', { type: 'button', 'aria-label': r[2], title: r[2] }, r[1]);
        b.addEventListener('click', function () {
          bar.textContent = '';
          bar.appendChild(el('span', {}, 'Thanks for the feedback'));
          fetch(self._api('/feedback'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
            body: JSON.stringify({ rating: r[0], at: at })
          }).catch(function () {});
          self._emit('bitenxt-feedback', { rating: r[0] });
        });
        bar.appendChild(b);
      });
      this._log.appendChild(bar);
      this._log.scrollTop = this._log.scrollHeight;
    }

    // ---- token & thread -----------------------------------------------------

    _currentToken() {
      try {
        if (typeof this.getToken === 'function') return normaliseToken(this.getToken(), '');
      } catch (e) { return null; }
      if (this._token) return this._token;
      var key = this.getAttribute('token-key');
      if (!key) return null;
      return normaliseToken(readStored(this.getAttribute('token-source') || 'localStorage', key), this.getAttribute('token-path') || '');
    }

    // Shows the chat only to logged-in customers and switches threads when the customer changes.
    _sync() {
      var token = this._currentToken();
      if (token !== this._known) {
        var first = this._known === undefined;
        this._known = token;
        this._historyFor = null;
        this._clear();
        if (!token) this.close();
        else if (this.hasAttribute('open') || this.getAttribute('mode') === 'inline') this._loadHistory();
        if (!first && !token) this._emit('bitenxt-auth-required', {});
      }
      // On an inner element, not the host: frameworks may own the host's class attribute.
      this._launcher.classList.toggle('ready', !!token);
    }

    _api(suffix) {
      return (this.getAttribute('api-url') || DEFAULT_API).replace(/\/+$/, '') + (suffix || '');
    }

    _loadHistory() {
      var token = this._currentToken();
      if (!token || this._historyFor === token) return;
      this._historyFor = token;
      var self = this;
      this._clear();
      this._log.appendChild(el('div', { 'class': 'separator', part: 'separator' }, 'Loading your messages\u2026'));
      this._busy = true;
      this._sendBtn.disabled = true;
      fetch(this._api('/history'), { headers: { Authorization: 'Bearer ' + token } })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
          if (self._currentToken() !== token) return;
          self._clear();
          var messages = res.status === 200 && res.data.messages ? res.data.messages : [];
          messages.forEach(function (m) { self._add(m.text, m.role, m.at); });
          if (!messages.length) self._add(self.getAttribute('greeting') || 'Hi! I can help with your orders, patients, cart, coupons and how to use BiteNXT Pro. How can I help?', 'bot');
          if (res.status === 401) { self._add(res.data.reply || 'Please log in again.', 'bot'); self._emit('bitenxt-auth-required', {}); }
          self._addChips();
        })
        .catch(function () {
          self._historyFor = null;
          self._clear();
          self._add(self.getAttribute('greeting') || 'Hi! How can I help?', 'bot');
          self._addChips();
        })
        .finally(function () { self._busy = false; self._sendBtn.disabled = false; });
    }

    _submit() {
      var text = this._input.value.trim();
      var token = this._currentToken();
      if (!text || this._busy) return;
      if (!token) { this._sync(); return; }
      var self = this;
      this._input.value = '';
      // Old suggestion buttons no longer apply once the customer moves on.
      Array.prototype.forEach.call(this._log.querySelectorAll('.chips'), function (c) { c.remove(); });
      this._add(text, 'user');
      this._emit('bitenxt-message-sent', { text: text });
      this._busy = true;
      this._sendBtn.disabled = true;
      var pending = this._add('Typing\u2026', 'bot');
      pending.classList.add('typing');

      fetch(this._api(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
        body: JSON.stringify({ message: text })
      })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
          renderText(pending, res.data.reply || 'Sorry, something went wrong. Please try again.');
          if (res.status === 401) self._emit('bitenxt-auth-required', {});
          if (res.status === 200) self._addRating(res.data.at);
          if (res.data.quick_replies && res.data.quick_replies.length) self._addChips(res.data.quick_replies);
          self._emit('bitenxt-reply', { text: pending.textContent, status: res.status });
        })
        .catch(function () {
          pending.textContent = 'Sorry, I could not reach support right now. Please try again.';
          self._emit('bitenxt-reply', { text: pending.textContent, status: 0 });
        })
        .finally(function () {
          pending.classList.remove('typing');
          self._busy = false;
          self._sendBtn.disabled = false;
          self._input.focus();
        });
    }

    _attrSuggestions() {
      var raw = this.getAttribute('suggestions');
      if (raw === null) return null;
      try { var list = JSON.parse(raw); return Array.isArray(list) ? list.map(String) : null; } catch (e) { return null; }
    }

    _emit(name, detail) {
      this.dispatchEvent(new CustomEvent(name, { detail: detail, bubbles: true, composed: true }));
    }
  }

  customElements.define('bitenxt-chat', BitenxtChat);
})();
