<?php
// 資訊部戰情室：只有資訊部帳號進得來，集中看各項申請並直接處置。
// 熊哥 2026-10-04 TG：「資訊部的帳號要多一個戰情室，專門處理申請事項的情報和處置」。
// 這台主機不寄信：報修改狀態只寫進資料庫，通知信由 IT大蘇本機 repair_notify.py 每小時補寄（先記後寄）。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/uptime.php';
require '/var/www/it-lib/zabbix.php';
$id = access_identity();
$email = strtolower($id['email']);
if (!is_it_staff($email)) { http_response_code(403); exit('戰情室只開放給資訊部帳號。'); }
$me = IT_STAFF[$email];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function db($name) {
    $d = new PDO('sqlite:' . STATE_DIR . '/' . $name);
    $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $d;
}
function rows($name, $sql, $a = []) {
    if (!is_file(STATE_DIR . '/' . $name)) return [];
    $s = db($name)->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC);
}
// 報修表的追加欄位（舊資料庫沒有就補上）
function repair_db() {
    $d = db('repair.sqlite');
    $cols = array_column($d->query('PRAGMA table_info(repair)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['handler', 'status_notified_at', 'assignee', 'assigned_by', 'assigned_at', 'planner_task_id', 'planner_assigned_at'] as $c)
        if (!in_array($c, $cols, true)) $d->exec("ALTER TABLE repair ADD COLUMN $c TEXT");
    return $d;
}
function ticket_no($r) { return 'R' . date('ymd', strtotime($r['created_at'])) . '-' . str_pad((string)$r['id'], 3, '0', STR_PAD_LEFT); }
function ago($t) {
    $s = time() - strtotime($t);
    if ($s < 3600) return max(1, intdiv($s, 60)) . ' 分鐘前';
    if ($s < 86400) return intdiv($s, 3600) . ' 小時前';
    return intdiv($s, 86400) . ' 天前';
}
$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'ops' . $email . date('Y-m-d'), $secret);

const URG = ['low' => ['不急', 'low'], 'mid' => ['影響工作', 'mid'], 'high' => ['無法工作', 'high']];
const RST = ['new' => '待處理', 'doing' => '處理中', 'done' => '已完成'];

// ── 處置：報修改狀態 ──
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) $flash = '頁面已過期，請重新整理。';
    elseif (($_POST['act'] ?? '') === 'assign') {
        // 熊哥 10-04：「報修通知先不寄信只放頻道，都指派誰負責後開票跟進」——指派後由 IT大蘇本機腳本在 Planner 開任務給負責人
        $rid = (int)($_POST['id'] ?? 0); $who = strtolower($_POST['assignee'] ?? '');
        if (is_it_staff($who)) {
            $d = repair_db();
            $d->prepare("UPDATE repair SET assignee = ?, assigned_by = ?, assigned_at = ?, status = CASE status WHEN 'new' THEN 'doing' ELSE status END, updated_at = ?, status_notified_at = NULL WHERE id = ?")
              ->execute([$who, $me, date('c'), date('c'), $rid]);
            $flash = '已指派給 ' . IT_STAFF[$who] . '，一小時內 Planner 任務會掛上負責人，並通知報修同工。';
        }
    }
    elseif (($_POST['act'] ?? '') === 'repair') {
        $rid = (int)($_POST['id'] ?? 0); $st = $_POST['status'] ?? ''; $note = mb_substr(trim($_POST['note'] ?? ''), 0, 300);
        if (isset(RST[$st]) && $st !== 'new') {
            $d = repair_db();
            $d->prepare('UPDATE repair SET status = ?, note = ?, handler = ?, updated_at = ?, status_notified_at = NULL WHERE id = ?')
              ->execute([$st, $note, $me, date('c'), $rid]);
            $flash = '已更新，同工會在一小時內收到通知信。';
        }
    }
    header('Location: ./?m=' . urlencode($flash) . '#repair', true, 303);
    exit;
}
$flash = (string)($_GET['m'] ?? '');

