#!/usr/bin/env bash
# انتشارِ نسخهٔ تازهٔ افزونهٔ هما در GitHub Releases. استفاده: GITHUB_TOKEN=… ./release.sh
set -euo pipefail
cd "$(dirname "$0")"
./set-version.sh "${HOMA_VERSION:-}"
V=$(grep -m1 "define( 'HOMACRM_VERSION'" homacrm/homacrm.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
H=$(grep -m1 "^ \* Version:" homacrm/homacrm.php | awk '{print $3}')
R=$(grep -m1 "^Stable tag:" homacrm/readme.txt | awk '{print $3}')
[ "$V" = "$H" ] && [ "$V" = "$R" ] || { echo "نسخه‌ها یکی نیستند: $V / $H / $R"; exit 1; }
NOTES=$(awk "/^## $V/{f=1;next}/^## /{f=0}f" CHANGELOG.md)
rm -f homacrm.zip && zip -qr homacrm.zip homacrm -x "*.DS_Store"
git tag -a "v$V" -m "HomaCRM $V" && git push origin "v$V"
API=https://api.github.com/repos/Homa-OS/Plugins-HomaCRM
ID=$(curl -fsS -H "Authorization: token $GITHUB_TOKEN" -d "$(python3 -c 'import json,sys;print(json.dumps({"tag_name":"v"+sys.argv[1],"name":"HomaCRM "+sys.argv[1],"body":sys.argv[2]}))' "$V" "$NOTES")" $API/releases | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
curl -fsS -H "Authorization: token $GITHUB_TOKEN" -H "Content-Type: application/zip" --data-binary @homacrm.zip "https://uploads.github.com/repos/Homa-OS/Plugins-HomaCRM/releases/$ID/assets?name=homacrm.zip" >/dev/null
echo "منتشر شد: v$V"
