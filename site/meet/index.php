<?php
// 與資訊部主任有約：同工自選時段，送出即在主任的 ccra 行事曆建會議並寄邀請。
// 可約時段＝上班時段 −（ccra 行事曆的忙碌）−（CCNDA 行事曆的忙碌，只有忙閒、沒有內容）。
// 熊哥 2026-10-03：「CCNDA需要隱藏，但是CCRA部分要公開」；選「即時」建會議。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/graph.php';
$id = access_identity();
$email = strtolower($id['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];

const OWNER = 'black@ccra.org.tw';
const SLOT_MIN = 30;                       // 每次 30 分鐘
const DAYS_AHEAD = 14;                     // 可約兩週內
const LEAD_HOURS = 3;                      // 至少提前 3 小時
const WINDOWS = [['09:30', '12:00'], ['13:30', '17:30']];   // 週一到週五
$tz = new DateTimeZone('Asia/Taipei');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'meet' . $email . date('Y-m-d'), $secret);

// ── 忙碌時段 ──
function busy_intervals($from, $to) {
    $out = [];
    [$code, $j] = graph('GET', '/users/' . OWNER . '/calendar/calendarView?startDateTime=' . urlencode($from->format('c'))
        . '&endDateTime=' . urlencode($to->format('c')) . '&$select=start,end,showAs&$top=200');
    if ($code !== 200) throw new RuntimeException('calendar ' . $code);
    foreach ($j['value'] ?? [] as $e) {
        if (($e['showAs'] ?? '') === 'free') continue;
        $out[] = [strtotime($e['start']['dateTime'] . ' Asia/Taipei'), strtotime($e['end']['dateTime'] . ' Asia/Taipei')];
    }
    $cc = json_decode((string)@file_get_contents(STATE_DIR . '/ccnda_busy.json'), true);
    if (!$cc || strtotime($cc['at']) < time() - 3600) throw new RuntimeException('ccnda busy stale');   // 舊資料寧可停用，不可多放時段
    foreach ($cc['busy'] as [$s, $e]) $out[] = [strtotime($s), strtotime($e)];
    return $out;
}
function free_slots($tz) {
    $now = time(); $from = new DateTime('today', $tz); $to = (clone $from)->modify('+' . DAYS_AHEAD . ' days');
    $busy = busy_intervals($from, $to);
    $days = [];
    for ($d = clone $from; $d < $to; $d->modify('+1 day')) {
        if ((int)$d->format('N') >= 6) continue;
        foreach (WINDOWS as [$a, $b]) {
            $s = strtotime($d->format('Y-m-d ') . $a . ' Asia/Taipei'); $end = strtotime($d->format('Y-m-d ') . $b . ' Asia/Taipei');
            for (; $s + SLOT_MIN * 60 <= $end; $s += SLOT_MIN * 60) {
                if ($s < $now + LEAD_HOURS * 3600) continue;
                $e = $s + SLOT_MIN * 60; $ok = true;
                foreach ($busy as [$bs, $be]) if ($s < $be && $e > $bs) { $ok = false; break; }
                if ($ok) $days[$d->format('Y-m-d')][] = $s;
            }
        }
    }
    return $days;
}

$err = ''; $done = null; $v = ['topic' => '', 'detail' => '', 'mode' => 'teams', 'slot' => ''];
try {
    $slots = free_slots($tz);
} catch (Throwable $e) {
    error_log('meet: ' . $e->getMessage()); $slots = null;
}

if ($slots !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = ['topic' => trim($_POST['topic'] ?? ''), 'detail' => trim($_POST['detail'] ?? ''),
          'mode' => ($_POST['mode'] ?? '') === 'room' ? 'room' : 'teams', 'slot' => (string)($_POST['slot'] ?? '')];
    $name = trim($_POST['name'] ?? $who['name']);
    $all = array_merge(...array_values($slots ?: [[]]));
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) $err = '頁面已過期，請重新整理後再送出。';
    elseif (!in_array((int)$v['slot'], $all, true)) $err = '這個時段剛被約走了，請改選其他時段。';
    elseif ($v['topic'] === '' || mb_strlen($v['topic']) > 60) $err = '請簡述要談的事（60 字內）。';
    elseif ($name === '') $err = '請填寫姓名。';
    else {
        $s = (int)$v['slot']; $e = $s + SLOT_MIN * 60;
        $body = '<p>預約人：' . h($name) . '（' . h($email) . '）' . ($who['dept'] ? '／' . h($who['dept']) : '') . '</p>'
              . '<p>事由：' . h($v['topic']) . '</p>' . ($v['detail'] !== '' ? '<p>說明：' . nl2br(h(mb_substr($v['detail'], 0, 500))) . '</p>' : '')
              . '<p style="color:#888">由 it.ccra.tw「與資訊部主任有約」建立</p>';
        $ev = ['subject' => '【有約】' . $name . '：' . $v['topic'],
               'body' => ['contentType' => 'HTML', 'content' => $body],
               'start' => ['dateTime' => date('Y-m-d\TH:i:s', $s), 'timeZone' => 'Asia/Taipei'],
               'end' => ['dateTime' => date('Y-m-d\TH:i:s', $e), 'timeZone' => 'Asia/Taipei'],
               'attendees' => [['emailAddress' => ['address' => $email, 'name' => $name], 'type' => 'required']],
               'allowNewTimeProposals' => true];
        if ($v['mode'] === 'teams') { $ev['isOnlineMeeting'] = true; $ev['onlineMeetingProvider'] = 'teamsForBusiness'; }
        else $ev['location'] = ['displayName' => '資訊部（台北辦公室）'];
        [$code, $j] = graph('POST', '/users/' . OWNER . '/calendar/events', $ev);
        if ($code === 201) {
            $db = new PDO('sqlite:' . STATE_DIR . '/meet.sqlite');
            $db->exec('CREATE TABLE IF NOT EXISTS meet (id INTEGER PRIMARY KEY, created_at TEXT, email TEXT, name TEXT, start TEXT, topic TEXT, mode TEXT, event_id TEXT)');
            $db->prepare('INSERT INTO meet (created_at,email,name,start,topic,mode,event_id) VALUES (?,?,?,?,?,?,?)')
               ->execute([date('c'), $email, $name, date('c', $s), $v['topic'], $v['mode'], $j['id'] ?? '']);
            $done = $s;
        } else {
            error_log('meet create ' . $code . ' ' . json_encode($j));
            $err = '建立會議失敗，請稍後再試，或直接 Teams 找大蘇。';
        }
    }
}
$wd = ['日', '一', '二', '三', '四', '五', '六'];
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
.meet-head{display:flex;align-items:center;gap:14px;margin:22px 0 6px}
.meet-head .pic{width:64px;height:64px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:900;font-size:22px;color:#fff;background:linear-gradient(135deg,var(--green),#0b6b35);border:2px solid var(--edge);overflow:hidden}
.meet-head .pic img{width:100%;height:100%;object-fit:cover;object-position:top}
.meet-head h1{margin:0;font-size:24px}
.meet-head p{margin:2px 0 0;color:var(--muted);font-size:14.5px}
.panel{background:var(--panel);border:1px solid var(--edge);border-radius:18px;padding:18px;box-shadow:var(--glow);margin-top:16px}
.day{margin:0 0 14px}
.day h3{margin:0 0 8px;font-size:15px;color:var(--ink-2)}
.slots{display:flex;flex-wrap:wrap;gap:8px}
.slots label{position:relative}
.slots input{position:absolute;opacity:0;inset:0}
.slots span{display:inline-block;padding:7px 12px;border-radius:10px;border:1px solid var(--edge);font:500 14px/1 "IBM Plex Mono",ui-monospace,monospace;cursor:pointer;background:var(--paper)}
.slots input:checked+span{background:var(--ink);color:var(--paper);border-color:var(--ink)}
.slots input:focus-visible+span{outline:3px solid var(--focus);outline-offset:2px}
label.q{display:block;font-weight:700;margin:16px 0 4px}
input[type=text],textarea{width:100%;font:inherit;color:inherit;background:var(--paper);border:1px solid var(--edge);border-radius:10px;padding:9px 11px}
textarea{min-height:80px}
.modes label{margin-right:16px}
.err{color:var(--red);font-weight:700}
.note{color:var(--muted);font-size:13.5px}
</style>
</head>
<body>
<header class="bar"><div class="wrap">
  <a href="/"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁"></a>
  <div class="title">與資訊部主任有約<small>Book a meeting</small></div>
</div></header>
<main class="wrap">
  <div class="meet-head">
    <div class="pic"><?php if (is_file('/var/www/it.ccra.tw/img/black-avatar.webp')): ?><img src="/img/black-avatar.webp" alt=""><?php else: ?>王<?php endif; ?></div>
    <div><h1>王獻宗 主任</h1><p>資訊部｜選一個時段，送出後會直接收到會議邀請（每次 30 分鐘）</p></div>
  </div>
<?php if ($done !== null): ?>
  <div class="panel">
    <p><b>約好了！</b><?= h(date('n/j', $done)) ?>（<?= $wd[(int)date('w', $done)] ?>）<?= h(date('H:i', $done)) ?>–<?= h(date('H:i', $done + SLOT_MIN * 60)) ?></p>
    <p>會議邀請已寄到 <?= h($email) ?><?= $v['mode'] === 'teams' ? '，裡面有 Teams 會議連結' : '，地點：資訊部' ?>。要改時間可以直接在邀請裡「建議新時間」。</p>
    <p><a class="btn ghost" href="/">回首頁</a></p>
  </div>
<?php elseif ($slots === null): ?>
  <div class="panel"><p class="err">預約系統暫時無法讀取行事曆。</p><p>請直接 <a href="https://teams.microsoft.com/l/chat/0/0?users=ituncle@ccra.org.tw" target="_blank" rel="noopener">Teams 找大蘇</a> 幫你約。</p></div>
<?php else: ?>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" class="panel">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <?php if (!$slots): ?><p>未來兩週已經約滿，請 Teams 找大蘇協調。</p><?php endif; ?>
    <?php foreach ($slots as $day => $list): $t = strtotime($day . ' Asia/Taipei'); ?>
      <div class="day">
        <h3><?= h(date('n/j', $t)) ?>（<?= $wd[(int)date('w', $t)] ?>）</h3>
        <div class="slots">
          <?php foreach ($list as $s): ?><label><input type="radio" name="slot" value="<?= $s ?>" required<?= (string)$s === $v['slot'] ? ' checked' : '' ?>><span><?= date('H:i', $s) ?></span></label><?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($slots): ?>
    <label class="q" for="name">姓名</label>
    <input type="text" id="name" name="name" maxlength="50" required value="<?= h($who['name']) ?>">
    <label class="q" for="topic">想談的事</label>
    <input type="text" id="topic" name="topic" maxlength="60" required placeholder="例：部門網站改版、資料庫權限申請" value="<?= h($v['topic']) ?>">
    <label class="q" for="detail">補充說明（選填）</label>
    <textarea id="detail" name="detail" maxlength="500"><?= h($v['detail']) ?></textarea>
    <label class="q">方式</label>
    <div class="modes">
      <label><input type="radio" name="mode" value="teams"<?= $v['mode'] !== 'room' ? ' checked' : '' ?>> Teams 線上</label>
      <label><input type="radio" name="mode" value="room"<?= $v['mode'] === 'room' ? ' checked' : '' ?>> 到資訊部當面談</label>
    </div>
    <button class="btn" type="submit" style="margin-top:18px">送出預約 →</button>
    <p class="note">只會顯示主任有空的時段；行程內容不會公開。</p>
    <?php endif; ?>
  </form>
<?php endif; ?>
</main>
</body>
</html>
