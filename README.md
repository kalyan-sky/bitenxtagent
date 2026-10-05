# BiteNXT Support Agent

A customer-support chatbot for the BiteNXT Pro portal. Clinics can ask about their orders (status, items,
tracking, follow-up notes), search products and services, get answers from your help articles, and be handed
to a person when the bot can't help. It's a small standalone PHP service. It talks to Magento only through
the customer's own GraphQL token. The AI is configurable: by default **Gemini answers first and OpenRouter
takes over automatically** if Gemini is down, rate-limited or refuses (OpenRouter can serve Claude, GPT and other
models with one key; Anthropic can also be called directly). Any OpenAI-compatible provider can be added
through environment variables. Token limits cap AI spend per reply, per customer and for the whole service.

```
 Pro frontend ──(message + customer's Magento token)──▶  POST /chat  (this service)
                                                           │
                         rate limit → input guard → verify token with Magento → session
                                                           │
          token budget check → Gemini ⇢ (fallback) OpenRouter   (tool-use loop, 7 fixed tools)
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
| Exposing code or internals | The model never writes GraphQL or SQL; queries are hard-coded. Every reply is scanned for code, SQL/GraphQL, stack traces, server paths, PHP namespaces and API keys. A hit replaces the whole reply and removes that turn from history. Magento and AI-provider errors are logged, never shown. | `Guardrails/OutputGuard.php`, `ChatService.php` |
| System-prompt extraction | The prompt carries a random canary marker. If a reply contains it, the reply is blocked. | `App::canary`, `OutputGuard` |
| Prompt injection | The input guard strips invisible/control characters and flags injection attempts in the log. The system prompt says its rules override anything in the conversation. The structural limits above hold even if the model is fooled. | `Guardrails/InputGuard.php`, `Agent/SystemPrompt.php` |
| Anonymous use | Every message must carry a valid Magento customer token, checked with Magento first. Without one the request is refused (401) before any session is loaded or the AI is called. | `ChatService::handle` |
| Reading someone else's chat | The client never sends a conversation ID. The server finds the thread from the verified customer only, so there is no ID to guess or steal. | `ChatService::currentConversation` |
| Contact details in replies | Emails, phone numbers and card numbers (Luhn-checked) are redacted unless they are the customer's own, your public support contacts, or IDs from the customer's own orders. | `OutputGuard::filter` |
| Runaway AI cost (attack or spike) | Before every AI call, its estimated tokens are reserved against a per-customer daily limit, service-wide hourly and daily limits, and optional per-provider daily limits; over a limit, the AI is not called. Usage is then corrected to what the provider reported. Output is capped per reply. Counters are shared in Firestore and fail closed. | `Budget/TokenBudget.php`, `Agent/SupportAgent.php` |
| Credentials | The Magento token is used per request and never stored or sent to the AI. AI API keys live in Secret Manager. The service holds no admin token. Logs pseudonymise customer IDs and contain no chat text. | `public/index.php`, `Support/Logger.php` |
| Made-up policies | Policy answers must come from `search_help_articles`. With no match, the bot says so and offers a person. | `SystemPrompt`, `knowledge/` |

The tests in `tests/ChatServiceTest.php` check these guarantees against two fake clinics: cross-clinic
isolation, session hijacking, blocked replies being removed from history, the cap on order-number guessing,
and tokens never reaching the model.

## Setup

Requirements: PHP 8.1+ with `curl`, `json` and `mbstring`, plus Composer.

```bash
composer install
cp .env.example .env        # then fill in LLM_GEMINI_MODEL/_API_KEY, LLM_CLAUDE_API_KEY, MAGENTO_GRAPHQL_URL, ...
composer test               # 29 tests, all offline
composer serve              # http://127.0.0.1:8080 for local testing
```

To run on your own server instead of Cloud Run, point the web server's document root at `public/` and keep
`var/` writable and outside the web root (the default layout already does this). Always serve over HTTPS.

## Deploy to Google Cloud Run

The widget and the chat API are both served from the Cloud Run service URL.

### Recommended: Cloud Build trigger (console only, no CLI)

[`cloudbuild.yaml`](cloudbuild.yaml) runs on every push to `main`: tests → build image → push to Artifact
Registry → deploy to Cloud Run. If a test fails, nothing is deployed. The one-time console setup (APIs,
Artifact Registry, Firestore, secret, service accounts, GitHub trigger, environment variables) is described
click by click in **[docs/DEPLOY-CONSOLE.md](docs/DEPLOY-CONSOLE.md)**.
To keep the monthly bill under $10 (Gemini Flash-Lite, free-tier Cloud Run, billing alert), follow
**[docs/LOW-COST.md](docs/LOW-COST.md)**.

### Alternative: one command from a terminal

The same setup and deploy can be done with the `gcloud` CLI and the included script:

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
4. asks for your Gemini and Anthropic API keys once and stores it in Secret Manager (it never goes into the image or
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
POST /chat                                   GET /chat/history
Authorization: Bearer <customer token>       Authorization: Bearer <customer token>
{"message": "Where is order 000001234?"}

→ {"reply": "Order 000001234 shipped on …", "at": 1791000000}
→ {"messages": [{"role": "user", "text": "…", "at": 1791000000}, {"role": "assistant", …}]}
```

The client never sends a conversation ID. The server works out the customer's thread from the verified token.

**Chat is for logged-in Pro customers only.** A request without a valid Magento customer token gets
`401 {"error": "login_required"}`, and the AI is never called. If Magento can't verify the token (for example,
the VM is down), the response is `503`. The widget shows its chat button only while a token is present and hides it when the
customer logs out. When a token expires, it asks the customer to log in again.

