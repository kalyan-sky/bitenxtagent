# BiteNXT Support Agent

A customer-support chatbot for the BiteNXT Pro portal. Clinics can ask about their orders (status, items,
tracking, follow-up notes), search products and services, get answers from your help articles, and be handed
to a person when the bot can't help. It's a small standalone PHP service. It talks to Magento only through
the customer's own GraphQL token, and to Claude through the official Anthropic PHP SDK.

```
 Pro frontend ──(message + customer's Magento token)──▶  POST /chat  (this service)
                                                           │
                         rate limit → input guard → verify token with Magento → session
                                                           │
                                     Claude (tool-use loop, 7 fixed tools)
                                                           │
                   tools call fixed GraphQL queries with the CUSTOMER's token → allow-listed fields only
                                                           │
                                 output guard (leak blocking + PII redaction) → reply
```

## Guardrails

The protection is built into the code, so it holds even if someone talks the model into ignoring its prompt.

| Risk | How it's handled | Where |
|---|---|---|
| Seeing another clinic's orders | No tool accepts a customer ID, email or token. Identity comes only from the verified Magento token on the request. Orders are read via `customer { orders }`, which Magento scopes to the token. | `Agent/SupportTools.php`, `Magento/MagentoCustomerDataSource.php` |
| Order-number guessing | "Not found" looks the same whether the order doesn't exist or belongs to someone else. After 5 misses per session, lookups stop. Rate limits apply per IP, session and customer. | `SupportTools::notFound`, `Guardrails/RateLimiter.php` |
| Unsafe custom resolvers | Never called: `salesOrder`, `fetchPatients`, `getCustomerAddresses`, `fetchAttachment`, `listServiceFiles` and every admin operation. `getOrderFollowUps` is only called after ownership is confirmed, and rows with a different `customer_id` are dropped. | `MagentoCustomerDataSource`, `SupportTools::followUps` |
| Leaking personal data | Tool results are allow-listed. Addresses, phones, emails, payment data, file paths, designer assignments and patient age/gender never reach the model. Patient names are shortened to "John S.". | `Magento/OrderPresenter.php` |
| Exposing code or internals | The model never writes GraphQL or SQL; queries are hard-coded. Every reply is scanned for code, SQL/GraphQL, stack traces, server paths, PHP namespaces and API keys. A hit replaces the whole reply and removes that turn from history. Magento/Claude errors are logged, never shown. | `Guardrails/OutputGuard.php`, `ChatService.php` |
| System-prompt extraction | The prompt carries a random canary marker. If a reply contains it, the reply is blocked. | `App::canary`, `OutputGuard` |
| Prompt injection | The input guard strips invisible/control characters and flags injection attempts in the log. The system prompt says its rules override anything in the conversation. The structural limits above hold even if the model is fooled. | `Guardrails/InputGuard.php`, `Agent/SystemPrompt.php` |
| Session hijacking | A session is bound to a hash of the token that started it. A request with a different token, or none, gets a fresh session and never sees earlier history. | `ChatService::bindIdentity` |
| Contact details in replies | Emails, phone numbers and card numbers (Luhn-checked) are redacted unless they are the customer's own, your public support contacts, or IDs from the customer's own orders. | `OutputGuard::filter` |
| Credentials | The Magento token is used per request and never stored or sent to Claude. The service holds no admin token. Logs pseudonymise customer IDs and contain no chat text. | `public/index.php`, `Support/Logger.php` |
| Made-up policies | Policy answers must come from `search_help_articles`. With no match, the bot says so and offers a person. | `SystemPrompt`, `knowledge/` |

The tests in `tests/ChatServiceTest.php` check these guarantees against two fake clinics: cross-clinic
isolation, session hijacking, blocked replies being removed from history, the cap on order-number guessing,
and tokens never reaching the model.

## Setup

Requirements: PHP 8.1+ with `curl`, `json` and `mbstring`, plus Composer.

```bash
composer install
cp .env.example .env        # then fill in ANTHROPIC_API_KEY, MAGENTO_GRAPHQL_URL, ALLOWED_ORIGINS, ...
composer test               # 29 tests, all offline
composer serve              # http://127.0.0.1:8080 for local testing
```

In production, point your web server's document root at `public/` and keep `var/` writable and outside the
web root (the default layout already does this). Always serve over HTTPS.

