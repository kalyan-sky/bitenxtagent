#!/usr/bin/env bash
# Checks the chatbot end to end on UAT. Run it in Cloud Shell:
#
#   bash deploy/uat-check.sh <chat-service-url> <test-customer-email>
#
# It asks for the password (never stored), signs in to Magento, checks the
# queries the bot relies on, then asks the chat every question in
# eval/questions.txt and prints each answer with how long it took.
set -euo pipefail

CHAT_URL="${1:?usage: uat-check.sh <chat-service-url> <email>}"
EMAIL="${2:?usage: uat-check.sh <chat-service-url> <email>}"
MAGENTO="${MAGENTO_GRAPHQL_URL:-https://uat-magento.bitenxt.com/graphql}"
QUESTIONS="$(dirname "$0")/../eval/questions.txt"
CHAT_URL="${CHAT_URL%/}"

read -r -s -p "Password for $EMAIL: " PASSWORD; echo

gql() { # gql <token> <json body>
  curl -sS -m 30 "$MAGENTO" -H 'Content-Type: application/json' ${1:+-H "Authorization: Bearer $1"} -d "$2"
}

LOGIN=$(jq -n --arg e "$EMAIL" --arg p "$PASSWORD" \
  '{query: "mutation ($e: String!, $p: String!) { generateCustomerToken(email: $e, password: $p) { token } }", variables: {e: $e, p: $p}}')
TOKEN=$(gql "" "$LOGIN" | jq -r '.data.generateCustomerToken.token // empty')
unset PASSWORD LOGIN
[ -n "$TOKEN" ] || { echo "Login failed: check the email and password."; exit 1; }
echo "Signed in."

echo; echo "== Magento queries the bot uses (errors here mean a field needs adjusting) =="
for q in \
  '{ customer { orders(currentPage: 1, pageSize: 100) { total_count items { status } } } }' \
  '{ availableCoupons { name code description discount_type discount_amount from_date to_date } }' \
  '{ customerCart { id total_quantity items { quantity product { name } } applied_coupons { code } } }' \
  '{ categoryList { name children { name products(pageSize: 3) { total_count items { name } } } } }'; do
  echo "--- $q"
  gql "$TOKEN" "$(jq -n --arg q "$q" '{query: $q}')" | jq -c '{errors: [.errors[]?.message], data: (.data | tostring | .[0:400])}'
done

echo; echo "== Chat answers =="
while IFS= read -r question; do
  [[ -z "$question" || "$question" == \#* ]] && continue
  start=$(date +%s%N)
  answer=$(curl -sS -m 60 "$CHAT_URL/chat" -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
    -d "$(jq -n --arg m "$question" '{message: $m}')" | jq -r '.reply // .error')
  ms=$(( ($(date +%s%N) - start) / 1000000 ))
  printf '\nQ: %s   [%d ms]\nA: %s\n' "$question" "$ms" "$answer"
  sleep 6 # stay under the default 10-per-minute rate limit
done < "$QUESTIONS"

echo; echo "Done. In Cloud Run logs, search \"timing\" to see where the time went, and \"knowledge_gap\" for unanswered how-to questions."
