/*
 * BiteNXT support chat widget.
 *
 * Chat is for logged-in Pro customers only. The chat button appears only
 * while getToken() returns the customer's Magento token, and disappears (and
 * the conversation is cleared) when they log out or a different user logs in.
 *
 * Simplest: one tag. The API address is taken from where this file was
 * loaded (e.g. your Cloud Run URL), and the customer's Magento token is read
 * from localStorage under the key you name.
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
 *       getToken: () => store.getState().auth.token, // Magento customer token, or null when logged out
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

    function resetConversation() {
      try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* storage blocked */ }
      log.textContent = '';
      add(greeting, 'bot');
    }

    function currentToken() {
      try { return getToken() || null; } catch (e) { return null; }
    }

    // Show the chat only to logged-in users; start over when the user changes.
    var knownToken = currentToken();
    function syncLogin() {
      var token = currentToken();
      if (token !== knownToken) {
        knownToken = token;
        resetConversation();
      }
      button.classList.toggle('ready', !!token);
      if (!token) panel.classList.remove('open');
    }

    add(greeting, 'bot');
    syncLogin();
    setInterval(syncLogin, 2000);
    window.addEventListener('storage', syncLogin);
    window.addEventListener('focus', syncLogin);

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
        body: JSON.stringify({ session_id: sessionId(), message: text })
      })
        .then(function (r) {
          return r.json().then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
          if (res.status === 401) {
            // Token expired or revoked: Pro needs a fresh login.
            try { sessionStorage.removeItem(STORAGE_KEY); } catch (err) { /* storage blocked */ }
            pending.textContent = res.data.reply || 'Your session has expired. Please log in again.';
            return;
          }
          if (res.data.session_id) saveSessionId(res.data.session_id);
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

  window.BitenxtChat = { init: init };

  if (script && script.hasAttribute('data-auto-init')) {
    var tokenKey = script.getAttribute('data-token-key');
    var start = function () {
      if (!tokenKey) {
        if (window.console) console.warn('BitenxtChat: data-token-key is required; chat is for logged-in users only.');
        return;
      }
      init({
        title: script.getAttribute('data-title') || undefined,
        getToken: function () {
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