### Call it from the Pro frontend

The frontend already has the customer's token from `generateCustomerTokenWithId`. Load the widget and hand it
the token:

```html
<script src="https://chat.bitenxt.com/widget.js"></script>
<script>
  BitenxtChat.init({
    apiUrl: 'https://chat.bitenxt.com/chat',
    getToken: () => localStorage.getItem('customerToken'), // wherever Pro keeps it
  });
</script>
```

Or call the API yourself:

```http
POST /chat
Authorization: Bearer <customer's Magento token>      (omit for guests)
Content-Type: application/json

{"session_id": "<from the previous reply, or null>", "message": "Where is order 000001234?"}
```

```json
{"session_id": "…", "reply": "Order 000001234 shipped on …", "signed_in": true}
```

Guests can ask general and product questions. Order questions ask them to sign in first: Magento 2.4.6 has
no `guestOrder` query, and identifying someone by order number alone isn't safe.

### Add your help articles

Copy files from `knowledge/templates/` up into `knowledge/`, then replace every `[placeholder]` with your real
policy. Anything in `knowledge/*.md` may be quoted to customers word for word. See `knowledge/README.txt`.

### Human handoff

Set `HANDOFF_WEBHOOK_URL` to a Slack or Teams incoming webhook, or a helpdesk endpoint, and escalations
arrive there. They are also always appended to `var/handoffs.jsonl`.

## Before going live

1. **Verify the GraphQL fields on UAT.** Bitenxt's `AdminOrder` module overrides `Customer.orders` and the
   order types, and the API reference doesn't list every field. Run an introspection query, or try each
   query in `src/Magento/MagentoCustomerDataSource.php` with a customer token in a GraphQL client, and adjust
   field names (`status_title`, `patient_name`, the `customerAllOrders` filter shape, `getOrderFollowUps`
   fields).
2. **Confirm `customer { orders }` really is token-scoped** in Bitenxt's replacement resolver. Run it with
   clinic A's token and clinic B's order number; you should get nothing back.
3. **Fix the resolver issues from the API reference doc.** They are not caused by this bot, but they are open
   to anyone with a token. `getCustomerAddresses`, `fetchPatients`, `fetchAttachment`, `listServiceFiles`,
   `listCartServiceFiles`, `addCustomerAttachment`, `addPatient` and `sendAppointmentMail` must check the ID
   argument against the token's customer. The bot avoids them, but the Pro API is still exposed.
4. **Behind a load balancer or CDN**, set `REMOTE_ADDR` from the trusted proxy header so per-IP rate limits
   work.
5. **More than one app server:** move `SessionStore` and `RateLimiter` to Redis or MySQL. Each is one small
   class.
6. **Try to break it.** Ask for other clinics' orders, the system prompt, "the PHP code", SQL, or a role
   change, and check `var/logs/chat.jsonl` for `suspicious_input`, `reply_blocked` and `reply_redacted`.

## Configuration

See `.env.example`. Key settings:

| Variable | Default | Notes |
|---|---|---|
| `CLAUDE_MODEL` | `claude-opus-5-5` | |
| `CLAUDE_EFFORT` | `low` | `low` suits support chat. Raise to `medium` if answers feel shallow. |
| `MAX_MESSAGE_CHARS` | 2000 | |
| `MAX_TURNS_PER_SESSION` | 40 | Caps conversation length and cost |
| `RATE_LIMIT_PER_MINUTE` / `_PER_DAY` | 10 / 200 | Applied per IP, per session and per customer |

Requests use server-side refusal fallbacks (`fallbacks: "default"`), so a false positive from a safety
classifier on dental or medical wording is retried on a fallback model instead of failing. The system prompt
and tool list are prompt-cached.

## Layout

```
public/index.php            HTTP entry: CORS, auth header, JSON in/out, no error output
public/widget.js            Embeddable chat widget (renders text only, no HTML)
src/Chat/ChatService.php    Request pipeline and session/identity binding
src/Agent/                  System prompt, tool definitions + execution, Claude loop
src/Magento/                GraphQL client, fixed customer-scoped queries, field allow-listing
src/Guardrails/             Input guard, output guard, rate limiter
src/Session/                Session model + file store
src/Knowledge/              Help-article search over knowledge/*.md
tests/                      Offline tests with a fake Magento and a scripted Claude
```
