/*
 * BiteNXT support chat widget.
 *
 * Simplest: one tag. The API address is taken from where this file was
 * loaded (e.g. your Cloud Run URL), and the customer's Magento token is read
 * from localStorage under the key you name (omit it for guest-only chat).
 *
 *   <script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"
 *           data-auto-init data-token-key="customerToken"></script>
 *
 * Or initialise it yourself for full control:
 *
 *   <script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"></script>
 *   <script>
 *     BitenxtChat.init({
 *       // apiUrl defaults to <script origin>/chat
 *       getToken: () => store.getState().auth.token, // Magento customer token, or null
 *     });
 *   </script>
 *
 * Replies are rendered with textContent only, never innerHTML, so a reply
 * can't inject markup or scripts into the page.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'bitenxt_chat_session';
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
    var getToken = options.getToken || function () { return null; };
    var title = options.title || 'BiteNXT Support';
    var greeting = options.greeting ||
      'Hi! I can help with order status, tracking, products and account questions. How can I help?';

    var style = el('style');
    style.textContent = [
      '.bnx-btn{position:fixed;right:20px;bottom:20px;z-index:99999;border:0;border-radius:28px;padding:14px 20px;',
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

    function add(text, who) {
      var msg = el('div', { 'class': 'bnx-msg ' + (who === 'user' ? 'bnx-user' : 'bnx-bot') }, text);
      log.appendChild(msg);
      log.scrollTop = log.scrollHeight;
      return msg;
    }

    function sessionId() {
      try { return sessionStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    }

    function saveSessionId(id) {
      try { sessionStorage.setItem(STORAGE_KEY, id); } catch (e) { /* storage blocked */ }
    }

    add(greeting, 'bot');

    button.addEventListener('click', function () {
      panel.classList.toggle('open');
      if (panel.classList.contains('open')) input.focus();
    });
    close.addEventListener('click', function () { panel.classList.remove('open'); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || send.disabled) return;
      input.value = '';
      add(text, 'user');
      send.disabled = true;
      var pending = add('…', 'bot');

      var headers = { 'Content-Type': 'application/json' };
      var token = getToken();
      if (token) headers.Authorization = 'Bearer ' + token;

      fetch(apiUrl, {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({ session_id: sessionId(), message: text })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.session_id) saveSessionId(data.session_id);
          pending.textContent = data.reply || 'Sorry, something went wrong. Please try again.';
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

  window.BitenxtChat = { init: init };

  if (script && script.hasAttribute('data-auto-init')) {
    var tokenKey = script.getAttribute('data-token-key');
    var start = function () {
      init({
        title: script.getAttribute('data-title') || undefined,
        getToken: function () {
          if (!tokenKey) return null;
          try { return localStorage.getItem(tokenKey); } catch (e) { return null; }
        }
      });
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start);
    } else {
      start();
    }
  }
})();
