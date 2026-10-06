# `<bitenxt-chat>` web component (for the Pro frontend team)

The support chat ships as a standard web component. It works in React, Angular, Vue or plain HTML with no
library, and it renders inside Shadow DOM, so Pro's CSS can't break it and its CSS can't leak into Pro. You
restyle it from Pro's own stylesheet with **CSS custom properties** and **`::part()`**.

Try it: `https://<chat-service-url>/demo-component.html` (paste a UAT customer token).

## 1. Add it

```html
<script src="https://<chat-service-url>/bitenxt-chat.js" defer></script>

<!-- Anywhere in the logged-in layout (e.g. App shell). Floating button bottom-right. -->
<bitenxt-chat token-key="customerToken"></bitenxt-chat>
```

The script is served by the chat service and always matches the backend, so there is nothing to install or
version. The API address defaults to the same server (`/chat`); set `api-url` only to override it.

**Allowed origins.** The chat API only answers pages listed in the service's `ALLOWED_ORIGINS`
(currently `https://uat-pro.bitenxt.com`). Ask for production or local dev origins to be added, e.g.
`http://localhost:3000`.

## 2. Give it the customer's login

Chat is for logged-in customers only: nothing shows until the component has the customer's Magento token
(the Bearer token from `generateCustomerTokenWithId`). The component sends it with each request and never
displays or stores it. Pick one:

| How Pro keeps the token | Use |
|---|---|
| `localStorage` / `sessionStorage` / cookie | `token-key="customerToken"` (+ `token-source="sessionStorage"` or `"cookie"`, and `token-path="auth.token"` if the stored value is JSON) |
| App state (Redux, context, Apollo) | `element.token = token` after login, `element.token = null` on logout |
| A function you already have | `element.getToken = () => store.getState().auth.token` |

Login and logout are picked up automatically (the component checks every 2 seconds and on tab focus).
When the token is missing or rejected it fires `bitenxt-auth-required`.

## 3. Match Pro's theme

```css
bitenxt-chat {
  --bnx-primary: #d65897;               /* buttons, header, your messages */
  --bnx-font: "Poppins", sans-serif;     /* inherit Pro's font */
  --bnx-radius: 16px;
}
bitenxt-chat::part(launcher) { box-shadow: none; }
bitenxt-chat::part(message-user) { border-bottom-right-radius: 4px; }
```

### CSS custom properties

| Property | Default | What it styles |
|---|---|---|
| `--bnx-primary` | `#d65897` | Header, launcher, send button, chips, your messages |
| `--bnx-primary-contrast` | `#ffffff` | Text on primary |
| `--bnx-primary-soft` | `#fbeaf3` | Call bar, chip hover |
| `--bnx-font` | `inherit` | Font family |
| `--bnx-font-size` | `14px` | Base text size |
| `--bnx-text` / `--bnx-muted` | `#1d2327` / `#6b7680` | Text / dates and hints |
| `--bnx-bg` / `--bnx-log-bg` | `#ffffff` / `#f7f8fa` | Panel / message area |
| `--bnx-border` | `#e4e7eb` | Borders |
| `--bnx-bot-bg` / `--bnx-bot-text` | white / text | Assistant messages |
| `--bnx-user-bg` / `--bnx-user-text` | primary / contrast | Customer messages |
| `--bnx-radius` / `--bnx-bubble-radius` | `14px` / `12px` | Panel / message corners |
| `--bnx-shadow` | soft shadow | Panel and launcher shadow |
| `--bnx-width` / `--bnx-height` | `380px` / `560px` | Panel size (full width on phones) |
| `--bnx-offset-x` / `--bnx-offset-y` | `20px` | Distance from the screen corner |
| `--bnx-z-index` | `2147483000` | Stacking (lower it if it covers Pro's modals) |

### Parts (`bitenxt-chat::part(name)`)

`launcher`, `panel`, `header`, `title`, `close`, `call`, `log`, `separator`, `message`, `message-user`,
`message-bot`, `chips`, `chip`, `rating`, `form`, `input`, `send`.

### Slots

| Slot | Replaces |
|---|---|
| `launcher` | Button content, e.g. `<span slot="launcher"><img src="/icons/chat.svg" alt=""> Help</span>` |
| `heading` | Header title, e.g. a logo |

## 4. Attributes

| Attribute | Default | |
|---|---|---|
| `token-key`, `token-source`, `token-path` | | Where to read the token (section 2) |
| `heading` | `BiteNXT Support` | Header title |
| `greeting` | built-in | First message when the thread is empty |
| `placeholder` | `Type your message…` | Input placeholder |
| `suggestions` | built-in chips | JSON array, e.g. `'["Track my order","Talk to support"]'`; `'[]'` hides chips |
| `support-phone` | service's `SUPPORT_PHONE` | "Urgent? Call us" bar; empty hides it |
| `position` | `right` | `left` to dock bottom-left |
| `mode` | floating | `inline` fills its container (for a "Talk to Experts" page); no launcher |
| `open` | | Present = panel open (reflects state) |
| `api-url` | same server `/chat` | Chat API address |

## 5. Methods and events

```js
const chat = document.querySelector('bitenxt-chat');
chat.open(); chat.close(); chat.toggle();
chat.send('status of order 728');   // e.g. a "Need help with this order?" link on the order page

chat.addEventListener('bitenxt-auth-required', () => router.push('/login'));
```

| Event | `detail` |
|---|---|
| `bitenxt-open` / `bitenxt-close` | `{}` |
| `bitenxt-message-sent` | `{ text }` |
| `bitenxt-reply` | `{ text, status }` (status 200 = answered) |
| `bitenxt-feedback` | `{ rating: 'up' \| 'down' }` |
| `bitenxt-auth-required` | `{}`: token missing, expired or rejected |

Events bubble and cross the shadow boundary, so they can be handled on `document` too (e.g. for analytics).

## 6. Framework examples

**React** (TypeScript types: copy `src/Widget/bitenxt-chat.d.ts` from the chat repo)

```tsx
import { useEffect, useRef } from 'react';
import type { BitenxtChatElement } from './types/bitenxt-chat';

export function SupportChat({ token }: { token: string | null }) {
  const ref = useRef<BitenxtChatElement>(null);
  useEffect(() => { if (ref.current) ref.current.token = token; }, [token]);
  useEffect(() => {
    const el = ref.current;
    const onAuth = () => { /* send the customer to login */ };
    el?.addEventListener('bitenxt-auth-required', onAuth);
    return () => el?.removeEventListener('bitenxt-auth-required', onAuth);
  }, []);
  return <bitenxt-chat ref={ref} heading="BiteNXT Support" />;
}
```

Load the script once (e.g. in `index.html`, or `next/script` with `strategy="afterInteractive"` in Next.js).
Render `<SupportChat>` only in the logged-in layout.

**Angular**: add `CUSTOM_ELEMENTS_SCHEMA` to the module/standalone component, then
`<bitenxt-chat #chat token-key="customerToken"></bitenxt-chat>` and `this.chat.nativeElement.token = token`.

**Vue**: `compilerOptions.isCustomElement = tag => tag === 'bitenxt-chat'`, then
`<bitenxt-chat :token.prop="token" />`.

## 7. Notes

- After each answer the server may send `quick_replies` (e.g. "Follow-ups on order 728"); the component shows
  them as chips under the reply, styled by `::part(chip)`.
- Replies are inserted as text only (never HTML), so a reply can't inject markup.
- Text is English; replies follow the customer's language.
- The older one-tag `widget.js` keeps working; use the component for new work.
