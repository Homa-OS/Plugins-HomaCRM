#!/usr/bin/env bash
# انتشارِ لندینگِ plugin.homacrm.com با zipِ همان نسخه‌ای که در homacrm.php است (نسخه را عوض نمی‌کند؛
# نسخهٔ تازه فقط با ./set-version.sh --bump و ./release.sh). استفاده: landing/deploy.sh
set -euo pipefail
cd "$(dirname "$0")/.."
V=$(grep -m1 "define( 'HOMACRM_VERSION'" homacrm/homacrm.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
rm -f "$T/x.zip"; zip -qr "$T/homacrm-$V.zip" homacrm -x "*.DS_Store"
python3 - "$V" landing/index.html "$T/index.html" <<'PY'
import sys
v,src,dst=sys.argv[1:4]; s=open(src,encoding='utf8').read().replace('{{VERSION}}',v); open(dst,'w',encoding='utf8').write(s)
PY
sed "s/{{DATE}}/$(date +%F)/" landing/sitemap.xml > "$T/sitemap.xml"
cp landing/install.html landing/robots.txt landing/llms.txt landing/llms-full.txt landing/og.png landing/apple-touch-icon.png landing/mark.svg "$T/"
SSH=(-i "$HOME/.ssh/homa-sms-node" -p 22008)
scp -q -P 22008 -i "$HOME/.ssh/homa-sms-node" "$T"/* homa@134.255.200.130:/tmp/
GATE=${LEAD_GATE:-0}
ssh "${SSH[@]}" homa@134.255.200.130 "B=/opt/sites/plugin-homacrm-com; cd /tmp && sudo cp index.html install.html sitemap.xml robots.txt llms.txt llms-full.txt og.png apple-touch-icon.png mark.svg \$B/html/ && sudo cp homacrm-$V.zip \$B/files/ && sudo chmod 644 \$B/html/* \$B/files/*; sudo find \$B/files -name 'homacrm*.zip' ! -name 'homacrm-$V.zip' -delete; sudo find \$B/html -name 'homacrm*.zip' -delete; rm -f /tmp/homacrm-$V.zip; true"
echo "لندینگ زنده شد: نسخه $V"
