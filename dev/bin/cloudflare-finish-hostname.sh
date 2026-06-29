#!/usr/bin/env bash
set -euo pipefail

DEV_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$DEV_ROOT/.env"

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

ACCOUNT_ID="${CLOUDFLARE_ACCOUNT_ID:-7aee82807c2c5b3c75878a3aa1c21872}"
TUNNEL_ID="${CLOUDFLARE_TUNNEL_ID:-138a8334-2eb0-46f9-9757-71a0a821dc0d}"
HOSTNAME="${CLOUDFLARE_TUNNEL_HOSTNAME:-dev.roammax.ca}"
ZONE_NAME="${CLOUDFLARE_ZONE_NAME:-roammax.ca}"
ORIGIN="${TUNNEL_ORIGIN:-http://127.0.0.1:8080}"
API_TOKEN="${CLOUDFLARE_API_TOKEN:-}"

if [[ -z "$API_TOKEN" ]]; then
  cat <<EOF
Missing CLOUDFLARE_API_TOKEN in dev/.env

Create one at: https://dash.cloudflare.com/profile/api-tokens
  Template: "Edit Cloudflare Tunnel" + DNS Edit for roammax.ca

Then add to dev/.env:
  CLOUDFLARE_API_TOKEN=your_token

Re-run: dev/bin/cloudflare-finish-hostname.sh

--- Or configure manually in the dashboard ---

1. Open https://one.dash.cloudflare.com/
2. Networks → Connectors → Cloudflare Tunnels
3. Open tunnel $TUNNEL_ID
4. Public Hostname → Add a public hostname
     Subdomain: dev
     Domain: roammax.ca
     Service type: HTTP
     URL: localhost:8080
5. Save

Then set in website/.env:
  APP_URL=https://$HOSTNAME

Start stack:
  dev/bin/dev-with-tunnel.sh

Square webhook:
  https://$HOSTNAME/webhooks/square
EOF
  exit 1
fi

api() {
  local method="$1"
  local path="$2"
  local data="${3:-}"
  if [[ -n "$data" ]]; then
    curl -sS -X "$method" "https://api.cloudflare.com/client/v4${path}" \
      -H "Authorization: Bearer ${API_TOKEN}" \
      -H "Content-Type: application/json" \
      --data "$data"
  else
    curl -sS -X "$method" "https://api.cloudflare.com/client/v4${path}" \
      -H "Authorization: Bearer ${API_TOKEN}" \
      -H "Content-Type: application/json"
  fi
}

echo "Configuring tunnel ingress for https://${HOSTNAME} -> ${ORIGIN} ..."

CONFIG_RESPONSE="$(api PUT "/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/configurations" "$(cat <<JSON
{
  "config": {
    "ingress": [
      {
        "hostname": "${HOSTNAME}",
        "service": "${ORIGIN}"
      },
      {
        "service": "http_status:404"
      }
    ]
  }
}
JSON
)")"

if ! echo "$CONFIG_RESPONSE" | python3 -c "import json,sys; r=json.load(sys.stdin); sys.exit(0 if r.get('success') else 1)"; then
  echo "Tunnel configuration failed:"
  echo "$CONFIG_RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$CONFIG_RESPONSE"
  exit 1
fi

echo "Tunnel ingress updated."

ZONE_RESPONSE="$(api GET "/zones?name=${ZONE_NAME}")"
ZONE_ID="$(echo "$ZONE_RESPONSE" | python3 -c "import json,sys; r=json.load(sys.stdin); print(r['result'][0]['id'] if r.get('success') and r.get('result') else '')")"

if [[ -z "$ZONE_ID" ]]; then
  echo "Could not find Cloudflare zone for ${ZONE_NAME}. Add DNS manually if needed."
  exit 0
fi

CNAME_TARGET="${TUNNEL_ID}.cfargotunnel.com"
SUBDOMAIN="${HOSTNAME%.${ZONE_NAME}}"

EXISTING="$(api GET "/zones/${ZONE_ID}/dns_records?type=CNAME&name=${HOSTNAME}")"
RECORD_ID="$(echo "$EXISTING" | python3 -c "import json,sys; r=json.load(sys.stdin); rows=r.get('result') or []; print(rows[0]['id'] if rows else '')")"

if [[ -n "$RECORD_ID" ]]; then
  DNS_RESPONSE="$(api PUT "/zones/${ZONE_ID}/dns_records/${RECORD_ID}" "$(cat <<JSON
{
  "type": "CNAME",
  "name": "${SUBDOMAIN}",
  "content": "${CNAME_TARGET}",
  "proxied": true
}
JSON
)")"
else
  DNS_RESPONSE="$(api POST "/zones/${ZONE_ID}/dns_records" "$(cat <<JSON
{
  "type": "CNAME",
  "name": "${SUBDOMAIN}",
  "content": "${CNAME_TARGET}",
  "proxied": true
}
JSON
)")"
fi

if echo "$DNS_RESPONSE" | python3 -c "import json,sys; r=json.load(sys.stdin); sys.exit(0 if r.get('success') else 1)"; then
  echo "DNS CNAME ${HOSTNAME} -> ${CNAME_TARGET}"
else
  echo "DNS update failed (tunnel may still work if hostname was added in dashboard):"
  echo "$DNS_RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$DNS_RESPONSE"
fi

echo ""
echo "Done. Update website/.env if needed:"
echo "  APP_URL=https://${HOSTNAME}"
echo ""
echo "Run: dev/bin/dev-with-tunnel.sh"
echo "Square webhook: https://${HOSTNAME}/webhooks/square"
