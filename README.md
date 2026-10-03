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
| Anonymous use | Every message must carry a valid Magento customer token, checked with Magento first. Without one the request is refused (401) before any session is loaded or Claude is called. | `ChatService::handle` |
| Session hijacking | A session belongs to the customer who started it. A request for another customer gets a fresh session, and one without a login gets 401. Neither sees the earlier history. | `ChatService::bindIdentity` |
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

To run on your own server instead of Cloud Run, point the web server's document root at `public/` and keep
`var/` writable and outside the web root (the default layout already does this). Always serve over HTTPS.

## Deploy to Google Cloud Run

The repo includes a `Dockerfile` (PHP 8.3 + Apache) and a deploy script. You run one command and get an
HTTPS URL; the widget and the chat API are both served from it.

```bash
cp deploy/env.example.yaml deploy/env.yaml      # set MAGENTO_GRAPHQL_URL, ALLOWED_ORIGINS, support contacts
gcloud auth login
PROJECT_ID=your-gcp-project REGION=asia-south1 ./deploy/cloudrun-deploy.sh
```

The script is safe to re-run, and each run deploys a new revision. It:

1. enables the Cloud Run, Cloud Build, Artifact Registry, Firestore and Secret Manager APIs;
2. creates a Firestore database for chat sessions and rate limits, with TTL policies so old data is deleted
   automatically;
3. creates a dedicated service account that can only use Firestore and read the one secret;
4. asks for your Anthropic API key once and stores it in Secret Manager (it never goes into the image or
   `env.yaml`);
5. builds the image with Cloud Build and deploys it, then prints the service URL and the widget snippet.

When it finishes you have, for example, `https://bitenxt-support-agent-abc123-el.a.run.app`:

| URL | What it is |
|---|---|
| `/widget.js` | The chat widget to embed in the Pro frontend |
| `/chat` | The chat API (`POST`) the widget calls |
| `/demo.html` | Test page: paste a UAT customer token to chat as that customer |
| `/health` | Health check |

Then add one line to the Pro frontend (UAT: `https://uat-pro.bitenxt.com`, which is already in
`ALLOWED_ORIGINS` in `deploy/env.example.yaml`; add the production Pro domain there when you go live and re-run
the script):

```html
<script src="https://bitenxt-support-agent-abc123-el.a.run.app/widget.js"
        data-auto-init data-token-key="customerToken"></script>
```

The widget uses the Bearer token the customer already gets when logging in to Pro. **For the frontend team,
[docs/INTEGRATION.md](docs/INTEGRATION.md)** covers how to find where Pro stores the token, the one-tag options
(localStorage, sessionStorage, cookie, or JSON values), `BitenxtChat.setToken(token)` for tokens held in
app state, and how to check the integration.

What changes on Cloud Run compared with running locally:

- **Shared state:** sessions and rate limits live in Firestore (`STORAGE_BACKEND=firestore`), so any number
  of instances behave like one. They are read through Firestore's REST API using the service account, so no
  key file or gRPC extension is needed.
- **Logs:** they go to Cloud Logging as structured entries (`LOG_TARGET=stderr`). Filter with
  `jsonPayload.event="reply_blocked"`, or similar, in Logs Explorer to review guardrail activity and handoffs.
- **Client IP:** it comes from `X-Forwarded-For` (`TRUSTED_PROXY_HOPS=1`), so per-IP rate limits apply to
  real visitors.
- **Public access:** the service accepts unauthenticated requests (`--allow-unauthenticated`), which a
  public chat widget needs. Access to data is controlled by the customer's Magento token and the guardrails
  above, not by Cloud Run IAM. If your organisation blocks `allUsers`, an admin has to allow it for this
  service.
- **Scaling and cost:** it scales to zero when idle (`--min-instances 0`), so the first message after a quiet
  period takes a second or two longer. Use `--min-instances 1` if that matters.
- **Custom domain (optional):** to serve it as `chat.bitenxt.com`, use a Cloud Run domain mapping, or an
  external HTTPS load balancer with a serverless NEG. With a load balancer, set `TRUSTED_PROXY_HOPS` to `2`.

Updating: edit the code or the help articles in `knowledge/`, then re-run the script. To roll back, choose an
earlier revision under Cloud Run → Revisions.

To test the image locally (file storage, no GCP needed):

```bash
docker build -t bitenxt-support-agent .
docker run -p 8080:8080 --env-file .env -e STORAGE_BACKEND=file -e STORAGE_DIR=/tmp/support-agent -e LOG_TARGET=stderr -e TRUSTED_PROXY_HOPS=0 \
  bitenxt-support-agent
# open http://localhost:8080/demo.html
```

### Call it from the Pro frontend

The frontend already has the customer's token from `generateCustomerTokenWithId`. The widget sends it with each
message (see the snippet in the Cloud Run section above). To build your own UI instead, call the API directly:

```http
POST /chat
Authorization: Bearer <customer's Magento token>      (required)
Content-Type: application/json

{"session_id": "<from the previous reply, or null>", "message": "Where is order 000001234?"}
```

```json
{"session_id": "…", "reply": "Order 000001234 shipped on …"}
```

**Chat is for logged-in Pro customers only.** A request without a valid Magento customer token gets
`401 {"error": "login_required"}`, and Claude is never called. If Magento can't verify the token (for example,
the VM is down), the response is `503`. The widget shows its chat button only while a token is present. It
hides the chat and clears the conversation when the customer logs out or a different customer logs in. When a
token expires it asks the customer to log in again. The same customer logging in again with a new token keeps
their conversation.

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
4. **Try to break it.** Ask for other clinics' orders, the system prompt, "the PHP code", SQL, or a role
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
public/index.php            HTTP entry: CORS, auth header, client IP, JSON in/out, no error output
public/widget.js            Embeddable chat widget (renders text only, no HTML)
public/demo.html            Test page served by the service
Dockerfile, docker/         Cloud Run image (PHP 8.3 + Apache on $PORT)
deploy/                     Cloud Run deploy script + environment template
src/Chat/ChatService.php    Request pipeline and session/identity binding
src/Agent/                  System prompt, tool definitions + execution, Claude loop
src/Magento/                GraphQL client, fixed customer-scoped queries, field allow-listing
src/Guardrails/             Input guard, output guard, rate limiters (file / Firestore)
src/Session/                Session model; file store (local) and Firestore store (Cloud Run)
src/Gcp/FirestoreClient.php Minimal Firestore REST client (metadata-server auth)
src/Knowledge/              Help-article search over knowledge/*.md
tests/                      Offline tests with a fake Magento and a scripted Claude
```
