#!/usr/bin/env python3
"""سرویسِ جمع‌آوریِ ایمیلِ لندینگ: ایمیل می‌گیرد، لینکِ یکتای دانلود می‌سازد و هر قدم (باز کردن، کلیک، دانلود) را ثبت می‌کند.
ایمیل فرستادن کارِ این سرویس نیست؛ فرستنده (روی مکِ مرتضی با SMTPِ هما) صفِ pending را می‌خواند. بی هیچ وابستگیِ بیرونی."""
import http.server, json, os, re, sqlite3, secrets, time, io, zipfile, urllib.parse, threading, glob, html

DATA = os.environ.get('LEADS_DATA', '/app/data'); FILES = os.environ.get('LEADS_FILES', '/files')
os.makedirs(DATA, exist_ok=True); DB = os.path.join(DATA, 'leads.db')
BASE = 'https://plugin.homacrm.com'
DISPOSABLE = {'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com', 'throwawaymail.com'}
EMAIL_RE = re.compile(r'^[A-Za-z0-9._%+\-]{1,64}@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,24}$')
BOT_RE = re.compile(r'bot|crawl|spider|preview|scan|curl|python|wget|headless|facebookexternalhit|slack|proofpoint|mimecast|barracuda|safelinks', re.I)
lock = threading.Lock()

def db():
    c = sqlite3.connect(DB, timeout=10); c.row_factory = sqlite3.Row; return c
def init():
    with db() as c:
        c.executescript('''CREATE TABLE IF NOT EXISTS leads(id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, token TEXT UNIQUE NOT NULL, lead_key TEXT UNIQUE NOT NULL, created REAL NOT NULL, ip TEXT, ua TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, ref TEXT, lang TEXT, status TEXT NOT NULL DEFAULT 'pending', sent_at REAL, tries INTEGER NOT NULL DEFAULT 0, resends INTEGER NOT NULL DEFAULT 0, downloads INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY, lead_id INTEGER NOT NULL, kind TEXT NOT NULL, ts REAL NOT NULL, ip TEXT, ua TEXT, extra TEXT);
CREATE INDEX IF NOT EXISTS ev_lead ON events(lead_id,kind); CREATE INDEX IF NOT EXISTS lead_ip ON leads(ip,created);''')
def client_ip(h):
    x = (h.headers.get('X-Forwarded-For') or '').split(',')[0].strip()
    return x.rsplit(':', 1)[0] if x.count(':') == 1 else x
def ev(c, lead_id, kind, h, extra=''):
    c.execute('INSERT INTO events(lead_id,kind,ts,ip,ua,extra) VALUES(?,?,?,?,?,?)', (lead_id, kind, time.time(), client_ip(h), (h.headers.get('User-Agent') or '')[:300], extra[:200]))
def newest_zip():
    z = sorted(glob.glob(os.path.join(FILES, 'homacrm-*.zip')), key=os.path.getmtime)
    return z[-1] if z else None
def personal_zip(lead_key):
    src = newest_zip()
    if not src: return None, None
    buf = io.BytesIO()
    with zipfile.ZipFile(src) as zi, zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as zo:
        for it in zi.infolist(): zo.writestr(it, zi.read(it.filename))
        zo.writestr('homacrm/lead.txt', lead_key + '\n')
    return buf.getvalue(), os.path.basename(src)

