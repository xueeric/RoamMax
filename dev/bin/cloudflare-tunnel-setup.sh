#!/usr/bin/env bash
set -euo pipefail

DEV_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CF_DIR="$DEV_ROOT/cloudflare"
CONFIG="$CF_DIR/config.yml"
TUNNEL_NAME="${TUNNEL_NAME:-roammax-dev}"
HOSTNAME="${TUNNEL_HOSTNAME:-dev.roammax.ca}"

if ! command -v cloudflared >/dev/null 2>&1; then
  echo "cloudflared is not installed. Run: brew install cloudflared"
  exit 1
fi

echo "== RoamMax Cloudflare Tunnel setup =="
echo ""
echo "Prerequisites:"
echo "  - roammax.ca (or your domain) added to Cloudflare"
echo "  - You are logged into the correct Cloudflare account"
echo ""

if [[ ! -f "$HOME/.cloudflared/cert.pem" ]]; then
  echo "Step 1: Log in to Cloudflare (opens browser)..."
  cloudflared tunnel login
  echo ""
fi

echo "Step 2: Create tunnel '$TUNNEL_NAME' (skip if it already exists)..."
if cloudflared tunnel list 2>/dev/null | grep -q "$TUNNEL_NAME"; then
  echo "  Tunnel '$TUNNEL_NAME' already exists."
else
  cloudflared tunnel create "$TUNNEL_NAME"
fi

TUNNEL_ID="$(cloudflared tunnel list 2>/dev/null | awk -v name="$TUNNEL_NAME" '$0 ~ name { print $1; exit }')"
if [[ -z "$TUNNEL_ID" ]]; then
  echo "Could not find tunnel ID for '$TUNNEL_NAME'. Check: cloudflared tunnel list"
  exit 1
fi

CREDS="$HOME/.cloudflared/${TUNNEL_ID}.json"
if [[ ! -f "$CREDS" ]]; then
  echo "Credentials file not found at $CREDS"
  exit 1
fi

echo ""
echo "Step 3: Route DNS $HOSTNAME -> tunnel..."
cloudflared tunnel route dns "$TUNNEL_NAME" "$HOSTNAME" || true

mkdir -p "$CF_DIR"
cat > "$CONFIG" <<EOF
tunnel: $TUNNEL_NAME
credentials-file: $CREDS

ingress:
  - hostname: $HOSTNAME
    service: http://127.0.0.1:8080
  - service: http_status:404
EOF

echo ""
echo "Wrote $CONFIG"
echo ""
echo "Next steps:"
echo "  1. Update website/.env: APP_URL=https://$HOSTNAME"
echo "  2. Start dev server + tunnel: dev/bin/dev-with-tunnel.sh"
echo "  3. Square webhook URL: https://$HOSTNAME/webhooks/square"
echo ""
