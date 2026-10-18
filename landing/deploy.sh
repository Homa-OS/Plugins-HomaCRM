#!/usr/bin/env bash
# انتشارِ لندینگِ plugin.homacrm.com: نسخهٔ افزونه = نسخهٔ زندهٔ هما، zip نسخه‌دار، سپس آپلود.
# استفاده: landing/deploy.sh [نسخه]   (بی آرگومان: نسخهٔ زندهٔ https://homacrm.com/api/version)
set -euo pipefail
cd "$(dirname "$0")/.."
./set-version.sh "${1:-}" >/dev/null
V=$(grep -m1 "define( 'HOMACRM_VERSION'" homacrm/homacrm.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
rm -f "$T/x.zip"; zip -qr "$T/homacrm-$V.zip" homacrm -x "*.DS_Store"
sed "s/{{VERSION}}/$V/g" landing/index.html > "$T/index.html"
sed "s/{{DATE}}/$(date +%F)/" landing/sitemap.xml > "$T/sitemap.xml"
cp landing/robots.txt landing/llms.txt landing/llms-full.txt landing/og.png landing/apple-touch-icon.png landing/mark.svg "$T/"
SSH=(-i "$HOME/.ssh/homa-sms-node" -p 22008)
scp -q -P 22008 -i "$HOME/.ssh/homa-sms-node" "$T"/* homa@134.255.200.130:/tmp/
ssh "${SSH[@]}" homa@134.255.200.130 "B=/opt/sites/plugin-homacrm-com/html; cd /tmp && sudo cp index.html sitemap.xml robots.txt llms.txt llms-full.txt og.png apple-touch-icon.png mark.svg homacrm-$V.zip \$B/ && sudo chmod 644 \$B/* && sudo find \$B -name 'homacrm*.zip' ! -name 'homacrm-$V.zip' -delete; rm -f /tmp/homacrm-$V.zip"
echo "لندینگ زنده شد: نسخه $V"
