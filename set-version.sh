#!/usr/bin/env bash
# نسخهٔ افزونه = نسخهٔ زندهٔ هما در لحظهٔ به‌روزرسانی. استفاده: ./set-version.sh  (یا ./set-version.sh 3.13.10)
set -euo pipefail
cd "$(dirname "$0")"
V=${1:-$(curl -fsS -m 15 https://homacrm.com/api/version | python3 -c 'import json,sys;print(json.load(sys.stdin)["version"])')}
[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "نسخهٔ نامعتبر: $V"; exit 1; }
sed -i.bak -E "s/^( \* Version: +).*/\1$V/" homacrm/homacrm.php
sed -i.bak -E "s/define\( 'HOMACRM_VERSION', '[^']*' \)/define( 'HOMACRM_VERSION', '$V' )/" homacrm/homacrm.php
sed -i.bak -E "s/^Stable tag: .*/Stable tag: $V/" homacrm/readme.txt
rm -f homacrm/homacrm.php.bak homacrm/readme.txt.bak
echo "نسخهٔ افزونه: $V"
