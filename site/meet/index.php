<?php
// 與資訊部主任有約：請吃飯、請下午茶、約出去玩，或討論事情。同工自選時段，送出即在主任 ccra 行事曆建會議並寄邀請。
// 可約時段＝各目的的時段 −（ccra 行事曆忙碌）−（CCNDA 忙碌，只有忙閒、沒有內容），每個行程後留 30 分鐘休息。
// 熊哥 2026-10-03：CCNDA 要隱藏、CCRA 要公開；即時建會議；每次 90 分＋休息 30 分；開放三個月、六日也可；
// 地點加「其他地方」；說明要涵蓋「請我吃飯、請我下午茶、約我出去玩」；頁面要可愛。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/graph.php';
$id = access_identity();
$email = strtolower($id['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];

const OWNER = 'black@ccra.org.tw';
const SLOT_MIN = 90;          // 每次 90 分鐘
const REST_MIN = 30;          // 每個行程結束後留 30 分鐘休息
const STEP_MIN = 30;          // 開始時間每 30 分鐘一格
const DAYS_AHEAD = 92;        // 開放三個月
const LEAD_HOURS = 3;         // 至少提前 3 小時
// 目的 => [顯示, 圖示, 開始時間範圍（含頭尾）, 預設地點]。熊哥 10-04：開放時間 10–19、晚餐開放到七點
const PURPOSES = [
    'talk' => ['討論事情', '💬', [['10:00', '19:00']], 'teams'],
    'meal' => ['請吃飯',   '🍱', [['11:30', '13:00'], ['17:30', '19:00']], 'other'],
    'tea'  => ['請下午茶', '🍰', [['14:00', '16:30']], 'other'],
    'play' => ['約出去玩', '🎈', [['10:00', '19:00']], 'other'],
];
$PLACES = ['teams' => 'Teams 線上', 'office' => '台北辦公室', 'ccnda' => 'CCNDA 辦公室', 'other' => '其他地方'];   // 熊哥 10-03

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'meet' . $email . date('Y-m-d'), $secret);

function busy_intervals($from, $to) {
    $out = []; $path = '/users/' . OWNER . '/calendar/calendarView?startDateTime=' . urlencode($from->format('c'))
        . '&endDateTime=' . urlencode($to->format('c')) . '&$select=start,end,showAs&$top=500';
    while ($path) {
        [$code, $j] = graph('GET', $path);
        if ($code !== 200) throw new RuntimeException('calendar ' . $code);
        foreach ($j['value'] ?? [] as $e) {
            if (($e['showAs'] ?? '') === 'free') continue;
            $out[] = [strtotime($e['start']['dateTime'] . ' Asia/Taipei'), strtotime($e['end']['dateTime'] . ' Asia/Taipei')];
        }
        $path = isset($j['@odata.nextLink']) ? str_replace('https://graph.microsoft.com/v1.0', '', $j['@odata.nextLink']) : null;
    }
    $cc = json_decode((string)@file_get_contents(STATE_DIR . '/ccnda_busy.json'), true);
    if (!$cc || strtotime($cc['at']) < time() - 3600) throw new RuntimeException('ccnda busy stale');   // 舊資料寧可停用，不可多放時段
    foreach ($cc['busy'] as [$s, $e]) $out[] = [strtotime($s), strtotime($e)];
    return $out;
}
// 回傳 [目的 => [日期 => [開始時間戳...]]]
function free_slots() {
    $tz = new DateTimeZone('Asia/Taipei'); $now = time();
    $from = new DateTime('today', $tz); $to = (clone $from)->modify('+' . DAYS_AHEAD . ' days');
    $busy = busy_intervals($from, $to); $res = [];
    foreach (PURPOSES as $k => [, , $wins]) {
        for ($d = clone $from; $d < $to; $d->modify('+1 day')) {
            foreach ($wins as [$a, $b]) {
                $s = strtotime($d->format('Y-m-d ') . $a . ' Asia/Taipei'); $end = strtotime($d->format('Y-m-d ') . $b . ' Asia/Taipei');
                for (; $s <= $end; $s += STEP_MIN * 60) {   // $end 是最晚的開始時間
                    if ($s < $now + LEAD_HOURS * 3600) continue;
                    $e = $s + SLOT_MIN * 60; $ok = true;
                    // 衝突：開始落在別的行程＋休息內，或這次會議＋休息蓋到別的行程
                    foreach ($busy as [$bs, $be]) if ($s < $be + REST_MIN * 60 && $e + REST_MIN * 60 > $bs) { $ok = false; break; }
                    if ($ok) $res[$k][$d->format('Y-m-d')][] = $s;
                }
            }
        }
    }
    return $res;
}

$err = ''; $done = null;
$v = ['purpose' => 'talk', 'slot' => '', 'place' => '', 'place_text' => '', 'note' => '', 'name' => $who['name']];
try { $slots = free_slots(); } catch (Throwable $e) { error_log('meet: ' . $e->getMessage()); $slots = null; }

if ($slots !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $v = ['purpose' => array_key_exists($_POST['purpose'] ?? '', PURPOSES) ? $_POST['purpose'] : 'talk',
          'slot' => (string)($_POST['slot'] ?? ''), 'place' => array_key_exists($_POST['place'] ?? '', $PLACES) ? $_POST['place'] : '',
          'place_text' => trim($_POST['place_text'] ?? ''), 'note' => trim($_POST['note'] ?? ''), 'name' => trim($_POST['name'] ?? '')];
    $pool = array_merge([], ...array_values($slots[$v['purpose']] ?? []));
    [$plabel] = PURPOSES[$v['purpose']];
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) $err = '頁面已過期，請重新整理後再送出。';
    elseif (!in_array((int)$v['slot'], $pool, true)) $err = '這個時段剛被約走了，換一個時間吧！';
    elseif ($v['name'] === '' || mb_strlen($v['name']) > 50) $err = '請填寫你的名字。';
    elseif ($v['place'] === '') $err = '請選地點。';
    elseif ($v['place'] === 'other' && ($v['place_text'] === '' || mb_strlen($v['place_text']) > 80)) $err = '「其他地方」請寫一下在哪裡。';
    elseif (mb_strlen($v['note']) > 500) $err = '想說的話請在 500 字內。';
    else {
        $s = (int)$v['slot']; $e = $s + SLOT_MIN * 60;
        $placeLabel = $v['place'] === 'other' ? $v['place_text'] : $PLACES[$v['place']];
        $body = '<p>預約人：' . h($v['name']) . '（' . h($email) . '）' . ($who['dept'] ? '／' . h($who['dept']) : '') . '</p>'
              . '<p>目的：' . h($plabel) . '<br>地點：' . h($placeLabel) . '</p>'
              . ($v['note'] !== '' ? '<p>想說的話：<br>' . nl2br(h($v['note'])) . '</p>' : '')
              . '<p style="color:#888">由 it.ccra.tw「與資訊部主任有約」建立</p>';
        $ev = ['subject' => '【有約・' . $plabel . '】' . $v['name'] . ($v['note'] !== '' ? '：' . mb_substr(preg_replace('/\s+/u', ' ', $v['note']), 0, 30) : ''),
               'body' => ['contentType' => 'HTML', 'content' => $body],
               'start' => ['dateTime' => date('Y-m-d\TH:i:s', $s), 'timeZone' => 'Asia/Taipei'],
               'end' => ['dateTime' => date('Y-m-d\TH:i:s', $e), 'timeZone' => 'Asia/Taipei'],
               'attendees' => [['emailAddress' => ['address' => $email, 'name' => $v['name']], 'type' => 'required']],
               'allowNewTimeProposals' => true];
        if ($v['place'] === 'teams') { $ev['isOnlineMeeting'] = true; $ev['onlineMeetingProvider'] = 'teamsForBusiness'; }
        else $ev['location'] = ['displayName' => $placeLabel];
        [$code, $j] = graph('POST', '/users/' . OWNER . '/calendar/events', $ev);
        if ($code === 201) {
            $db = new PDO('sqlite:' . STATE_DIR . '/meet.sqlite');
            $db->exec('CREATE TABLE IF NOT EXISTS meet (id INTEGER PRIMARY KEY, created_at TEXT, email TEXT, name TEXT, start TEXT, topic TEXT, mode TEXT, event_id TEXT)');
            $db->prepare('INSERT INTO meet (created_at,email,name,start,topic,mode,event_id) VALUES (?,?,?,?,?,?,?)')
               ->execute([date('c'), $email, $v['name'], date('c', $s), $plabel . '｜' . $v['note'], $placeLabel, $j['id'] ?? '']);
            $done = [$s, $plabel, $placeLabel];
        } else {
            error_log('meet create ' . $code . ' ' . json_encode($j));
            $err = '建立會議失敗，請稍後再試，或直接 Teams 找大蘇。';
        }
    }
}
$wd = ['日', '一', '二', '三', '四', '五', '六'];
$data = [];
foreach ($slots ?? [] as $k => $days) foreach ($days as $d => $list) $data[$k][$d] = array_map(fn($s) => [$s, date('H:i', $s)], $list);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>與資訊部主任有約｜CCRA 資訊服務</title>
<link rel="icon" href="/img/logo.png">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<style>
/* 莫蘭迪藍綠（熊哥 10-03：要藍綠搭配、符合 CCNDA 配色，可愛但用莫蘭迪色系） */
:root{--teal:#6f9a96;--teal-d:#557c79;--blue:#8aa3b6;--sage:#a9bfb3;--mist:#d9e4e1;--cream:#f3f6f5}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--cream:#1d2a2e;--mist:#2a3a3d}}
body{background-image:radial-gradient(600px 300px at 90% 0%,color-mix(in srgb,var(--sage) 30%,transparent),transparent 70%),radial-gradient(600px 320px at 0% 20%,color-mix(in srgb,var(--blue) 26%,transparent),transparent 70%),linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);background-size:auto,auto,28px 28px,28px 28px}
.hero{display:flex;align-items:center;gap:16px;margin:22px 0 4px}
.hero .pic{width:76px;height:76px;border-radius:50%;flex:none;display:grid;place-items:center;font-size:38px;background:linear-gradient(135deg,var(--sage),var(--blue));border:3px solid #fff;box-shadow:0 8px 20px -10px rgba(85,124,121,.6);overflow:hidden;animation:bob 3s ease-in-out infinite}
.hero .pic img{width:100%;height:100%;object-fit:cover;object-position:top}
@keyframes bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}
.hero h1{margin:0;font-size:clamp(22px,4vw,28px);font-weight:900}
.hero p{margin:4px 0 0;color:var(--ink-2);font-size:15px}
.box{background:var(--panel);border:1px solid var(--edge);border-radius:24px;padding:18px;box-shadow:var(--glow);margin-top:16px}
.step{display:flex;align-items:center;gap:8px;font-weight:800;margin:0 0 10px;font-size:16px}
.step b{display:inline-grid;place-items:center;width:24px;height:24px;border-radius:50%;background:var(--teal);color:#fff;font-size:13px}
.pills{display:flex;flex-wrap:wrap;gap:10px}
.pill{position:relative}
.pill input{position:absolute;opacity:0;inset:0}
.pill span{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:2px solid var(--edge);background:var(--cream);font-weight:800;cursor:pointer;transition:transform .15s}
.pill span:hover{transform:translateY(-2px)}
.pill input:checked+span{border-color:var(--teal);background:var(--mist)}
.pill input:focus-visible+span{outline:3px solid var(--focus);outline-offset:2px}
.cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.cal-head strong{font-size:17px}
.cal-head button{border:0;background:var(--cream);border-radius:12px;width:38px;height:38px;font-size:18px;cursor:pointer;color:var(--ink)}
.cal-head button:disabled{opacity:.3;cursor:default}
.grid7{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;text-align:center}
.grid7 .wd{font-size:12.5px;color:var(--muted);font-weight:700}
.grid7 .wd.we{color:var(--blue)}
.day{height:clamp(44px,8vw,64px);border-radius:14px;border:0;background:transparent;color:var(--muted);font:700 15px/1 "Noto Sans TC",sans-serif;opacity:.45}
.day.on{opacity:1;color:var(--ink);background:var(--cream);cursor:pointer;position:relative}
.day.on::after{content:"";position:absolute;left:50%;bottom:7px;width:5px;height:5px;margin-left:-2.5px;border-radius:50%;background:var(--teal)}
.day.on:hover{background:var(--mist)}
.day.sel{background:var(--teal)!important;color:#fff}
.day.sel::after{background:#fff}
.times{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
.times button{border:2px solid var(--edge);background:var(--cream);border-radius:12px;padding:8px 12px;font:500 14px/1 "IBM Plex Mono",monospace;cursor:pointer;color:var(--ink)}
.times button.sel{background:var(--teal-d);color:#fff;border-color:var(--teal-d)}
.hint{color:var(--muted);font-size:14px}
label.q{display:block;font-weight:800;margin:14px 0 4px}
input[type=text],textarea{width:100%;font:inherit;color:inherit;background:var(--cream);border:2px solid var(--edge);border-radius:14px;padding:10px 12px}
textarea{min-height:80px}
.go{margin-top:18px;border:0;border-radius:999px;padding:13px 26px;font:900 16px/1 "Noto Sans TC",sans-serif;color:#fff;background:linear-gradient(135deg,var(--teal),var(--blue));cursor:pointer;box-shadow:0 10px 22px -12px rgba(85,124,121,.8)}
.go:disabled{opacity:.45;cursor:default;box-shadow:none}
.err{color:var(--red);font-weight:800}
.yay{text-align:center;padding:26px 18px}
.yay .big{font-size:46px;animation:bob 2s ease-in-out infinite}
@media (prefers-reduced-motion:reduce){.hero .pic,.yay .big{animation:none}}
</style>
</head>
<body>
<header class="bar"><div class="wrap">
  <a href="/"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁"></a>
  <div class="title">與資訊部主任有約<small>Book a meeting</small></div>
</div></header>
<main class="wrap">
  <div class="hero">
    <div class="pic"><?php if (is_file('/var/www/it.ccra.tw/img/black-avatar.webp')): ?><img src="/img/black-avatar.webp" alt=""><?php else: ?>🐻<?php endif; ?></div>
    <div><h1>與資訊部主任有約</h1><p>請王主任吃飯、喝下午茶、約出去玩，或有事情想討論，都歡迎！挑個時間，送出就收到邀請。</p></div>
  </div>
<?php if ($done !== null): [$s, $pl, $place] = $done; ?>
  <div class="box yay">
    <div class="big">🎉</div>
    <h2>約好了！</h2>
    <p><?= h(date('n/j', $s)) ?>（<?= $wd[(int)date('w', $s)] ?>）<?= h(date('H:i', $s)) ?>–<?= h(date('H:i', $s + SLOT_MIN * 60)) ?>｜<?= h($pl) ?>｜<?= h($place) ?></p>
    <p class="hint">邀請已寄到 <?= h($email) ?><?= $place === 'Teams 線上' ? '，裡面有 Teams 會議連結' : '' ?>。要改時間，直接在邀請裡「建議新時間」就可以。</p>
    <p><a class="btn ghost" href="/">回首頁</a></p>
  </div>
<?php elseif ($slots === null): ?>
  <div class="box"><p class="err">預約系統暫時讀不到行事曆 😢</p><p>請直接 <a href="https://teams.microsoft.com/l/chat/0/0?users=ituncle@ccra.org.tw" target="_blank" rel="noopener">Teams 找大蘇</a> 幫你約。</p></div>
<?php else: ?>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" id="f">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="slot" id="slot" value="">
    <div class="box">
      <p class="step"><b>1</b>想約主任做什麼？</p>
      <div class="pills">
        <?php foreach (PURPOSES as $k => [$lab, $ico]): ?>
          <label class="pill"><input type="radio" name="purpose" value="<?= $k ?>"<?= $v['purpose'] === $k ? ' checked' : '' ?>><span><?= $ico ?> <?= h($lab) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="box">
      <p class="step"><b>2</b>挑一天、挑時間（每次 90 分鐘）</p>
      <div class="cal-head"><button type="button" id="prev" aria-label="上個月">‹</button><strong id="mon"></strong><button type="button" id="next" aria-label="下個月">›</button></div>
      <div class="grid7" id="grid"></div>
      <p class="hint" id="pickday">有綠點的日子可以約，點一下看時間。</p>
      <div class="times" id="times"></div>
    </div>
    <div class="box">
      <p class="step"><b>3</b>在哪裡、想說什麼</p>
      <div class="pills">
        <?php foreach ($PLACES as $k => $lab): ?>
          <label class="pill"><input type="radio" name="place" value="<?= $k ?>"<?= $v['place'] === $k ? ' checked' : '' ?>><span><?= h($lab) ?></span></label>
        <?php endforeach; ?>
      </div>
      <div id="otherbox"<?= $v['place'] === 'other' ? '' : ' hidden' ?>>
        <label class="q" for="place_text">在哪裡？</label>
        <input type="text" id="place_text" name="place_text" maxlength="80" placeholder="例：辦公室附近的咖啡店、陽明山步道" value="<?= h($v['place_text']) ?>">
      </div>
      <label class="q" for="name">你的名字</label>
      <input type="text" id="name" name="name" maxlength="50" required value="<?= h($v['name']) ?>">
      <label class="q" for="note">想說的話（選填）</label>
      <textarea id="note" name="note" maxlength="500" placeholder="想討論的事、想吃的餐廳、想去的地方⋯⋯"><?= h($v['note']) ?></textarea>
      <button class="go" type="submit" id="go" disabled>送出邀約 →</button>
      <p class="hint">只會看到主任有空的時段，行程內容不會公開。</p>
    </div>
  </form>
<script>
(function () {
  var DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>, DEF = <?= json_encode(array_map(fn($p) => $p[3], PURPOSES)) ?>;
  var WD = ['日','一','二','三','四','五','六'];
  var form = document.getElementById('f'), slotIn = document.getElementById('slot'), go = document.getElementById('go');
  var today = new Date(); today.setHours(0,0,0,0);
  var first = new Date(today.getFullYear(), today.getMonth(), 1), last = new Date(today.getFullYear(), today.getMonth() + 4, 1);
  var view = new Date(first), selDay = null;
  function purpose() { return form.querySelector('input[name=purpose]:checked').value; }
  function key(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function render() {
    var days = DATA[purpose()] || {}, g = document.getElementById('grid'); g.innerHTML = '';
    document.getElementById('mon').textContent = view.getFullYear() + ' 年 ' + (view.getMonth() + 1) + ' 月';
    document.getElementById('prev').disabled = view <= first;
    document.getElementById('next').disabled = new Date(view.getFullYear(), view.getMonth() + 1, 1) >= last;
    WD.forEach(function (w, i) { var e = document.createElement('div'); e.className = 'wd' + (i === 0 || i === 6 ? ' we' : ''); e.textContent = w; g.appendChild(e); });
    for (var i = 0; i < view.getDay(); i++) g.appendChild(document.createElement('div'));
    var n = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
    for (var d = 1; d <= n; d++) {
      var dt = new Date(view.getFullYear(), view.getMonth(), d), k = key(dt), b = document.createElement('button');
      b.type = 'button'; b.textContent = d; b.className = 'day';
      if (days[k]) { b.className += ' on'; b.setAttribute('aria-label', (dt.getMonth() + 1) + '月' + d + '日 可約'); b.onclick = (function (k) { return function () { pickDay(k); }; })(k); }
      else b.disabled = true;
      if (k === selDay) b.className += ' sel';
      g.appendChild(b);
    }
  }
  function pickDay(k) {
    selDay = k; slotIn.value = ''; go.disabled = true; render();
    var t = document.getElementById('times'); t.innerHTML = '';
    var p = k.split('-'), dt = new Date(+p[0], +p[1] - 1, +p[2]);
    document.getElementById('pickday').textContent = (dt.getMonth() + 1) + '/' + dt.getDate() + '（' + WD[dt.getDay()] + '）可以的時間：';
    (DATA[purpose()][k] || []).forEach(function (s) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = s[1];
      b.onclick = function () { [].forEach.call(t.children, function (x) { x.classList.remove('sel'); }); b.classList.add('sel'); slotIn.value = s[0]; go.disabled = false; };
      t.appendChild(b);
    });
  }
  function setDefaultPlace() {
    var pl = form.querySelector('input[name=place][value="' + DEF[purpose()] + '"]');
    if (pl) { pl.checked = true; document.getElementById('otherbox').hidden = DEF[purpose()] !== 'other'; }
  }
  document.getElementById('prev').onclick = function () { view.setMonth(view.getMonth() - 1); render(); };
  document.getElementById('next').onclick = function () { view.setMonth(view.getMonth() + 1); render(); };
  [].forEach.call(form.querySelectorAll('input[name=purpose]'), function (r) { r.addEventListener('change', function () {
    selDay = null; slotIn.value = ''; go.disabled = true; document.getElementById('times').innerHTML = '';
    document.getElementById('pickday').textContent = '有綠點的日子可以約，點一下看時間。';
    setDefaultPlace(); render(); }); });
  [].forEach.call(form.querySelectorAll('input[name=place]'), function (r) { r.addEventListener('change', function () {
    document.getElementById('otherbox').hidden = r.value !== 'other'; }); });
  if (!form.querySelector('input[name=place]:checked')) setDefaultPlace();
  render();
})();
</script>
<?php endif; ?>
</main>
</body>
</html>
