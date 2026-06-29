#!/usr/bin/env bash
set -euo pipefail

DEV_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WEBSITE_ROOT="$(cd "$DEV_ROOT/../website" && pwd)"
CONFIG="$DEV_ROOT/cloudflare/config.yml"
PORT="${PORT:-8080}"

load_env() {
  local file="$1"
  if [[ -f "$file" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$file"
    set +a
  fi
}

load_env "$WEBSITE_ROOT/.env"
load_env "$DEV_ROOT/.env"

if ! command -v cloudflared >/dev/null 2>&1; then
  echo "cloudflared is not installed. Run: brew install cloudflared"
  exit 1
fi

HOSTNAME="${CLOUDFLARE_TUNNEL_HOSTNAME:-dev.roammax.ca}"
TUNNEL_TOKEN="${CLOUDFLARE_TUNNEL_TOKEN:-}"

if [[ -z "$TUNNEL_TOKEN" && ! -f "$CONFIG" ]]; then
  echo "Missing CLOUDFLARE_TUNNEL_TOKEN in dev/.env and no dev/cloudflare/config.yml"
  echo "Add CLOUDFLARE_TUNNEL_TOKEN from Cloudflare Zero Trust, or run dev/bin/cloudflare-tunnel-setup.sh"
  exit 1
fi

cleanup() {
  if [[ -n "${PHP_PID:-}" ]] && kill -0 "$PHP_PID" 2>/dev/null; then
    kill "$PHP_PID" 2>/dev/null || true
  fi
  if [[ -n "${TUNNEL_PID:-}" ]] && kill -0 "$TUNNEL_PID" 2>/dev/null; then
    kill "$TUNNEL_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

echo "Starting PHP on http://127.0.0.1:$PORT ..."
php -S "127.0.0.1:$PORT" -t "$WEBSITE_ROOT/public" "$WEBSITE_ROOT/public/index.php" &
PHP_PID=$!

sleep 1

if [[ -n "$TUNNEL_TOKEN" ]]; then
  echo "Starting Cloudflare Tunnel (token) ..."
  cloudflared tunnel run --token "$TUNNEL_TOKEN" &
else
  HOSTNAME="$(grep -E '^\s+- hostname:' "$CONFIG" | head -1 | awk '{print $3}')"
  HOSTNAME="${HOSTNAME:-dev.roammax.ca}"
  echo "Starting Cloudflare Tunnel (config: $CONFIG) ..."
  cloudflared tunnel --config "$CONFIG" run &
fi
TUNNEL_PID=$!

PUBLIC_URL="https://${HOSTNAME}"
echo ""
echo "Local:  http://127.0.0.1:$PORT"
echo "Public: $PUBLIC_URL"
echo "Square webhook: ${PUBLIC_URL}/webhooks/square"
echo ""
echo "Ensure website/.env has: APP_URL=${PUBLIC_URL}"
echo "Press Ctrl+C to stop."

wait "$TUNNEL_PID"
