# Adding the support chat to BiteNXT Pro

The chat is for **logged-in Pro customers only**. At login, Pro already receives a Bearer token from Magento
(`generateCustomerTokenWithId`). The widget sends that same token with every chat message as
`Authorization: Bearer <token>`, and the chat service checks it with Magento. Without a valid token, the chat
button is not shown and the API refuses the request (`401`).

You only need to tell the widget where Pro keeps that token.

## 1. Find where Pro stores the token

Log in to `https://uat-pro.bitenxt.com`, open dev tools (F12), then **Application**:

- **Local Storage → `https://uat-pro.bitenxt.com`**: look for the entry whose value is the long token string
  (often `token`, `customerToken`, `authToken`). Note the **key name**. If the value looks like JSON
  (`{"user":{"token":"…"}}`), also note the **path** to the token (`user.token`).
- **Session Storage**: same check.
- **Cookies**: the cookie name holding the token.
- **None of these**: the token lives only in app state (React/Redux, Vue, Angular). Use option C below.

## 2. Add the widget (pick one)

Replace `https://YOUR-SERVICE-xxxx.a.run.app` with the URL printed by `deploy/cloudrun-deploy.sh`. Put the tag
just before `</body>` in Pro's main HTML template (`index.html`), so it loads on every page.

### A. Token in localStorage (most common)

```html
<script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"
        data-auto-init data-token-key="customerToken"></script>
```

If the stored value is JSON, add the path to the token:

```html
<script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"
        data-auto-init data-token-key="auth" data-token-path="user.token"></script>
```

### B. Token in sessionStorage or a cookie

```html
<!-- sessionStorage -->
<script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"
        data-auto-init data-token-key="customerToken" data-token-source="sessionStorage"></script>

<!-- cookie (must not be HttpOnly, since the page's JavaScript has to read it) -->
<script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"
        data-auto-init data-token-key="pro_token" data-token-source="cookie"></script>
```

### C. Token only in app state

Load the script without `data-auto-init`, then push the token from your login/logout code:

```html
<script src="https://YOUR-SERVICE-xxxx.a.run.app/widget.js"></script>
```

```js
// after a successful login (where you receive the token from generateCustomerTokenWithId)
window.BitenxtChat.setToken(token);

// on logout
window.BitenxtChat.setToken(null);

// on page reload, if the user is still logged in, call setToken(token) again once the app has the token
```

Alternatively, give the widget a function it can call: `BitenxtChat.init({ getToken: () => store.getState().auth.token || null })`.

## 3. What the user sees

| Situation | Widget behaviour |
|---|---|
| Not logged in | No chat button |
| Logs in | Chat button appears within about 2 seconds, with no page reload needed |
| Opens the chat | Their earlier messages are shown (last 90 days), with date dividers, like Amazon's chat |
| Refreshes, opens another tab or another device | Same thread, nothing lost |
| Logs out | Chat closes and disappears |
| Another user logs in on the same browser | Sees only their own thread |
| Token expired or revoked | Next message: "Please log in to your BiteNXT Pro account to use support chat." |

## 4. Check it

1. **Before touching Pro:** open `https://YOUR-SERVICE-xxxx.a.run.app/demo.html`, paste a UAT customer token,
   and ask about one of that customer's orders.
2. **After adding the tag:** log in to UAT Pro. The chat button should appear in the bottom-right corner.
   Ask "what are my recent orders?"
3. **If the button never appears:** open the browser console. A warning about `data-token-key` means the
   attribute is missing. Otherwise, check that the key, source and path match step 1:
   `localStorage.getItem('<your key>')` should return the token.
4. **If messages fail with a network/CORS error:** check that `https://uat-pro.bitenxt.com` is listed in
   `ALLOWED_ORIGINS` in `deploy/env.yaml`, and re-run the deploy script.

## API (if you build your own chat UI instead)

```http
POST https://YOUR-SERVICE-xxxx.a.run.app/chat
Authorization: Bearer <customer token>
Content-Type: application/json

{"message": "Where is order 000001234?"}
```

```http
GET https://YOUR-SERVICE-xxxx.a.run.app/chat/history
Authorization: Bearer <customer token>
```

The server finds the customer's thread from the token, so there is no conversation ID to keep track of.

| Status | Body | Meaning |
|---|---|---|
| 200 | `{"reply": "…", "at": 1791000000}` | Reply to show (`at` = Unix time) |
| 200 (history) | `{"messages": [{"role": "user" or "assistant", "text": "…", "at": 1791000000}, …]}` | The customer's thread, oldest first (up to 200 messages) |
| 400 | `{"error": "invalid_message", "reply": "…"}` | Empty or too-long message |
| 401 | `{"error": "login_required", "reply": "…"}` | Missing, invalid or expired token |
| 429 | `{"error": "rate_limited", "reply": "…"}` | Too many messages; wait a minute |
| 503 | `{"error": "unavailable", "reply": "…"}` | Magento couldn't verify the token (e.g. VM down) |
