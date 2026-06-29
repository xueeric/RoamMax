#!/bin/bash
set -euo pipefail

# Mac: put vpssj.pem in ~/.ssh/ (or set KEY below)
KEY="${DEPLOY_KEY:-$HOME/.ssh/vpssj.pem}"
chmod 600 "$KEY"

APP="/opt/stack/websites/starlink"
PUB="$APP/public"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="${DEPLOY_SRC:-$SCRIPT_DIR/website/}"

# PHP-FPM must own data/ (SQLite + WAL). Adjust www-data if your pool uses another user.
FIX="mkdir -p $APP/data && \
sudo chown -R www-data:www-data $APP/data && sudo chmod 775 $APP/data && \
chmod 755 $PUB && find $PUB -type d -exec chmod 755 {} \; && find $PUB -type f -exec chmod 644 {} \; && \
cd $APP && sudo -u www-data php bin/migrate.php"

RSYNC_EXCLUDES=(
  --exclude-from="$SRC.deployignore"
  --exclude='@eaDir'
  --exclude='.DS_Store'
  --exclude='.cursor'
  --exclude='data/'
)

echo "Deploying $SRC -> eric@10.10.0.100:$APP"

rsync -av --delete "${RSYNC_EXCLUDES[@]}" \
  -e "ssh -i $KEY -o StrictHostKeyChecking=no -o BatchMode=yes -p 53816" \
  "$SRC" "eric@10.10.0.100:$APP/"

ssh -i "$KEY" -o StrictHostKeyChecking=no -o BatchMode=yes -p 53816 \
  "eric@10.10.0.100" "$FIX"

echo "Done."
