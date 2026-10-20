#!/usr/bin/env bash
# انتشارِ نسخهٔ تازهٔ افزونهٔ هما در GitHub Releases. استفاده: GITHUB_TOKEN=… ./release.sh
# نسخه پیش از این با ./set-version.sh --bump گذاشته و با CHANGELOG کامیت شده است؛ این اسکریپت نسخه را عوض نمی‌کند.
set -euo pipefail
cd "$(dirname "$0")"
[ -z "$(git status --porcelain homacrm CHANGELOG.md)" ] || { echo "افزونه یا CHANGELOG کامیت‌نشده دارد؛ اول کامیت کنید."; exit 1; }
V=$(grep -m1 "define( 'HOMACRM_VERSION'" homacrm/homacrm.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
H=$(grep -m1 "^ \* Version:" homacrm/homacrm.php | awk '{print $3}')
R=$(grep -m1 "^Stable tag:" homacrm/readme.txt | awk '{print $3}')
[ "$V" = "$H" ] && [ "$V" = "$R" ] || { echo "نسخه‌ها یکی نیستند: $V / $H / $R"; exit 1; }
NOTES=$(awk -v v="$V" 'index($0,"## " v " ")==1{f=1;next}/^## /{f=0}f' CHANGELOG.md)
[ -n "$NOTES" ] || { echo "در CHANGELOG بخشی برای $V نیست."; exit 1; }
# نامِ دانلود HomaCRM-<نسخه>.zip؛ homacrm.zip هم برای به‌روزرسانِ نصب‌های قدیمی می‌ماند.
Z="HomaCRM-$V.zip"
rm -f homacrm.zip HomaCRM-*.zip && zip -qr "$Z" homacrm -x "*.DS_Store" && cp "$Z" homacrm.zip
git tag -a "v$V" -m "HomaCRM $V" && git push origin "v$V"
API=https://api.github.com/repos/Homa-OS/Plugins-HomaCRM
ID=$(curl -fsS -H "Authorization: token $GITHUB_TOKEN" -d "$(python3 -c 'import json,sys;print(json.dumps({"tag_name":"v"+sys.argv[1],"name":"HomaCRM "+sys.argv[1],"body":sys.argv[2]}))' "$V" "$NOTES")" $API/releases | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
for f in "$Z" homacrm.zip; do curl -fsS -H "Authorization: token $GITHUB_TOKEN" -H "Content-Type: application/zip" --data-binary @"$f" "https://uploads.github.com/repos/Homa-OS/Plugins-HomaCRM/releases/$ID/assets?name=$f" >/dev/null; done
echo "منتشر شد: v$V"