### Chat history

Chat history works like Amazon's customer-service chat:

- **One continuous thread per customer.** Opening the chat shows earlier messages with date dividers ("Today
  10:42", "Yesterday 15:03", "2 Oct, 09:10"). It's the same after a page refresh, in another tab and on another
  device, because it belongs to the logged-in customer, not the browser.
- **The bot starts fresh after a quiet spell.** After `CONVERSATION_IDLE_MINUTES` (default 30) without
  messages, or after `MAX_TURNS_PER_CONVERSATION` messages, the next message starts a new conversation for
  the AI. That keeps answers focused and cost predictable. The customer still sees the whole thread.
- **History is kept `HISTORY_RETENTION_DAYS`** (default 90) after the last message, then Firestore deletes it
  automatically.
- **The thread shows exactly what the customer saw.** Redacted values stay redacted and blocked replies never
  reappear. The AI's internal tool data is never shown.

Chat history can contain patient-related details the customer typed, so treat Firestore as personal-data
storage. Access is limited to the service account. Shorten `HISTORY_RETENTION_DAYS` if your data policy needs it.

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
| `LLM_PROVIDERS` | `gemini,openrouter` | AI providers in order: primary, then fallbacks |
| `LLM_GEMINI_MODEL` | (required) | Gemini model ID from Google AI Studio |
| `LLM_OPENROUTER_MODEL` | (required) | OpenRouter model ID that supports tool calling, e.g. `anthropic/<claude model>` |
| `LLM_GEMINI_API_KEY` / `LLM_OPENROUTER_API_KEY` | (secrets) | From Secret Manager. Several comma-separated keys are tried in turn on 401/403/429. |
| `LLM_CLAUDE_MODEL` / `LLM_CLAUDE_EFFORT` | `claude-opus-5-5` / `low` | Only if you call Anthropic directly (`claude` in `LLM_PROVIDERS`) |
| `LLM_<NAME>_DAILY_TOKEN_LIMIT` | 0 (none) | Per-provider cap; over it, the next provider answers |
| `MAX_OUTPUT_TOKENS` | 400 | Most tokens one AI reply can produce |
| `TOKEN_LIMIT_CUSTOMER_PER_DAY` | 100000 | Per logged-in customer (input + output tokens) |
| `TOKEN_LIMIT_GLOBAL_PER_HOUR` / `_PER_DAY` | 200000 / 1000000 | Whole service (1M/day caps Gemini Flash-Lite at about $5 a month) |
| `AI_HISTORY_TURNS` | 6 | Only the last N question/answer pairs are sent to the AI (keeps each call small) |
| `FAST_PATH` | on | Answer order status and "my orders" from a template, with no AI call. `off` sends everything to the AI |
| `MAX_MESSAGE_CHARS` | 2000 | |
| `HISTORY_RETENTION_DAYS` | 90 | How long a customer's chat history is kept after their last message |
| `CONVERSATION_IDLE_MINUTES` | 30 | Quiet time after which the bot starts a fresh conversation (the thread is kept) |
| `MAX_TURNS_PER_CONVERSATION` | 40 | Messages per conversation before the bot starts a fresh one |
| `RATE_LIMIT_PER_MINUTE` / `_PER_DAY` | 10 / 200 | Applied per IP and per customer |

**Adding another AI provider:** any provider with an OpenAI-compatible Chat Completions API (OpenAI, Azure
OpenAI, Mistral, Groq, DeepSeek, OpenRouter, Ollama, …) works without code changes. Add its name to
`LLM_PROVIDERS` and set `LLM_<NAME>_TYPE=openai-compatible`, `LLM_<NAME>_BASE_URL`, `LLM_<NAME>_MODEL`, and
`LLM_<NAME>_API_KEY` as a secret (add it to `_SECRETS` in the Cloud Build trigger).

**How fallback works:** each message goes to the first provider. If it errors, times out, is rate-limited,
refuses, or is over its own daily token limit, the next provider answers, starting from the last messages the
customer saw. The next message tries the primary again. On Claude, requests also use server-side refusal
fallbacks (`fallbacks: "default"`), and the system prompt and tools are prompt-cached.

## Layout

```
public/index.php            HTTP entry: CORS, auth header, client IP, JSON in/out, no error output
public/widget.js            Embeddable chat widget (renders text only, no HTML)
public/demo.html            Test page served by the service
Dockerfile, docker/         Cloud Run image (PHP 8.3 + Apache on $PORT)
cloudbuild.yaml             Cloud Build pipeline: test, build, push, deploy (docs/DEPLOY-CONSOLE.md)
deploy/                     CLI deploy script + environment template (alternative to Cloud Build)
src/Chat/ChatService.php    Request pipeline and session/identity binding
src/Agent/                  System prompt, tool definitions + execution, provider-chain tool loop
src/Llm/                    AI providers: Anthropic (SDK) and OpenAI-compatible (Gemini, OpenAI, …), settings
src/Budget/                 Token budget: reservations, limits, Firestore/file counters
src/Magento/                GraphQL client, fixed customer-scoped queries, field allow-listing
src/Guardrails/             Input guard, output guard, rate limiters (file / Firestore)
src/Session/                Conversation model + transcript; file store (local) and Firestore store (Cloud Run)
src/Gcp/FirestoreClient.php Minimal Firestore REST client (metadata-server auth)
src/Knowledge/              Help-article search over knowledge/*.md
tests/                      Offline tests with a fake Magento, a scripted Claude and a scripted Gemini (HTTP)
```