// ── 情報 ──
$repairs = rows('repair.sqlite', "SELECT * FROM repair ORDER BY CASE status WHEN 'new' THEN 0 WHEN 'doing' THEN 1 ELSE 2 END, CASE urgency WHEN 'high' THEN 0 WHEN 'mid' THEN 1 ELSE 2 END, id DESC LIMIT 60");
$openRep = array_filter($repairs, fn($r) => $r['status'] !== 'done');
$ai = rows('ai_apply.sqlite', 'SELECT * FROM ai_apply ORDER BY id DESC LIMIT 30');
$members = json_decode((string)@file_get_contents(STATE_DIR . '/cafe_members.json'), true) ?: [];
$cafe = rows('cafe_join.sqlite', 'SELECT * FROM cafe_join ORDER BY id DESC LIMIT 30');
$cafePending = array_filter($cafe, fn($r) => !in_array(strtolower($r['email']), $members, true));
$meets = rows('meet.sqlite', 'SELECT * FROM meet WHERE start >= ? ORDER BY start LIMIT 30', [date('c', strtotime('today'))]);
$wd = ['日', '一', '二', '三', '四', '五', '六'];
// 網站監控摘要（UptimeRobot；熊哥 10-04：戰情室也要顯示，詳情再進網站監控頁）
[$up, $upErr] = uptime_data();
$mons = $up['monitors'] ?? [];
$bad = array_values(array_filter($mons, fn($m) => in_array((int)$m['status'], [8, 9], true)));
$okN = count(array_filter($mons, fn($m) => (int)$m['status'] === 2));
$pauseN = count($mons) - $okN - count($bad);
// 主機監控（Zabbix；熊哥 10-04：戰情室要有主機監控檢視清單）
[$zb, $zbErr] = zabbix_hosts();
$zh = $zb['hosts'] ?? [];
$zbBad = count(array_filter($zh, fn($x) => $x['avail'] !== 1 || $x['problems']));
const SEV = [0 => '未分類', 1 => '資訊', 2 => '警告', 3 => '一般', 4 => '嚴重', 5 => '災難'];
function pct($v) { return $v === null ? '—' : $v . '%'; }
function barcls($v) { return $v === null ? '' : ($v >= 90 ? 'hi' : ($v >= 75 ? 'mid' : '')); }
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>戰情室｜CCRA 資訊服務</title>
<link rel="icon" href="/img/logo.png">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<link rel="stylesheet" href="/assets/form.css?v=dev">
<script src="/assets/whoami.js?v=dev" defer></script>
<style>
.kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:18px}
@media (max-width:720px){.kpi{grid-template-columns:repeat(2,1fr)}}
.kpi a{display:block;text-decoration:none;color:inherit;background:var(--panel);border:1px solid var(--edge);border-radius:18px;padding:14px 16px;box-shadow:var(--glow)}
.kpi b{display:block;font-size:30px;font-weight:900;line-height:1.1}
.kpi span{color:var(--muted);font-size:13.5px}
.kpi .hot b{color:var(--red)}
.sec{display:flex;align-items:baseline;gap:10px;margin:0 0 10px}
.sec h2{margin:0;font-size:18px}
.sec small{color:var(--muted)}
.item{border-top:1px solid var(--edge);padding:12px 0}
.item:first-of-type{border-top:0}
.row{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}
.no{font:500 12.5px/1 "IBM Plex Mono",ui-monospace,monospace;color:var(--muted)}
.tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:800;background:var(--cream);border:1px solid var(--edge)}
.tag.high{background:color-mix(in srgb,var(--red) 16%,var(--panel));color:var(--red);border-color:transparent}
.tag.mid{background:color-mix(in srgb,var(--yellow) 22%,var(--panel));border-color:transparent}
.tag.new{background:var(--mist);color:var(--teal-d);border-color:transparent}
.tag.doing{background:color-mix(in srgb,var(--yellow) 22%,var(--panel));border-color:transparent}
.tag.done{background:color-mix(in srgb,var(--green) 16%,var(--panel));color:var(--green);border-color:transparent}
.ttl{font-weight:800}
.meta{color:var(--muted);font-size:13px;margin-top:3px}
.desc{margin:6px 0 0;font-size:14px;color:var(--ink-2);white-space:pre-wrap}
.act{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;align-items:center}
.act input[type=text]{flex:1 1 220px;padding:7px 10px;border-radius:10px;font-size:14px}
.act button{border:1px solid var(--edge);background:var(--cream);border-radius:10px;padding:7px 12px;font:800 13.5px/1 "Noto Sans TC",sans-serif;cursor:pointer;color:var(--ink)}
.act button.ok{background:var(--teal);border-color:var(--teal);color:#fff}
.shots a{font-size:13px;margin-right:8px}
.flash{background:var(--mist);border-radius:12px;padding:8px 12px;margin-top:14px;font-weight:700}
details summary{cursor:pointer;color:var(--muted);font-size:14px;margin-top:8px}
.empty{color:var(--muted);margin:0}
a.hostbox{display:block;text-decoration:none;color:inherit}
a.hostbox:hover{border-color:var(--teal)}
a.hostbox.bad{border-color:color-mix(in srgb,var(--red) 55%,var(--edge))}
.hostsum{margin:8px 0 0;font-size:15px}
.hostsum b{font-size:22px;font-weight:900}
.hostsum b.red{color:var(--red)}
.hostbad{margin:8px 0 0;display:flex;flex-wrap:wrap;gap:6px}
table.srv{width:100%;border-collapse:collapse;font-size:14px}
table.srv th,table.srv td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--edge);vertical-align:top}
table.srv th{color:var(--muted);font-weight:700;font-size:12.5px}
table.srv .tag{white-space:nowrap}
table.srv td.num{font:500 13.5px/1.4 "IBM Plex Mono",ui-monospace,monospace;white-space:nowrap}
table.srv td.mid{color:var(--yellow);font-weight:700} table.srv td.hi{color:var(--red);font-weight:800}
table.srv tr.warnrow td:first-child{border-left:3px solid var(--red);padding-left:8px}
table.srv a{color:var(--ink);font-weight:800;text-decoration:none} table.srv a:hover{text-decoration:underline}
</style>
</head>
<body>
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">戰情室<small>IT Ops Room</small></div>
</div></header>
<main class="wrap">
  <div class="hero">
    <div class="pic">🛰️</div>
    <div><h1><?= h($me) ?>，平安！今天的戰況</h1><p>只有資訊部帳號看得到。每張申請單都在 Planner「資訊部共同事務」有一張任務；指派負責人後掛上人，狀態變更會寄信通知同工。</p></div>
  </div>
  <?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>
  <div class="kpi">
    <a href="#repair" class="<?= count(array_filter($openRep, fn($r) => $r['status'] === 'new')) ? 'hot' : '' ?>"><b><?= count(array_filter($openRep, fn($r) => $r['status'] === 'new')) ?></b><span>報修待處理</span></a>
    <a href="#repair"><b><?= count(array_filter($openRep, fn($r) => $r['status'] === 'doing')) ?></b><span>報修處理中</span></a>
    <a href="#cafe"><b><?= count($cafePending) ?></b><span>咖啡廳待加入</span></a>
    <a href="#meet"><b><?= count($meets) ?></b><span>主任有約（今天起）</span></a>
  </div>

  <a class="box hostbox <?= $bad ? 'bad' : 'good' ?>" href="/status/" id="hosts">
    <div class="sec" style="margin:0"><h2><?= $bad ? '🔴' : '🟢' ?> 網站監控</h2><small><?= $up ? '更新於 ' . h(date('H:i', $up['at'])) : '' ?>　點這裡看詳情 →</small></div>
    <?php if (!$up): ?><p class="err"><?= h($upErr) ?></p>
    <?php else: ?>
      <p class="hostsum"><b><?= $okN ?></b> 正常　<b class="<?= $bad ? 'red' : '' ?>"><?= count($bad) ?></b> 中斷／疑似中斷<?= $pauseN ? '　<b>' . $pauseN . '</b> 暫停監測' : '' ?></p>
      <?php if ($bad): ?><p class="hostbad"><?php foreach ($bad as $m) echo '<span class="tag high">' . h($m['friendly_name']) . '</span> '; ?></p><?php endif; ?>
      <?php if ($upErr): ?><p class="meta"><?= h($upErr) ?></p><?php endif; ?>
    <?php endif; ?>
  </a>

  <div class="box" id="servers">
    <div class="sec"><h2><?= $zbBad ? '🔴' : '🟢' ?> 主機監控</h2><small>Zabbix<?= $zb ? '　更新於 ' . h(date('H:i', $zb['at'])) : '' ?>　<a href="https://mon.ccra.tw/" target="_blank" rel="noopener">開啟監控系統 →</a></small></div>
    <?php if (!$zb): ?><p class="err"><?= h($zbErr) ?></p>
    <?php else: ?>
      <?php if ($zbErr): ?><p class="meta"><?= h($zbErr) ?></p><?php endif; ?>
      <table class="srv">
        <thead><tr><th>主機</th><th>狀態</th><th>CPU</th><th>記憶體</th><th>硬碟</th></tr></thead>
        <tbody>
        <?php foreach ($zh as $x): $ok = $x['avail'] === 1; ?>
          <tr class="<?= $ok && !$x['problems'] ? '' : 'warnrow' ?>">
            <td><a href="https://mon.ccra.tw/zabbix.php?action=host.dashboard.view&amp;hostid=<?= (int)$x['hostid'] ?>" target="_blank" rel="noopener"><?= h($x['name']) ?></a><br><span class="meta"><?= h($x['group']) ?></span>
              <?php foreach (array_slice($x['problems'], 0, 3) as $p): ?><br><span class="tag <?= $p['severity'] >= 4 ? 'high' : 'mid' ?>"><?= h(SEV[$p['severity']] ?? '') ?></span> <span class="meta" style="margin:0"><?= h($p['name']) ?></span><?php endforeach; ?></td>
            <td><span class="tag <?= $ok ? 'done' : ($x['avail'] === 2 ? 'high' : '') ?>"><?= $ok ? '正常' : ($x['avail'] === 2 ? '連不到' : '未知') ?></span></td>
            <td class="num <?= barcls($x['cpu']) ?>"><?= pct($x['cpu']) ?></td>
            <td class="num <?= barcls($x['mem']) ?>"><?= pct($x['mem']) ?></td>
            <td class="num <?= barcls($x['disk']) ?>"><?= pct($x['disk']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="meta">網路設備（防火牆、交換器）沒有 CPU／記憶體／硬碟欄位時顯示「—」，詳細流量請點主機名稱進監控系統。</p>
    <?php endif; ?>
  </div>

  <div class="box" id="repair">
    <div class="sec"><h2>🛠️ 資訊報修</h2><small>未完成在前，越急越前面；新報修貼 Teams 報修頻道並開 Planner 任務（資訊部共同事務），指派後掛上負責人</small></div>
    <?php if (!$repairs): ?><p class="empty">目前沒有報修。</p><?php endif; ?>
    <?php foreach ($repairs as $r): $files = json_decode($r['files'] ?: '[]', true); ?>
      <div class="item">
        <div class="row"><span class="no"><?= h(ticket_no($r)) ?></span>
          <span class="tag <?= h($r['status']) ?>"><?= h(RST[$r['status']] ?? $r['status']) ?></span>
          <span class="tag <?= h(URG[$r['urgency']][1] ?? '') ?>"><?= h(URG[$r['urgency']][0] ?? $r['urgency']) ?></span>
          <span class="tag"><?= h($r['category']) ?></span>
          <span class="ttl"><?= h($r['summary']) ?></span></div>
        <div class="meta"><?= h($r['name']) ?>（<?= h($r['dept']) ?>）<?= $r['place'] ? '｜' . h($r['place']) : '' ?><?= $r['contact'] ? '｜' . h($r['contact']) : '' ?>｜<?= $r['remote_ok'] ? '可遠端' : '不要遠端' ?>｜<?= h(ago($r['created_at'])) ?><?= !empty($r['assignee']) ? '｜負責：' . h(IT_STAFF[$r['assignee']] ?? $r['assignee']) . (!empty($r['planner_assigned_at']) ? '' : '（一小時內同步到 Planner）') : '' ?><?= !empty($r['handler']) ? '｜最後更新：' . h($r['handler']) : '' ?></div>
        <?php if ($r['detail']): ?><p class="desc"><?= h($r['detail']) ?></p><?php endif; ?>
        <?php if ($files): ?><div class="shots"><?php foreach ($files as $i => $f): ?><a href="/repair/file.php?f=<?= h($f) ?>" target="_blank" rel="noopener">截圖 <?= $i + 1 ?></a><?php endforeach; ?></div><?php endif; ?>
        <?php if ($r['note']): ?><div class="meta">處理說明：<?= h($r['note']) ?></div><?php endif; ?>
        <?php if ($r['status'] !== 'done'): ?>
        <?php if (empty($r['assignee'])): ?>
        <form method="post" class="act">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="assign"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <select name="assignee" required style="flex:0 1 200px;padding:7px 10px;border-radius:10px;font-size:14px"><option value="">指派負責人…</option>
          <?php foreach (IT_STAFF as $em => $nm): ?><option value="<?= h($em) ?>"><?= h($nm) ?></option><?php endforeach; ?></select>
          <button class="ok">指派負責人</button>
        </form>
        <?php endif; ?>
        <form method="post" class="act">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="repair"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="note" maxlength="300" placeholder="給同工的一句話（會寫進通知信）" value="<?= h($r['note']) ?>">
          <button name="status" value="done">已完成</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="box" id="ai">
    <div class="sec"><h2>✨ AI 工具使用申請</h2><small>審核由熊哥回信核准；申請人已收到確認信</small></div>
    <?php if (!$ai): ?><p class="empty">目前沒有申請。</p><?php endif; ?>
    <?php foreach ($ai as $r): ?>
      <div class="item">
        <div class="row"><span class="tag"><?= h($r['tools'] ?? 'Claude') ?></span><span class="ttl"><?= h($r['name']) ?></span><span class="meta" style="margin:0">（<?= h($r['dept']) ?>）<?= h($r['email']) ?>｜<?= h(ago($r['created_at'])) ?>｜個資：<?= h($r['pii']) ?></span></div>
        <details><summary>使用計畫與理由</summary><p class="desc"><b>使用計畫</b>：<?= h($r['uses']) ?></p><p class="desc"><b>為什麼 Copilot 不夠用</b>：<?= h($r['why']) ?></p></details>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="box" id="cafe">
    <div class="sec"><h2>☕ 咖啡廳加入申請</h2><small>在 Teams 加入頻道後，這裡會自動變成「已加入」</small></div>
    <?php if (!$cafe): ?><p class="empty">目前沒有申請。</p><?php endif; ?>
    <?php foreach ($cafe as $r): $in = in_array(strtolower($r['email']), $members, true); ?>
      <div class="item"><div class="row"><span class="tag <?= $in ? 'done' : 'new' ?>"><?= $in ? '已加入' : '待加入' ?></span><span class="ttl"><?= h($r['name']) ?></span><span class="meta" style="margin:0">（<?= h($r['dept']) ?>）<?= h($r['email']) ?>｜<?= h(ago($r['created_at'])) ?></span></div>
      <?php if ($r['note']): ?><p class="desc"><?= h($r['note']) ?></p><?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="box" id="meet">
    <div class="sec"><h2>📅 與資訊部主任有約</h2><small>今天起的預約</small></div>
    <?php if (!$meets): ?><p class="empty">目前沒有預約。</p><?php endif; ?>
    <?php foreach ($meets as $r): $s = strtotime($r['start']); ?>
      <div class="item"><div class="row"><span class="no"><?= h(date('n/j', $s)) ?>（<?= $wd[(int)date('w', $s)] ?>）<?= h(date('H:i', $s)) ?></span><span class="ttl"><?= h($r['name']) ?></span><span class="meta" style="margin:0"><?= h($r['topic']) ?>｜<?= h($r['mode']) ?></span></div></div>
    <?php endforeach; ?>
  </div>
</main>
</body>
</html>
