# Deploying with Cloud Build and Cloud Run (Google Cloud console)

Every push to `main` on GitHub runs [`cloudbuild.yaml`](../cloudbuild.yaml):

```
push to main → Cloud Build: run tests → build image → push to Artifact Registry → deploy to Cloud Run
```

If a test fails, nothing is deployed. The one-time setup below is all done in the Google Cloud console, with
no command line. The names used here match the defaults in `cloudbuild.yaml`. If you pick other names or
another region, set them as substitution variables on the trigger (step 8).

| Thing | Name used below |
|---|---|
| Region | `asia-south1` (Mumbai). Pick the region closest to your Magento VM. |
| Artifact Registry repository | `bitenxt` |
| Cloud Run service | `bitenxt-support-agent` |
| Runtime service account | `bitenxt-support-agent` |
| Secret with the Gemini API key (primary AI) | `gemini-api-key` |
| Secret with the OpenRouter API key (fallback AI) | `openrouter-api-key` |

## One-time setup

### 1. Enable the APIs

**APIs & Services → Library**, then enable each of: **Cloud Run Admin API**, **Cloud Build API**,
**Artifact Registry API**, **Cloud Firestore API**, **Secret Manager API**.

### 2. Artifact Registry repository (where images are stored)

**Artifact Registry → Repositories → Create repository**
- Name: `bitenxt` · Format: **Docker** · Mode: Standard · Location type: **Region** → `asia-south1` → **Create**.

### 3. Firestore (chat history, rate limits and token usage)

**Firestore → Create database**
- Database ID: `(default)` · Mode: **Native** · Location type: **Region** → `asia-south1` → **Create**.

Then, in that database:
- **Time-to-live (TTL) → Create policy**: collection group `chat_sessions`, timestamp field `expireAt`.
  Repeat for collection groups `chat_ratelimits` and `chat_token_usage`, field `expireAt`. This deletes old
  chats (after `HISTORY_RETENTION_DAYS`), old rate-limit counters and old token counters automatically.
- **Indexes → Single field → Add exemption**: collection `chat_sessions`, field `data`, untick every index
  type → **Save**. The full conversation is never searched, so indexing it would only cost money.

### 4. AI API keys in Secret Manager

