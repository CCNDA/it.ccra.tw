#!/usr/bin/env python3
"""把熊哥 CCNDA 行事曆的「忙碌」時段展開成 JSON，給「與資訊部主任有約」頁使用。

來源是熊哥在 ccnda.net 發佈的「只能檢視何時有空」ICS（網址放 /var/lib/it-ccra/ccnda-ics.url，不進 GitHub）。
這份 ICS 只有「忙碌／空閒」，沒有標題地點內容——CCNDA 的行程細節從頭到尾不會進到這台主機。
重複行程（每週／每年）用 recurring_ical_events 展開；「空閒」（TRANSP:TRANSPARENT）不算忙碌。
由 it-ccra-ccnda.timer 每 10 分鐘執行；抓不到時保留上一份，不寫空檔（寫空檔＝全部時段都可約，方向錯）。
"""
import datetime as dt, json, os, sys, tempfile, urllib.request
import icalendar, recurring_ical_events

STATE = "/var/lib/it-ccra"
OUT = os.path.join(STATE, "ccnda_busy.json")
TZ = dt.timezone(dt.timedelta(hours=8))   # 台北
DAYS = 30

url = open(os.path.join(STATE, "ccnda-ics.url"), encoding="utf-8-sig").read().strip()
try:
    raw = urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "it.ccra.tw"}), timeout=30).read()
    cal = icalendar.Calendar.from_ical(raw)
except Exception as e:
    sys.exit(f"抓不到 CCNDA ICS，保留上一份：{e}")

now = dt.datetime.now(TZ)
start, end = now - dt.timedelta(days=1), now + dt.timedelta(days=DAYS)
busy = []
for ev in recurring_ical_events.of(cal).between(start, end):
    if str(ev.get("TRANSP", "OPAQUE")).upper() == "TRANSPARENT" or str(ev.get("SUMMARY", "")) == "空閒":
        continue
    s, e = ev["DTSTART"].dt, (ev.get("DTEND") or ev["DTSTART"]).dt
    if not isinstance(s, dt.datetime):          # 整天行程
        s = dt.datetime.combine(s, dt.time.min, TZ)
        e = dt.datetime.combine(e, dt.time.min, TZ) if not isinstance(e, dt.datetime) else e
    s = s if s.tzinfo else s.replace(tzinfo=TZ)
    e = e if e.tzinfo else e.replace(tzinfo=TZ)
    busy.append([s.astimezone(TZ).isoformat(), e.astimezone(TZ).isoformat()])

busy.sort()
fd, tmp = tempfile.mkstemp(dir=STATE)
with os.fdopen(fd, "w") as f:
    json.dump({"at": now.isoformat(), "busy": busy}, f)
os.chmod(tmp, 0o640)
os.replace(tmp, OUT)
print(f"CCNDA 忙碌時段 {len(busy)} 筆（{DAYS} 天內）")
