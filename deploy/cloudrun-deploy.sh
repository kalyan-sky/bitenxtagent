#!/usr/bin/env bash
# Deploys the support agent to Cloud Run. Safe to re-run: each step skips what
# already exists, and the last step rolls out a new revision.
#
#   PROJECT_ID=my-project REGION=asia-south1 ./deploy/cloudrun-deploy.sh
#
# Needs: gcloud (logged in as someone who can enable APIs, create service
# accounts and deploy), and deploy/env.yaml (copy env.example.yaml).
set -euo pipefail

: "${PROJECT_ID:?Set PROJECT_ID}"
: "${REGION:?Set REGION, e.g. asia-south1 or us-central1 (ideally near your Magento server)}"
SERVICE="${SERVICE:-bitenxt-support-agent}"
SECRET="${SECRET:-anthropic-api-key}"
SA_NAME="${SA_NAME:-bitenxt-support-agent}"
SA="${SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${ENV_FILE:-$ROOT/deploy/env.yaml}"

[[ -f "$ENV_FILE" ]] || { echo "Missing $ENV_FILE - copy deploy/env.example.yaml and edit it."; exit 1; }

gcloud config set project "$PROJECT_ID" >/dev/null

echo "==> Enabling APIs"
gcloud services enable run.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com \
  firestore.googleapis.com secretmanager.googleapis.com

echo "==> Firestore (sessions and rate limits)"
if ! gcloud firestore databases describe --database='(default)' >/dev/null 2>&1; then
  gcloud firestore databases create --database='(default)' --location="$REGION" --type=firestore-native
fi
# Chat history and counters are deleted automatically via their expireAt field
# (history: HISTORY_RETENTION_DAYS after the last message).
for group in chat_sessions chat_ratelimits; do
  gcloud firestore fields ttls update expireAt --collection-group="$group" --enable-ttl --async --quiet >/dev/null || true
done
# The full conversation JSON is never searched, so don't index it (saves cost).
gcloud firestore indexes fields update data --collection-group=chat_sessions --disable-indexes --async --quiet >/dev/null || true

echo "==> Service account (least privilege: Firestore + this one secret)"
if ! gcloud iam service-accounts describe "$SA" >/dev/null 2>&1; then
  gcloud iam service-accounts create "$SA_NAME" --display-name="BiteNXT support agent (Cloud Run)"
fi
gcloud projects add-iam-policy-binding "$PROJECT_ID" --member="serviceAccount:$SA" \
  --role=roles/datastore.user --condition=None >/dev/null

echo "==> Anthropic API key in Secret Manager"
if ! gcloud secrets describe "$SECRET" >/dev/null 2>&1; then
  read -r -s -p "Paste the Anthropic API key (input hidden): " KEY; echo
  printf '%s' "$KEY" | gcloud secrets create "$SECRET" --replication-policy=automatic --data-file=-
  unset KEY
fi
gcloud secrets add-iam-policy-binding "$SECRET" --member="serviceAccount:$SA" \
  --role=roles/secretmanager.secretAccessor >/dev/null

echo "==> Building and deploying (Cloud Build uses the Dockerfile)"
gcloud run deploy "$SERVICE" \
  --source "$ROOT" \
  --region "$REGION" \
  --service-account "$SA" \
  --env-vars-file "$ENV_FILE" \
  --set-secrets "ANTHROPIC_API_KEY=${SECRET}:latest" \
  --allow-unauthenticated \
  --port 8080 \
  --cpu 1 --memory 512Mi \
  --concurrency 20 --timeout 120 \
  --min-instances 0 --max-instances 10

URL="$(gcloud run services describe "$SERVICE" --region "$REGION" --format='value(status.url)')"
cat <<MSG

Deployed: $URL
  Health check : $URL/health
  Test page    : $URL/demo.html
  Chat API     : POST $URL/chat, GET $URL/chat/history

Add the widget to the Pro frontend (any page, before </body>):

  <script src="$URL/widget.js" data-auto-init data-token-key="customerToken"></script>

(data-token-key = the localStorage key where Pro keeps the Magento customer token.)
Remember to list the Pro site's origin in ALLOWED_ORIGINS in deploy/env.yaml.
MSG