The chatbot uses **Gemini first** and **OpenRouter as automatic fallback**. OpenRouter gives one key for many
models (Claude, GPT, …). Get the keys:
- **Gemini:** [Google AI Studio](https://aistudio.google.com) → **Get API key**. Create it in a Google Cloud
  project with billing, so you can set quotas (see "Spend protection" below).
- **OpenRouter:** [openrouter.ai](https://openrouter.ai) → **Keys** → **Create key**. Set a **credit limit** on
  the key, and add credits under **Credits**.

**Security → Secret Manager → Create secret**, once per key:
- Name: `gemini-api-key` · Secret value: the Gemini key → **Create secret**.
- Name: `openrouter-api-key` · Secret value: the OpenRouter key (`sk-or-v1-…`) → **Create secret**.

Every secret the pipeline attaches (`_SECRETS` in `cloudbuild.yaml`) must exist and **have a version**, or the
deploy step fails. To call Anthropic directly instead of through OpenRouter, create `anthropic-api-key`, use
`claude` in `LLM_PROVIDERS`, and set the trigger's `_SECRETS` to
`LLM_GEMINI_API_KEY=gemini-api-key:latest,LLM_CLAUDE_API_KEY=anthropic-api-key:latest`.

To rotate a key, add a **new version** to the secret. The service picks up `latest` on its next deploy or
restart. To give a provider several keys (the next one is tried when one is rate-limited or rejected), put
them in one secret version separated by commas.

### 5. Service account the chatbot runs as

**IAM & Admin → Service Accounts → Create service account**
- Name: `bitenxt-support-agent` → **Create and continue**.
- Role: **Cloud Datastore User** (lets it read and write Firestore) → **Done**.

Let it read the API keys. For **each** secret (`gemini-api-key` and `openrouter-api-key`):
**Secret Manager → secret → Permissions → Grant access**
- Principal: `bitenxt-support-agent@<PROJECT_ID>.iam.gserviceaccount.com` · Role: **Secret Manager Secret
  Accessor** → **Save**.

The service account has no other access: no Magento credentials, no admin rights.

### 6. Service account Cloud Build deploys with

Cloud Build runs the pipeline as a service account you choose in the trigger. Create one for it:

**IAM & Admin → Service Accounts → Create service account**
- Name: `cloud-build-deployer` → **Create and continue**, then add these roles:
  - **Cloud Build Service Account** (`roles/cloudbuild.builds.builder`)
  - **Cloud Run Admin**
  - **Artifact Registry Writer**
  - **Logs Writer**
- **Done**.

It must also be allowed to run the service as `bitenxt-support-agent`: **Service Accounts →
`bitenxt-support-agent` → Permissions → Grant access** · Principal: `cloud-build-deployer@<PROJECT_ID>.iam.gserviceaccount.com`
· Role: **Service Account User** → **Save**.

### 7. Connect the GitHub repository

**Cloud Build → Repositories → 2nd gen → Create host connection** → GitHub → authorise the Google Cloud Build
GitHub app for `kalyan-sky` → then **Link repository** → `kalyan-sky/bitenxtagent`.

### 8. Create the trigger

**Cloud Build → Triggers → Create trigger** (region: `asia-south1`)
- Name: `deploy-support-agent`
- Event: **Push to a branch** · Repository: `kalyan-sky/bitenxtagent` · Branch: `^main$`
- Configuration: **Cloud Build configuration file** · Location: Repository · `cloudbuild.yaml`
- Substitution variables: only needed if you changed any name or region from the table above
  (`_REGION`, `_SERVICE`, `_AR_REPO`, `_RUNTIME_SA`), or if your secrets have other names (`_SECRETS`).
- Service account: `cloud-build-deployer`
- **Create**.

### 9. First deploy

On the trigger list, click **Run** on `deploy-support-agent` (branch `main`). Watch it in **Cloud Build →
History**. It takes about 3–5 minutes. When it's green, the Cloud Run service `bitenxt-support-agent` exists.

### 10. Set the chatbot's settings on the Cloud Run service

**Cloud Run → `bitenxt-support-agent` → Edit & deploy new revision → Variables & Secrets → Environment
variables**. Add:

| Name | Value | Required |
|---|---|---|
| `MAGENTO_GRAPHQL_URL` | `https://uat-magento.bitenxt.com/graphql` | yes |
| `ALLOWED_ORIGINS` | `https://uat-pro.bitenxt.com` (comma-separate several) | yes |
| `SUPPORT_EMAIL` | your support email | yes |
| `SUPPORT_PHONE` | support phone, or leave out | no |
| `STORE_NAME` | `BiteNXT` | no (default) |
| `LLM_PROVIDERS` | `gemini,openrouter` (order = primary, then fallback) | yes |
| `LLM_GEMINI_MODEL` | the Gemini model ID from Google AI Studio (e.g. a current Gemini Flash model) | yes |
| `LLM_OPENROUTER_MODEL` | a model ID from openrouter.ai/models that supports **tool calling**, e.g. `anthropic/<claude model>` | yes |
| `HANDOFF_WEBHOOK_URL` | Slack/Teams incoming webhook for "talk to a person" | no |
| `HISTORY_RETENTION_DAYS` | `90` | no (default) |
| `CONVERSATION_IDLE_MINUTES` | `30` | no (default) |
| `MAX_OUTPUT_TOKENS` | `400` (most tokens one AI reply can produce) | no (default) |
| `TOKEN_LIMIT_CUSTOMER_PER_DAY` | `100000` | no (default) |
| `TOKEN_LIMIT_GLOBAL_PER_HOUR` | `200000` | no (default) |
| `TOKEN_LIMIT_GLOBAL_PER_DAY` | `1000000` | no (default) |
| `LLM_GEMINI_DAILY_TOKEN_LIMIT` | e.g. `3000000`; over it, OpenRouter answers instead (`0` = no limit) | no |

The key secrets are already attached by the pipeline as `LLM_GEMINI_API_KEY` and `LLM_OPENROUTER_API_KEY`. You'll
see them under **Secrets exposed as environment variables**. → **Deploy**.

To switch which AI answers first, change the order in `LLM_PROVIDERS` here (for example `openrouter,gemini`). No
code change or rebuild is needed.

These settings stay in place on every later deploy from Cloud Build. To change one, repeat this step.

### 11. Check it

- **Cloud Run → `bitenxt-support-agent`**: copy the URL at the top (e.g.
  `https://bitenxt-support-agent-xxxxx.asia-south1.run.app`).
- Open `<URL>/health` → `{"ok":true}`.
- Open `<URL>/demo.html`, paste a UAT customer token, and ask about one of that customer's orders.
- Add the widget to Pro. See [INTEGRATION.md](INTEGRATION.md):
  ```html
  <script src="<URL>/widget.js" data-auto-init data-token-key="customerToken"></script>
  ```

## Spend protection (token limits and alerts)

The app itself stops calling the AI when a limit is reached:

| Limit | Variable | What the customer sees |
|---|---|---|
| Per reply | `MAX_OUTPUT_TOKENS` (400) | (answers are just capped) |
| Per customer per day | `TOKEN_LIMIT_CUSTOMER_PER_DAY` (100,000) | "You've reached today's chat limit…" |
| Whole service per hour / day | `TOKEN_LIMIT_GLOBAL_PER_HOUR` (200k) / `_PER_DAY` (1M) | "Chat is temporarily unavailable" |
| One provider per day | `LLM_<NAME>_DAILY_TOKEN_LIMIT` | nothing: the next provider answers |

Tokens are reserved **before** each AI call and corrected to the real usage afterwards, so a burst of requests
can't overshoot. If the counters (Firestore) can't be reached, no AI calls are made.

**Get an email when a limit is hit:**
1. **Logging → Logs Explorer**, query: `jsonPayload.event="token_budget_exceeded"`
2. **Create alert** (or **Actions → Create log alert**) → name `Chatbot token limit reached` → notification
   channel: your email → **Save**.
   Do the same for `jsonPayload.event="llm_all_failed"` (no AI provider could answer) if you like.

**Also set limits at the providers.** This is a second safety net that still works if the app's counters fail:
- **Gemini:** in the Google Cloud project that owns the Gemini key, **Billing → Budgets & alerts → Create
  budget** (alert emails). Under **APIs & Services → Generative Language API → Quotas**, lower the requests
  per minute and tokens per minute to a level you're comfortable with.
- **OpenRouter:** **openrouter.ai → Keys → (your key) → Edit**: set a **credit limit** on the key. Keep
  auto top-up off, or capped, under **Credits**.

**See usage:** every AI call is logged as `jsonPayload.event="llm_call"`, with provider, model and
input/output tokens, and no chat text.

## Day to day

- **Deploy a change:** merge to `main`. Cloud Build tests, builds and deploys automatically.
- **See a build:** **Cloud Build → History**. A red build means a test failed and nothing was deployed.
- **Roll back:** **Cloud Run → service → Revisions → Manage traffic** → send 100% to the previous revision.
- **Logs:** **Cloud Run → service → Logs**, or Logs Explorer filtered by `jsonPayload.event`, for example
  `reply_blocked`, `suspicious_input`, `handoff`, `magento_error`.
- **Change settings:** step 10.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Build fails at **deploy** with `iam.serviceaccounts.actAs` | Step 6: give `cloud-build-deployer` **Service Account User** on `bitenxt-support-agent`. |
| Build fails at **push** with permission denied | Step 6: **Artifact Registry Writer**, and the repository region must match `_REGION`. |
| Service fails to start: secret not found or permission denied | Step 4/5: the secrets must be named `gemini-api-key` and `openrouter-api-key` (or set `_SECRETS` on the trigger), each must have a version, and the runtime service account needs **Secret Manager Secret Accessor** on each. |
| Every reply comes from OpenRouter, never Gemini | Logs: look for `llm_config_error` (e.g. `LLM_GEMINI_MODEL` not set) or `llm_fallback` with the Gemini error (wrong model ID, key, or quota). |
| "Chat is temporarily unavailable" for everyone | A service-wide token limit was reached (`token_budget_exceeded`, scope `global`), or Firestore is unreachable (`token_budget_unavailable`). Raise the limit if the traffic is genuine. |
| `/health` works but every chat says "Please log in" | The widget isn't sending the token (see INTEGRATION.md), or the token is from a different Magento than `MAGENTO_GRAPHQL_URL`. |
| Chat says "We can't verify your account right now" | Cloud Run can't reach Magento. Check `MAGENTO_GRAPHQL_URL`, and whether the VM's firewall blocks Google Cloud IPs. If it allows only listed IPs, you need a fixed outgoing IP (Cloud NAT). |
| Browser console shows a CORS error | Add the Pro site's exact origin (`https://…`, no trailing slash) to `ALLOWED_ORIGINS` (step 10). |
| Error "allUsers is not allowed" during deploy | Your organisation blocks public Cloud Run services. An org admin must allow it for this project (the chat widget has to be reachable by browsers; login is enforced by the app). |
| Chat history missing after refresh | Check the logs for `Firestore error`. The database must be `(default)` in Native mode (step 3), and the runtime service account needs **Cloud Datastore User**. |
