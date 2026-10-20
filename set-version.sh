#!/usr/bin/env bash
# نسخهٔ افزونه = نسخهٔ زندهٔ هما + شمارهٔ انتشار (۱۴۰۵/۰۷/۲۸): 3.13.15.1، 3.13.15.2 …؛ با نسخهٔ تازهٔ هما از .1 شروع.
# هر انتشارِ افزونه نسخهٔ تازه می‌گیرد تا به‌روزرسانِ وردپرس در همهٔ سایت‌ها اعلان بدهد.
# استفاده:  ./set-version.sh            (فقط نسخهٔ فعلی را چاپ می‌کند)
#           ./set-version.sh --bump     (نسخهٔ بعدی را می‌گذارد)
#           ./set-version.sh 3.13.15.4  (نسخهٔ دستی)
set -euo pipefail
cd "$(dirname "$0")"
CUR=$(grep -m1 "define( 'HOMACRM_VERSION'" homacrm/homacrm.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
case "${1:-}" in
  "") echo "$CUR"; exit 0 ;;
  --bump)
    H=$(curl -fsS -m 15 https://homacrm.com/api/version | python3 -c 'import json,sys;print(json.load(sys.stdin)["version"])')
    [[ "$H" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "نسخهٔ هما نامعتبر: $H"; exit 1; }
    if [[ "$CUR" == "$H".* ]]; then V="$H.$(( ${CUR##*.} + 1 ))"; else V="$H.1"; fi ;;
  *) V="$1" ;;
esac
[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || { echo "نسخهٔ نامعتبر: $V"; exit 1; }
sed -i.bak -E "s/^( \* Version: +).*/\1$V/" homacrm/homacrm.php
sed -i.bak -E "s/define\( 'HOMACRM_VERSION', '[^']*' \)/define( 'HOMACRM_VERSION', '$V' )/" homacrm/homacrm.php
sed -i.bak -E "s/^Stable tag: .*/Stable tag: $V/" homacrm/readme.txt
sed -i.bak -E "s/define\( 'HOMACRM_RELEASED', '[^']*' \)/define( 'HOMACRM_RELEASED', '$(date +%F)' )/" homacrm/homacrm.php
rm -f homacrm/homacrm.php.bak homacrm/readme.txt.bak
echo "$V"