PAGE = '''<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#00AAB0"><title>%s</title><meta name="description" content="%s">
<style>
:root{color-scheme:light dark;--ink:#14181f;--mut:#5b6472;--line:#dfe6ec;--teal:#00AAB0;--teal2:#008b90;--bg1:#eef9fa;--bg2:#fbfcfd;--card:#fff}
@media(prefers-color-scheme:dark){:root{--ink:#eef1f5;--mut:#a3adbb;--line:#2a323d;--bg1:#0f1a1c;--bg2:#0e1116;--card:#171b22;--teal2:#35c9cf}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;color:var(--ink);background:linear-gradient(180deg,var(--bg1),var(--bg2) 60%%);font:16px/1.9 system-ui,-apple-system,"Segoe UI",Tahoma,"Noto Sans Arabic","Noto Naskh Arabic",Arial,sans-serif}
.c{width:100%%;max-width:480px;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:28px 24px;text-align:center;box-shadow:0 12px 40px rgba(0,60,70,.10);position:relative;overflow:hidden}
.c:before{content:"";position:absolute;inset:0 0 auto 0;height:5px;background:linear-gradient(90deg,#BCD630,#F3C015,#E33426,#00AAB0)}
.m{display:block;margin:6px auto 10px}
h1{font-size:22px;line-height:1.5;margin:0 0 4px}
p{margin:6px 0;color:var(--mut);font-size:14.5px}
.v{display:inline-block;direction:ltr;background:var(--bg1);border:1px solid var(--line);border-radius:999px;padding:0 12px;font-size:12.5px;color:var(--teal2);font-weight:700}
.b{display:block;margin:22px auto 8px;background:var(--teal);color:#fff;text-decoration:none;border-radius:16px;padding:12px 18px;font-weight:800;box-shadow:0 8px 20px rgba(0,170,176,.30)}
.b:hover,.b:focus-visible{background:var(--teal2);outline:3px solid var(--ink);outline-offset:2px}
.b span{display:block;font-size:19px;line-height:1.6}
.b small{display:block;direction:ltr;font:600 12.5px Arial,Helvetica,sans-serif;letter-spacing:.7px;opacity:.92}
ol{text-align:right;color:var(--mut);padding-inline-start:22px;margin:16px 0 4px;font-size:14.5px}
li{margin:4px 0}
a{color:var(--teal2)}
.f{margin-top:16px;font-size:12.5px;color:var(--mut)}
</style></head><body><main class="c">%s</main></body></html>'''
MARK = '<svg class="m" width="52" height="52" viewBox="0 0 64 64" aria-hidden="true"><defs><clipPath id="k"><path d="M10 8h44a8 8 0 018 8v26a8 8 0 01-8 8H30L16 60V50h-6a8 8 0 01-8-8V16a8 8 0 018-8z"/></clipPath></defs><g clip-path="url(#k)"><rect width="64" height="16" y="6" fill="#BCD630"/><rect width="64" height="14" y="19" fill="#F3C015"/><rect width="64" height="14" y="31" fill="#E33426"/><rect width="64" height="30" y="43" fill="#00AAB0"/></g></svg>'
def page(inner, title='دانلود افزونه HomaCRM | افزونه پیامک وردپرس هما', desc='لینک دانلود افزونهٔ پیامک و CRM وردپرس هما (HomaCRM)'):
    return (PAGE % (html.escape(title), html.escape(desc), MARK + inner)).encode()

