#!/usr/bin/env bash
set -euo pipefail

# Fix tunnel origin: must be http:// not https:// for local PHP built-in server.
DEV_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1090
[[ -f "$DEV_ROOT/.env" ]] && source "$DEV_ROOT/.env"

ACCOUNT_ID="${CLOUDFLARE_ACCOUNT_ID:-7aee82807c2c5b3c75878a3aa1c21872}"
TUNNEL_ID="${CLOUDFLARE_TUNNEL_ID:-138a8334-2eb0-46f9-9757-71a0a821dc0d}"
HOSTNAME="${CLOUDFLARE_TUNNEL_HOSTNAME:-dev.roammax.ca}"
ORIGIN="${TUNNEL_ORIGIN:-http://127.0.0.1:8080}"
API_TOKEN="${CLOUDFLARE_API_TOKEN:-}"

if [[ -z "$API_TOKEN" ]]; then
  cat <<EOF
Cloudflare tunnel reaches the internet but returns 502 because the origin is set to HTTPS.

Fix in Cloudflare Zero Trust (fastest):
  1. https://one.dash.cloudflare.com/ → Networks → Connectors → Tunnels
  2. Open your tunnel → Public Hostname → edit dev.roammax.ca
  3. Change service URL from https://localhost:8080 to http://localhost:8080
  4. Save (cloudflared picks up the change within ~30s)

Or add CLOUDFLARE_API_TOKEN to dev/.env and re-run:
  dev/bin/cloudflare-fix-origin.sh
EOF
  exit 1
fi

echo "Updating tunnel origin to ${ORIGIN} for ${HOSTNAME} ..."

RESPONSE="$(curl -sS -X PUT \
  "https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/configurations" \
  -H "Authorization: Bearer ${API_TOKEN}" \
  -H "Content-Type: application/json" \
  --data "{\"config\":{\"ingress\":[{\"hostname\":\"${HOSTNAME}\",\"service\":\"${ORIGIN}\"},{\"service\":\"http_status:404\"}]}}")"

if echo "$RESPONSE" | python3 -c "import json,sys; r=json.load(sys.stdin); sys.exit(0 if r.get('success') else 1)"; then
  echo "Origin fixed. Test: curl -I https://${HOSTNAME}/"
else
  echo "API update failed:"
  echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
  exit 1
fi
