#!/usr/bin/env python3
"""خطِ فرمانِ مدیریتِ صف: pending (فهرست برای فرستنده) | mark <id> sent|failed | report (همهٔ لیدها با رویدادها)."""
import json, sqlite3, sys, time, os
DB = os.path.join(os.environ.get('LEADS_DATA', '/app/data'), 'leads.db')
c = sqlite3.connect(DB, timeout=10); c.row_factory = sqlite3.Row
cmd = sys.argv[1] if len(sys.argv) > 1 else 'report'
if cmd == 'pending':
    print(json.dumps([dict(r) for r in c.execute("SELECT id,email,token,created,tries FROM leads WHERE status='pending' AND tries<4 ORDER BY id LIMIT 25")], ensure_ascii=False))
elif cmd == 'mark':
    i, st = int(sys.argv[2]), sys.argv[3]
    if st == 'sent': c.execute("UPDATE leads SET status='sent',sent_at=?,tries=tries+1 WHERE id=?", (time.time(), i))
    else: c.execute("UPDATE leads SET status='failed',tries=tries+1 WHERE id=?", (i,))
    c.commit(); print('ok')
elif cmd == 'requeue':
    c.execute("UPDATE leads SET status='pending',tries=0 WHERE status='failed'"); c.commit(); print(c.total_changes)
else:
    L = [dict(r) for r in c.execute('SELECT * FROM leads ORDER BY id')]
    for l in L: l['events'] = [dict(r) for r in c.execute('SELECT kind,ts,ip,extra FROM events WHERE lead_id=? ORDER BY ts', (l['id'],))]
    print(json.dumps(L, ensure_ascii=False))
