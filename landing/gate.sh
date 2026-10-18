#!/usr/bin/env bash
# دروازهٔ ایمیل: on ⇒ فایلِ zip فقط از لینکِ ایمیلی؛ off ⇒ دانلودِ مستقیم (و باید با LEAD_GATE=0 deploy شود).
set -euo pipefail
M=${1:?on|off}; S=(-i "$HOME/.ssh/homa-sms-node" -p 22008)
ssh "${S[@]}" homa@134.255.200.130 "B=/opt/sites/plugin-homacrm-com; sudo sed -i '/zip\$/d;/فایلِ zip دیگر/d' \$B/default.conf; if [ '$M' = on ]; then sudo python3 - <<'PY'
p='/opt/sites/plugin-homacrm-com/default.conf'; s=open(p).read(); i=s.rindex('}')
open(p,'w').write(s[:i]+'  # فایلِ zip دیگر مستقیم و عمومی نیست\n  location ~* \\\\.zip\$ { return 404; }\n'+s[i:])
PY
fi; sudo docker restart plugin-homacrm-com >/dev/null; sleep 2; curl -s -o /dev/null -w 'root %{http_code}\n' http://127.0.0.1:8120/"
