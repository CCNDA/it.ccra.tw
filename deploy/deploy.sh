#!/bin/sh
# GitHub 發布：main 分支有新提交就部署（由 it-ccra-deploy.timer 每 2 分鐘觸發）。
# 採「主機自己拉」而不是 GitHub Actions 推：主機在內網、沒有對外入口；
# 而公開程式庫不宜掛 self-hosted runner（別人的 PR 可能在主機上跑程式）。
set -eu
REPO=/opt/it.ccra.tw
cd "$REPO"
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && [ "${1:-}" != "--force" ] && exit 0
git reset -q --hard origin/main
rsync -a --delete site/ /var/www/it.ccra.tw/
install -m 644 lib/access.php /var/www/it-lib/access.php
if ! cmp -s deploy/nginx-it.ccra.tw.conf /etc/nginx/sites-available/it.ccra.tw; then
    cp deploy/nginx-it.ccra.tw.conf /etc/nginx/sites-available/it.ccra.tw
    nginx -t && systemctl reload nginx
fi
logger -t it-ccra-deploy "deployed $(git rev-parse --short HEAD)"
echo "deployed $(git rev-parse --short HEAD)"
