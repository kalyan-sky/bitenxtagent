/*
 * BiteNXT support chat widget.
 *
 * Chat is for logged-in Pro customers only. The chat button appears only
 * while getToken() returns the customer's Magento token, and disappears when
 * they log out. Each customer has one continuous thread kept on the server,
 * so their messages are there after a refresh and on any device.
 *
 * The customer already receives a Bearer token from Magento when they log in
 * to Pro (generateCustomerTokenWithId). The widget sends that same token with
 * each message. Tell it where Pro keeps the token, in one of three ways:
 *
 * 1) Token in localStorage, sessionStorage or a cookie - one tag:
 *
 *   <script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"
 *           data-auto-init
 *           data-token-key="customerToken"
 *           data-token-source="localStorage"></script>
 *
 *    data-token-source: localStorage (default) | sessionStorage | cookie
 *    data-token-path:   only if the stored value is JSON, e.g. "token" or "auth.token"
 *
 * 2) Token in app state (React/Redux, Vue, Angular) - push it:
 *
 *   <script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"></script>
 *   BitenxtChat.setToken(token);   // after login
 *   BitenxtChat.setToken(null);    // on logout
 *
 * 3) Full control:
 *
 *   BitenxtChat.init({ getToken: () => currentTokenOrNull() });
 *
 * The API address defaults to <origin of widget.js>/chat.
 *
 * Replies are rendered with textContent only, never innerHTML, so a reply
 * can't inject markup or scripts into the page.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var defaultApiUrl = script && script.src ? new URL('/chat', script.src).href : '/chat';
  var initialised = false;

  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    if (text) node.textContent = text;
    return node;
  }

  function init(options) {
    options = options || {};
    if (initialised) return;
    initialised = true;
    var apiUrl = options.apiUrl || defaultApiUrl;
    var getToken = options.getToken || function () { return pushedToken; };
    var title = options.title || 'BiteNXT Support';
    var greeting = options.greeting ||
      'Hi! I can help with your order status, tracking, products and account questions. How can I help?';

    var style = el('style');
    style.textContent = [
      '.bnx-btn{display:none;position:fixed;right:20px;bottom:20px;z-index:99999;border:0;border-radius:28px;padding:14px 20px;',
      'background:#0b6e8a;color:#fff;font:600 15px system-ui,sans-serif;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.2)}',
      '.bnx-panel{position:fixed;right:20px;bottom:84px;z-index:99999;width:360px;max-width:calc(100vw - 32px);height:520px;',
      'max-height:calc(100vh - 120px);display:none;flex-direction:column;background:#fff;border-radius:12px;',
      'box-shadow:0 8px 30px rgba(0,0,0,.25);font:14px/1.45 system-ui,sans-serif;color:#1d2327;overflow:hidden}',
      '.bnx-panel.open{display:flex}',
      '.bnx-head{background:#0b6e8a;color:#fff;padding:12px 16px;font-weight:600;display:flex;justify-content:space-between}',
      '.bnx-head button{background:none;border:0;color:#fff;font-size:18px;cursor:pointer}',
      '.bnx-log{flex:1;overflow-y:auto;padding:12px;background:#f5f7f8}',
      '.bnx-msg{max-width:85%;margin:6px 0;padding:8px 12px;border-radius:10px;white-space:pre-wrap;word-wrap:break-word}',
      '.bnx-bot{background:#fff;border:1px solid #e1e5e8}',
      '.bnx-user{background:#0b6e8a;color:#fff;margin-left:auto}',
      '.bnx-form{display:flex;border-top:1px solid #e1e5e8}',
      '.bnx-form textarea{flex:1;border:0;padding:12px;resize:none;font:inherit;outline:none}',
      '.bnx-form button{border:0;background:#0b6e8a;color:#fff;padding:0 16px;cursor:pointer;font-weight:600}',
      '.bnx-btn.ready{display:block}',
      '.bnx-time{text-align:center;color:#6b7680;font-size:12px;margin:12px 0 4px}',
      '.bnx-form button:disabled{opacity:.5}'
    ].join('');
    document.head.appendChild(style);

    var button = el('button', { 'class': 'bnx-btn', type: 'button', 'aria-label': 'Open support chat' }, 'Chat with us');
    var panel = el('div', { 'class': 'bnx-panel', role: 'dialog', 'aria-label': title });
    var head = el('div', { 'class': 'bnx-head' }, title);
    var close = el('button', { type: 'button', 'aria-label': 'Close chat' }, '×');
    var log = el('div', { 'class': 'bnx-log', 'aria-live': 'polite' });
    var form = el('form', { 'class': 'bnx-form' });
    var input = el('textarea', { rows: '2', maxlength: '2000', placeholder: 'Type your message…', 'aria-label': 'Message' });
    var send = el('button', { type: 'submit' }, 'Send');

    head.appendChild(close);
    form.appendChild(input);
    form.appendChild(send);
    panel.appendChild(head);
    panel.appendChild(log);
    panel.appendChild(form);
    document.body.appendChild(panel);
    document.body.appendChild(button);

    var historyUrl = apiUrl.replace(/\/?$/, '') + '/history';
    var lastAt = 0;
    var historyLoadedFor = null;

    function separatorLabel(at) {
      var d = new Date(at * 1000);
      var now = new Date();
      var time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      var startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
      if (d.getTime() >= startOfToday) return 'Today ' + time;
      if (d.getTime() >= startOfToday - 86400000) return 'Yesterday ' + time;
      return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' }) + ', ' + time;
    }

    // Messages carry a time; a date/time divider is shown before the first
    // message and whenever 30+ minutes passed, like Amazon's chat.
    function add(text, who, at) {
      at = at || Math.floor(Date.now() / 1000);
      if (!lastAt || at - lastAt > 1800) {
        log.appendChild(el('div', { 'class': 'bnx-time' }, separatorLabel(at)));
      }
      lastAt = at;
      var msg = el('div', { 'class': 'bnx-msg ' + (who === 'user' ? 'bnx-user' : 'bnx-bot') }, text);
      log.appendChild(msg);
      log.scrollTop = log.scrollHeight;
      return msg;
    }

    function clearLog() {
      log.textContent = '';
      lastAt = 0;
    }

    function currentToken() {
      try { return getToken() || null; } catch (e) { return null; }
    }

    // The thread lives on the server, tied to the logged-in customer, so it
    // is the same after a refresh, in another tab or on another device.
    function loadHistory() {
      var token = currentToken();
      if (!token || historyLoadedFor === token) return;
      historyLoadedFor = token;
      clearLog();
      log.appendChild(el('div', { 'class': 'bnx-time' }, 'Loading your messages…'));
      send.disabled = true; // so a new message can't be wiped by the history arriving
      fetch(historyUrl, { headers: { Authorization: 'Bearer ' + token } })
        .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
        .then(function (res) {
          if (currentToken() !== token) return; // user changed meanwhile
          clearLog();
          var messages = res.status === 200 && res.data.messages ? res.data.messages : [];
          messages.forEach(function (m) { add(m.text, m.role, m.at); });
          if (!messages.length) add(greeting, 'bot');
          if (res.status === 401) add(res.data.reply || 'Please log in again.', 'bot');
        })
        .catch(function () {
          historyLoadedFor = null; // try again next time the chat is opened
          clearLog();
          add(greeting, 'bot');
        })
        .finally(function () { send.disabled = false; });
    }

    // Show the chat only to logged-in users; switch threads when the user changes.
    var knownToken = currentToken();
    function syncLogin() {
      var token = currentToken();
      if (token !== knownToken) {
        knownToken = token;
        historyLoadedFor = null;
        clearLog();
        if (token && panel.classList.contains('open')) loadHistory();
      }
      button.classList.toggle('ready', !!token);
      if (!token) panel.classList.remove('open');
    }

    syncLogin();
    setInterval(syncLogin, 2000);
    window.addEventListener('storage', syncLogin);
    window.addEventListener('focus', syncLogin);

    button.addEventListener('click', function () {
      panel.classList.toggle('open');
      if (panel.classList.contains('open')) {
        loadHistory();
        input.focus();
      }
    });
    close.addEventListener('click', function () { panel.classList.remove('open'); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      var token = currentToken();
      if (!text || send.disabled) return;
      if (!token) { syncLogin(); return; }
      input.value = '';
      add(text, 'user');
      send.disabled = true;
      var pending = add('…', 'bot');

      fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
        body: JSON.stringify({ message: text })
      })
        .then(function (r) {
          return r.json().then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
          if (res.status === 401) {
            // Token expired or revoked: Pro needs a fresh login.
            pending.textContent = res.data.reply || 'Your session has expired. Please log in again.';
            return;
          }
          pending.textContent = res.data.reply || 'Sorry, something went wrong. Please try again.';
        })
        .catch(function () {
          pending.textContent = 'Sorry, I could not reach support right now. Please try again.';
        })
        .finally(function () {
          send.disabled = false;
          input.focus();
        });
    });
  }

  // ---- Reading the token Pro stores at login --------------------------------

  // Pro may store the token as a plain string, as a JSON string ("\"abc\""),
  // or inside a JSON object ({"auth":{"token":"abc"}}). tokenPath picks the
  // field inside JSON, e.g. "token" or "auth.token". A "Bearer " prefix is removed.
  function normaliseToken(raw, tokenPath) {
    if (raw === null || raw === undefined || raw === '') return null;
    var value = raw;
    if (typeof value === 'string') {
      var trimmed = value.trim();
      if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '"') {
        try { value = JSON.parse(trimmed); } catch (e) { value = trimmed; }
      }
    }
    if (tokenPath && value && typeof value === 'object') {
      tokenPath.split('.').forEach(function (part) { value = value == null ? null : value[part]; });
    }
    if (typeof value !== 'string') return null;
    value = value.replace(/^Bearer\s+/i, '').trim();
    return value === '' || value === 'null' || value === 'undefined' ? null : value;
  }

  function readCookie(name) {
    var parts = document.cookie ? document.cookie.split('; ') : [];
    for (var i = 0; i < parts.length; i++) {
      var eq = parts[i].indexOf('=');
      if (parts[i].substring(0, eq) === name) {
        try { return decodeURIComponent(parts[i].substring(eq + 1)); } catch (e) { return parts[i].substring(eq + 1); }
      }
    }
    return null;
  }

  /** Builds getToken() for source = localStorage | sessionStorage | cookie. */
  function tokenReader(source, key, tokenPath) {
    return function () {
      var raw = null;
      try {
        if (source === 'sessionStorage') raw = sessionStorage.getItem(key);
        else if (source === 'cookie') raw = readCookie(key);
        else raw = localStorage.getItem(key);
      } catch (e) { raw = null; }
      return normaliseToken(raw, tokenPath);
    };
  }

  // For apps that keep the token in memory/app state (React, Vue, Angular):
  // call BitenxtChat.setToken(token) after login and setToken(null) on logout.
  var pushedToken = null;
  function setToken(token) {
    pushedToken = normaliseToken(token, null);
    if (!initialised) {
      init({ getToken: function () { return pushedToken; } });
    }
  }

  window.BitenxtChat = { init: init, setToken: setToken };

  // ---- One-tag setup ---------------------------------------------------------
  //   data-auto-init                    turn on one-tag setup
  //   data-token-key="customerToken"     where Pro stores the login token (required)
  //   data-token-source="localStorage"   localStorage (default) | sessionStorage | cookie
  //   data-token-path="auth.token"       only if the stored value is JSON
  //   data-title="BiteNXT Support"       optional panel title
  if (script && script.hasAttribute('data-auto-init')) {
    var tokenKey = script.getAttribute('data-token-key');
    var tokenSource = script.getAttribute('data-token-source') || 'localStorage';
    var tokenPath = script.getAttribute('data-token-path') || '';
    var start = function () {
      if (!tokenKey) {
        if (window.console) console.warn('BitenxtChat: data-token-key is required; chat is for logged-in users only.');
        return;
      }
      init({
        title: script.getAttribute('data-title') || undefined,
        getToken: tokenReader(tokenSource, tokenKey, tokenPath)
      });
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start);
    } else {
      start();
    }
  }
})();
