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
| Secret with the Anthropic API key | `anthropic-api-key` |

## One-time setup

### 1. Enable the APIs

**APIs & Services → Library**, then enable each of: **Cloud Run Admin API**, **Cloud Build API**,
**Artifact Registry API**, **Cloud Firestore API**, **Secret Manager API**.

### 2. Artifact Registry repository (where images are stored)

**Artifact Registry → Repositories → Create repository**
- Name: `bitenxt` · Format: **Docker** · Mode: Standard · Location type: **Region** → `asia-south1` → **Create**.

### 3. Firestore (chat history and rate limits)

**Firestore → Create database**
- Database ID: `(default)` · Mode: **Native** · Location type: **Region** → `asia-south1` → **Create**.

Then, in that database:
- **Time-to-live (TTL) → Create policy**: collection group `chat_sessions`, timestamp field `expireAt`.
  Repeat for collection group `chat_ratelimits`, field `expireAt`. This deletes old chats (after
  `HISTORY_RETENTION_DAYS`) and old rate-limit counters automatically.
- **Indexes → Single field → Add exemption**: collection `chat_sessions`, field `data`, untick every index
  type → **Save**. The full conversation is never searched, so indexing it would only cost money.

### 4. Anthropic API key in Secret Manager

**Security → Secret Manager → Create secret**
- Name: `anthropic-api-key` · Secret value: paste the key (`sk-ant-…`) → **Create secret**.

### 5. Service account the chatbot runs as

**IAM & Admin → Service Accounts → Create service account**
- Name: `bitenxt-support-agent` → **Create and continue**.
- Role: **Cloud Datastore User** (lets it read and write Firestore) → **Done**.

Let it read the API key: **Secret Manager → `anthropic-api-key` → Permissions → Grant access**
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
  (`_REGION`, `_SERVICE`, `_AR_REPO`, `_RUNTIME_SA`, `_API_KEY_SECRET`).
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
| `HANDOFF_WEBHOOK_URL` | Slack/Teams incoming webhook for "talk to a person" | no |
| `CLAUDE_EFFORT` | `low` | no (default) |
| `HISTORY_RETENTION_DAYS` | `90` | no (default) |
| `CONVERSATION_IDLE_MINUTES` | `30` | no (default) |

The `ANTHROPIC_API_KEY` secret is already attached by the pipeline. You'll see it under **Secrets exposed as
environment variables**. → **Deploy**.

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
| Service fails to start: secret not found or permission denied | Step 4/5: the secret name must be `anthropic-api-key`, and the runtime service account needs **Secret Manager Secret Accessor** on it. |
| `/health` works but every chat says "Please log in" | The widget isn't sending the token (see INTEGRATION.md), or the token is from a different Magento than `MAGENTO_GRAPHQL_URL`. |
| Chat says "We can't verify your account right now" | Cloud Run can't reach Magento. Check `MAGENTO_GRAPHQL_URL`, and whether the VM's firewall blocks Google Cloud IPs. If it allows only listed IPs, you need a fixed outgoing IP (Cloud NAT). |
| Browser console shows a CORS error | Add the Pro site's exact origin (`https://…`, no trailing slash) to `ALLOWED_ORIGINS` (step 10). |
| Error "allUsers is not allowed" during deploy | Your organisation blocks public Cloud Run services. An org admin must allow it for this project (the chat widget has to be reachable by browsers; login is enforced by the app). |
| Chat history missing after refresh | Check the logs for `Firestore error`. The database must be `(default)` in Native mode (step 3), and the runtime service account needs **Cloud Datastore User**. |