class H(http.server.BaseHTTPRequestHandler):
    server_version = 'leads'
    def log_message(self, *a): pass
    def _send(self, code, body=b'', ctype='text/html; charset=utf-8', extra=None):
        self.send_response(code); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(body))); self.send_header('Cache-Control', 'no-store')
        for k, v in (extra or {}).items(): self.send_header(k, v)
        self.end_headers(); self.wfile.write(body)
    def _j(self, o, code=200): self._send(code, json.dumps(o, ensure_ascii=False).encode(), 'application/json; charset=utf-8')
    def do_POST(self):
        if self.path != '/api/lead': return self._send(404)
        try: n = int(self.headers.get('Content-Length') or 0); d = json.loads(self.rfile.read(min(n, 4096)) or b'{}')
        except Exception: return self._j({'ok': False, 'error': 'bad'}, 400)
        ref = (self.headers.get('Origin') or '') + (self.headers.get('Referer') or '')
        if 'plugin.homacrm.com' not in ref: return self._j({'ok': False, 'error': 'origin'}, 403)
        if d.get('website'): return self._j({'ok': True})  # honeypot: به ربات وانمود می‌کنیم موفق بود
        if (d.get('dt') or 0) < 1500: return self._j({'ok': False, 'error': 'fast'}, 429)
        email = (d.get('email') or '').strip().lower()
        if not EMAIL_RE.match(email) or email.split('@')[1] in DISPOSABLE: return self._j({'ok': False, 'error': 'email'}, 400)
        ip = client_ip(self); now = time.time()
        with lock, db() as c:
            if c.execute('SELECT count(*) FROM leads WHERE ip=? AND created>?', (ip, now - 3600)).fetchone()[0] >= 6 or c.execute('SELECT count(*) FROM leads WHERE created>?', (now - 86400,)).fetchone()[0] >= 6000:
                return self._j({'ok': False, 'error': 'rate'}, 429)
            row = c.execute('SELECT * FROM leads WHERE email=?', (email,)).fetchone()
            if row:
                if row['resends'] >= 3 and now - (row['sent_at'] or 0) < 86400: return self._j({'ok': True})  # پنهان: بی‌خبر نگه می‌داریم
                c.execute("UPDATE leads SET status='pending', resends=resends+1 WHERE id=?", (row['id'],)); ev(c, row['id'], 'resubmit', self)
            else:
                c.execute('INSERT INTO leads(email,token,lead_key,created,ip,ua,utm_source,utm_medium,utm_campaign,ref,lang) VALUES(?,?,?,?,?,?,?,?,?,?,?)', (email, secrets.token_urlsafe(24), secrets.token_hex(8), now, ip, (self.headers.get('User-Agent') or '')[:300], str(d.get('us') or '')[:60], str(d.get('um') or '')[:60], str(d.get('uc') or '')[:60], str(d.get('ref') or '')[:200], str(d.get('lang') or '')[:20]))
                ev(c, c.execute('SELECT id FROM leads WHERE email=?', (email,)).fetchone()['id'], 'submit', self)
        return self._j({'ok': True})
    def do_GET(self):
        p = urllib.parse.urlparse(self.path).path
        m = re.match(r'^/get/([A-Za-z0-9_\-]{20,64})(/zip)?$', p); o = re.match(r'^/o/([A-Za-z0-9_\-]{20,64})\.gif$', p)
        if o:
            with db() as c:
                l = c.execute('SELECT id FROM leads WHERE token=?', (o.group(1),)).fetchone()
                if l: ev(c, l['id'], 'open', self)
            return self._send(200, bytes.fromhex('47494638396101000100800000000000ffffff21f90401000000002c00000000010001000002024401003b'), 'image/gif')
        if not m: return self._send(404, page('<h1>پیدا نشد</h1><p>این نشانی وجود ندارد.</p><p><a href="https://plugin.homacrm.com/">plugin.homacrm.com</a></p>', 'پیدا نشد | HomaCRM'))
        with db() as c:
            l = c.execute('SELECT * FROM leads WHERE token=?', (m.group(1),)).fetchone()
            if not l or time.time() - l['created'] > 14 * 86400 or l['downloads'] >= 8:
                return self._send(404, page('<h1>این لینک منقضی شده</h1><p>برای لینک تازه به <a href="https://plugin.homacrm.com/">plugin.homacrm.com</a> برگردید و ایمیل را دوباره ثبت کنید.</p>', 'لینک منقضی شد | HomaCRM'))
            bot = bool(BOT_RE.search(self.headers.get('User-Agent') or ''))
            if not m.group(2):
                ev(c, l['id'], 'click_scanner' if bot else 'click', self)
                z, name = personal_zip(l['lead_key'])
                ver = (re.search(r'homacrm-([\d.]+)\.zip', name or '') or [None, ''])[1]
                return self._send(200, page(f'<h1>افزونهٔ پیامک و CRM وردپرس هما</h1><p><span class="v">HomaCRM v{html.escape(ver)}</span></p><p>لینک نصب شما آماده است.</p><a class="b" href="/get/{l["token"]}/zip"><span>دانلود افزونه</span><small>Download Plugin</small></a><ol><li>در وردپرس: افزونه‌ها ← افزودن ← بارگذاری افزونه</li><li>فایل zip را انتخاب کنید و «نصب» و «فعال‌سازی» را بزنید</li><li>در منوی HomaCRM توکن رایگان بگیرید و بچسبانید</li></ol><p class="f">وردپرس ۵٫۲+ و PHP ۷٫۲+ · پرسش؟ <a href="mailto:support@homacrm.com">support@homacrm.com</a></p>', f'دانلود افزونه پیامک وردپرس هما (HomaCRM v{ver})', 'دانلود افزونهٔ پیامک و CRM وردپرس هما؛ نصب در سه قدم'))
            if bot: ev(c, l['id'], 'download_scanner', self); return self._send(403, page('<h1>این دانلود فقط از مرورگر ممکن است</h1>', 'HomaCRM'))
            z, name = personal_zip(l['lead_key'])
            if not z: return self._send(503, page('<h1>فایل موقتاً در دسترس نیست</h1><p>چند دقیقه بعد دوباره امتحان کنید.</p>', 'HomaCRM'))
            c.execute('UPDATE leads SET downloads=downloads+1 WHERE id=?', (l['id'],)); ev(c, l['id'], 'download', self, name)
        return self._send(200, z, 'application/zip', {'Content-Disposition': f'attachment; filename="{name}"'})
if __name__ == '__main__':
    init(); http.server.ThreadingHTTPServer(('0.0.0.0', 8000), H).serve_forever()
