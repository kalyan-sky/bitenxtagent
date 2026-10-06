# Hand-over emails to the support team

When a customer asks for a person ("talk to support", or the bot decides a request needs the team: cancel,
change, refund, remake, complaint), the chat emails the support inbox. The email has the customer's name,
account email and ID, the reason, the order (only if it is confirmed to be theirs), a summary and the last few
messages of the chat. **Reply-To is the customer**, so the team can just press Reply.

If the email can't be sent, the bot does **not** promise a follow-up: it gives the customer the support phone
number and email instead, and logs `handoff_email_failed` / `handoff_not_delivered` (ERROR) in Cloud Run.

## 1. Pick the mailbox that sends

Use an account on the mail service BiteNXT already uses. Cloud Run can't send on port 25, so use 587 or 465.

| Mail service | `SMTP_HOST` | `SMTP_PORT` | `SMTP_ENCRYPTION` | Password |
|---|---|---|---|---|
| Google Workspace / Gmail | `smtp.gmail.com` | `587` | `tls` | An **app password** (Google account → Security → 2-Step Verification → App passwords) |
| Zoho Mail | `smtp.zoho.in` (or `smtp.zoho.com`) | `587` | `tls` | Account or app-specific password |
| Microsoft 365 / Outlook | `smtp.office365.com` | `587` | `tls` | Account password (SMTP AUTH must be enabled for the mailbox) |
| Brevo (free 300/day) | `smtp-relay.brevo.com` | `587` | `tls` | SMTP key from Brevo → SMTP & API |
| Hostinger / cPanel mail | your mail host, e.g. `mail.bitenxt.com` | `465` | `ssl` | Mailbox password |

A dedicated sender such as `chatbot@bitenxt.com` or `noreply@bitenxt.com` is best. `SMTP_FROM` must be an
address that account is allowed to send as.

## 2. Store the password as a secret

Console → **Secret Manager** → **Create secret**: name `smtp-password`, value = the password or app password.
Then on the secret's **Permissions** tab, grant **Secret Manager Secret Accessor** to the service account
Cloud Run uses (`bitenxt-support-agent@…`), as for the AI keys.

## 3. Set the Cloud Run variables

Cloud Run → **bitenxtagent** → **Edit & deploy new revision** → **Variables & secrets**:

| Name | Value |
|---|---|
| `SMTP_HOST` | e.g. `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_ENCRYPTION` | `tls` (`ssl` for port 465) |
| `SMTP_USERNAME` | the sending mailbox, e.g. `chatbot@bitenxt.com` |
| `SMTP_FROM` | same as `SMTP_USERNAME` (optional) |
| `HANDOFF_EMAIL_TO` | where requests go, e.g. `contact@bitenxt.com` (comma-separate several). Defaults to `SUPPORT_EMAIL` |
| `SMTP_PASSWORD` | **Reference a secret** → `smtp-password`, version `latest` |

**Deploy**. Then ask the chat "talk to support": the inbox should get an email titled
`[BiteNXT chat] Customer requested: <customer email>`.

## Troubleshooting

Cloud Run → **Logs**, search `handoff_email_failed`. The `detail` says what went wrong:

| Detail | Fix |
|---|---|
| `Could not authenticate` | Wrong username/password; for Gmail use an app password, not the normal password |
| `Could not connect to SMTP host` | Wrong host or port; use 587 (`tls`) or 465 (`ssl`), never 25 |
| `Sender address rejected` | `SMTP_FROM` isn't an address this account may send as |

Optional: also set `HANDOFF_WEBHOOK_URL` to post hand-overs to Slack, Teams or Google Chat.
