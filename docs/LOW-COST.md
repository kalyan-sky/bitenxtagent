# Running the chat for under $10 a month

The code already does the most important parts:

- **Order lookups skip the AI.** "status of order 671", a bare order number, and "my orders" are answered
  from a template (`FAST_PATH=on`). They are instant and cost nothing.
- **Small AI calls.** Only the last 6 question/answer pairs go to the AI (`AI_HISTORY_TURNS`), and
  replies are capped at 400 tokens (`MAX_OUTPUT_TOKENS`).
- **Hard spending cap.** The whole service stops using the AI after 1,000,000 tokens a day
  (`TOKEN_LIMIT_GLOBAL_PER_DAY`). With Gemini Flash-Lite that is about $0.15 a day, or $5 a month at most.

The rest is settings in the Google Cloud console. Prices are approximate; check the current Gemini API and
Cloud Run pricing pages.

## 1. Use Gemini Flash-Lite (paid tier)

Cloud Run → **bitenxtagent** → **Edit & deploy new revision** → **Variables & secrets**:

| Variable | Value |
|---|---|
| `LLM_GEMINI_MODEL` | the current **Flash-Lite** model ID from Google AI Studio (Models list) |
| `LLM_PROVIDERS` | `gemini,openrouter` (or just `gemini` to switch the fallback off) |

Keep billing on for the Gemini API key's project. The paid tier does not use your prompts to improve
Google's products; the free tier may, which is a problem for clinic and patient data.

## 2. Set the limits

Same screen. These are the new defaults, so you only need them if you set other values before:

| Variable | Value |
|---|---|
| `TOKEN_LIMIT_GLOBAL_PER_DAY` | `1000000` |
| `TOKEN_LIMIT_GLOBAL_PER_HOUR` | `200000` |
| `TOKEN_LIMIT_CUSTOMER_PER_DAY` | `100000` |
| `MAX_OUTPUT_TOKENS` | `400` |

## 3. Keep Cloud Run in the free tier, with fast starts

Same **Edit & deploy new revision** screen:

1. **Container** tab → **Settings**: CPU **1**, memory **512 MiB**, and tick **Startup CPU boost**.
2. **Revision scaling**: minimum instances **0**, maximum **3**.
3. Billing: **Request-based** (CPU only allocated during requests).
4. **Deploy**.

## 4. Optional: keep it warm during clinic hours (free)

The first message after a quiet spell waits for the container to start. A free Cloud Scheduler job can keep
an instance warm while clinics are working:

1. Console → **Cloud Scheduler** → **Create job**.
2. Name `chat-warmup`, region `asia-south1`.
3. Frequency `*/10 9-20 * * 1-6` (every 10 minutes, 9am–8pm, Monday–Saturday), time zone **Asia/Kolkata**.
4. Target **HTTP**, URL `https://<your-service-url>/health`, method **GET**.
5. **Create**.

Each Google Cloud billing account gets 3 Scheduler jobs free, and `/health` does not call Magento or the AI.

## 5. Billing alert

Console → **Billing** → **Budgets & alerts** → **Create budget**:

- Scope: project `ai-email-sender-466810`.
- Amount: **$10** a month.
- Alerts at **50%, 90%, 100%** by email.

## 6. Fallback provider

If you keep `openrouter` in `LLM_PROVIDERS`, set a **credit limit of about $2** on the OpenRouter key
(openrouter.ai → Keys → edit key). It is only used when Gemini fails.

## Expected bill

| Usage | AI | Google Cloud | Total |
|---|---|---|---|
| Pilot (~1,000 messages a month) | ~$0–1 | ~$0 | **~$0–1** |
| ~10,000 messages a month | ~$2–5 | ~$0–1 | **~$2–6** |
| Attack or spike | stops at ~$5 | ~$0–1 | **under $10** |

## Checking it works

Cloud Run → **Logs**, filter by text:

- `fast_path`: order questions answered without the AI.
- `llm_call`: each AI call, with `input_tokens` and `output_tokens`.
- `token_budget_exceeded`: a limit was reached (chat pauses politely until the next hour or day).
