<?php
// 資訊部戰情室：只有資訊部帳號進得來，集中看各項申請並直接處置。
// 熊哥 2026-10-04 TG：「資訊部的帳號要多一個戰情室，專門處理申請事項的情報和處置」。
// 這台主機不寄信：報修改狀態只寫進資料庫，通知信由 IT大蘇本機 repair_notify.py 每小時補寄（先記後寄）。
require '/var/www/it-lib/access.php';
require '/var/www/it-lib/itstaff.php';
require '/var/www/it-lib/uptime.php';
require '/var/www/it-lib/zabbix.php';
require '/var/www/it-lib/graph.php';   // 主任有約取消時撤回行事曆邀請（同 /meet/ 那支的專用 App，只有行事曆權限）
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
// AI 申請的審核欄位（舊資料庫沒有就補上）。只有主任能核准（熊哥 2026-08-26 定案：由王主任審核，主管知會）
const AI_APPROVER = 'black@ccra.org.tw';
const AID = ['approve' => '核准', 'reject' => '不核准'];
function ai_db() {
    $d = db('ai_apply.sqlite');
    $cols = array_column($d->query('PRAGMA table_info(ai_apply)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['tools', 'planner_task_id', 'src', 'decision', 'decision_note', 'decided_by', 'decided_at', 'decision_notified_at'] as $c)
        if (!in_array($c, $cols, true)) $d->exec("ALTER TABLE ai_apply ADD COLUMN $c TEXT");
    return $d;
}
// 後續處置欄位：主任有約、咖啡廳也要能處置（熊哥 10-05：「約主任有約 資訊報修 申請咖啡廳 都可以有後續動作」）
function ensure_cols($file, $table, $cols) {
    $d = db($file);
    $have = array_column($d->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ($cols as $c) if (!in_array($c, $have, true)) $d->exec("ALTER TABLE $table ADD COLUMN $c TEXT");
    return $d;
}
const FOLLOW = ['decision', 'decision_note', 'decided_by', 'decided_at', 'decision_notified_at'];
const MEET_OWNER = 'black@ccra.org.tw';
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
    elseif (($_POST['act'] ?? '') === 'ai') {
        // AI 申請核准／不核准（熊哥 10-05：「准與不準我要怎麼核准 還有怎麼留下意見」）。
        // 只有主任能決定；這台不寄信，結果信與 Planner 由 IT大蘇本機 ai_internal_notify.py 下一輪處理（先記後寄）。
        $aid = (int)($_POST['id'] ?? 0); $dec = $_POST['decision'] ?? ''; $note = mb_substr(trim($_POST['note'] ?? ''), 0, 500);
        if ($email !== AI_APPROVER) $flash = 'AI 申請只有主任能核准。';
        elseif (!isset(AID[$dec])) $flash = '請選核准或不核准。';
        else {
            $d = ai_db();
            $s = $d->prepare('UPDATE ai_apply SET decision = ?, decision_note = ?, decided_by = ?, decided_at = ?, decision_notified_at = NULL WHERE id = ? AND decision IS NULL');
            $s->execute([$dec, $note, $me, date('c'), $aid]);
            $flash = $s->rowCount() ? '已' . AID[$dec] . '，申請人一小時內會收到結果通知信。' : '這筆已經有決定了。';
        }
        header('Location: ./?m=' . urlencode($flash) . '#ai', true, 303);
        exit;
    }
    elseif (($_POST['act'] ?? '') === 'meet') {
        // 主任有約：只有主任能處置。確認＝寄一句話給同工；取消＝撤回行事曆邀請（Graph 會把取消通知連同理由寄給同工）
        $mid = (int)($_POST['id'] ?? 0); $do = $_POST['do'] ?? ''; $note = mb_substr(trim($_POST['note'] ?? ''), 0, 300);
        $d = ensure_cols('meet.sqlite', 'meet', FOLLOW);
        $s = $d->prepare('SELECT * FROM meet WHERE id = ? AND decision IS NULL'); $s->execute([$mid]); $m = $s->fetch(PDO::FETCH_ASSOC);
        if ($email !== MEET_OWNER) $flash = '主任有約只有主任能處置。';
        elseif (!$m) $flash = '這筆已經處置過了。';
        elseif ($do === 'confirm') {
            $d->prepare('UPDATE meet SET decision = ?, decision_note = ?, decided_by = ?, decided_at = ? WHERE id = ?')->execute(['confirm', $note, $me, date('c'), $mid]);
            $flash = '已確認，一小時內寄給 ' . $m['name'] . '。';
        } elseif ($do === 'cancel') {
            if ($note === '') $flash = '取消要寫一句理由（會寄給同工）。';
            else {
                [$code, $j] = $m['event_id'] ? graph('POST', '/users/' . MEET_OWNER . '/events/' . rawurlencode($m['event_id']) . '/cancel',
                    ['comment' => $note . "\n\n想另約時間：https://it.ccra.tw/meet/"]) : [404, null];
                if ($code === 202 || $code === 404) {   // 404＝行事曆上已經不在（被手動刪掉），一樣記成取消，改由信件通知
                    $d->prepare('UPDATE meet SET decision = ?, decision_note = ?, decided_by = ?, decided_at = ?, decision_notified_at = ? WHERE id = ?')
                      ->execute(['cancel', $note, $me, date('c'), $code === 202 ? date('c') : null, $mid]);
                    $flash = $code === 202 ? '已取消，行事曆邀請已撤回並通知 ' . $m['name'] . '。' : '行事曆上已找不到這個會議，已記成取消，一小時內寄信通知 ' . $m['name'] . '。';
                } else { error_log('meet cancel ' . $code . ' ' . json_encode($j)); $flash = '撤回行事曆邀請失敗（' . $code . '），請稍後再試或在 Outlook 手動取消。'; }
            }
        }
        header('Location: ./?m=' . urlencode($flash) . '#meet', true, 303);
        exit;
    }
    elseif (($_POST['act'] ?? '') === 'cafe') {
        // 咖啡廳：加入由成員同步自動偵測；這裡只處理「婉拒」（附理由，一小時內寄給申請人）
        $cid = (int)($_POST['id'] ?? 0); $note = mb_substr(trim($_POST['note'] ?? ''), 0, 300);
        $d = ensure_cols('cafe_join.sqlite', 'cafe_join', FOLLOW);
        if ($note === '') $flash = '婉拒要寫一句理由（會寄給申請人）。';
        else {
            $s = $d->prepare('UPDATE cafe_join SET decision = ?, decision_note = ?, decided_by = ?, decided_at = ? WHERE id = ? AND decision IS NULL');
            $s->execute(['reject', $note, $me, date('c'), $cid]);
            $flash = $s->rowCount() ? '已婉拒，一小時內寄給申請人。' : '這筆已經處置過了。';
        }
        header('Location: ./?m=' . urlencode($flash) . '#cafe', true, 303);
        exit;
    }
    header('Location: ./?m=' . urlencode($flash) . '#repair', true, 303);
    exit;
}
$flash = (string)($_GET['m'] ?? '');

// ── 情報 ──
$repairs = rows('repair.sqlite', "SELECT * FROM repair ORDER BY CASE status WHEN 'new' THEN 0 WHEN 'doing' THEN 1 ELSE 2 END, CASE urgency WHEN 'high' THEN 0 WHEN 'mid' THEN 1 ELSE 2 END, id DESC LIMIT 60");
$openRep = array_filter($repairs, fn($r) => $r['status'] !== 'done');
$ai = is_file(STATE_DIR . '/ai_apply.sqlite')
    ? ai_db()->query('SELECT * FROM ai_apply ORDER BY (decision IS NULL) DESC, id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC) : [];
$members = json_decode((string)@file_get_contents(STATE_DIR . '/cafe_members.json'), true) ?: [];
$cafe = is_file(STATE_DIR . '/cafe_join.sqlite') ? ensure_cols('cafe_join.sqlite', 'cafe_join', FOLLOW)->query('SELECT * FROM cafe_join ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC) : [];
$cafePending = array_filter($cafe, fn($r) => empty($r['decision']) && !in_array(strtolower($r['email']), $members, true));
$meets = [];
if (is_file(STATE_DIR . '/meet.sqlite')) { $s = ensure_cols('meet.sqlite', 'meet', FOLLOW)->prepare('SELECT * FROM meet WHERE start >= ? ORDER BY start LIMIT 30'); $s->execute([date('c', strtotime('today'))]); $meets = $s->fetchAll(PDO::FETCH_ASSOC); }
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
// 熊哥 10-04：主機和防火牆分開顯示 → 依 Zabbix 主機群組拆成「主機」與「網路設備」兩張卡
$isNet = fn($x) => $x['group'] === '網路設備';
$zbHosts = array_values(array_filter($zh, fn($x) => !$isNet($x)));
$zbNet = array_values(array_filter($zh, $isNet));
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
  .autoref{margin:-4px 0 10px;font-size:12.5px;color:var(--muted);text-align:right}
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
.zcols{display:grid;grid-template-columns:1fr 1fr;gap:0 22px;margin-top:6px}
.zcol+.zcol{border-left:1px solid var(--edge);padding-left:22px}
@media (max-width:640px){.zcols{grid-template-columns:1fr}.zcol+.zcol{border-left:0;padding-left:0;border-top:1px solid var(--edge);padding-top:10px;margin-top:10px}}
.zhead{margin:6px 0 0;font-weight:800;color:var(--ink-2)}
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
  <p class="autoref" id="autoref">每分鐘自動更新・<?= h(date('H:i:s')) ?></p>
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

  <!-- 設備監控（Zabbix；熊哥 10-04 定名，不叫主機監控）：一張卡、左右兩欄（左主機、右防火牆），有問題的才列出來（熊哥 10-04） -->
  <a class="box hostbox <?= $zbBad ? 'bad' : 'good' ?>" href="https://mon.ccra.tw/" target="_blank" rel="noopener" id="servers">
    <div class="sec" style="margin:0"><h2><?= $zbBad ? '🔴' : '🟢' ?> 設備監控</h2><small>Zabbix<?= $zb ? '　更新於 ' . h(date('H:i', $zb['at'])) : '' ?>　點這裡看詳情 →</small></div>
    <?php if (!$zb): ?><p class="err"><?= h($zbErr) ?></p>
    <?php else: ?>
    <div class="zcols">
      <?php foreach ([['主機', $zbHosts], ['防火牆', $zbNet]] as [$ctitle, $list]):
          $nb = count(array_filter($list, fn($x) => $x['avail'] !== 1 || $x['problems'])); ?>
      <div class="zcol">
        <p class="zhead"><?= h($ctitle) ?></p>
        <p class="hostsum" style="margin-top:2px"><b><?= count($list) - $nb ?></b> 正常　<b class="<?= $nb ? 'red' : '' ?>"><?= $nb ?></b> 有狀況</p>
        <?php foreach ($list as $x): if ($x['avail'] === 1 && !$x['problems']) continue; ?>
          <p class="hostbad" style="display:block;margin-top:8px"><span class="tag high"><?= h($x['name']) ?></span><br>
            <span class="meta" style="margin:0"><?= $x['avail'] === 2 ? '連不到' : ($x['avail'] === 0 ? '狀態未知' : '') ?><?php foreach (array_slice($x['problems'], 0, 2) as $p) echo h(SEV[$p['severity']] ?? '') . '：' . h($p['name']) . '　'; ?></span></p>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($zbErr): ?><p class="meta"><?= h($zbErr) ?></p><?php endif; ?>
    <?php endif; ?>
  </a>

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
          <button name="status" value="doing">更新進度</button>
          <button name="status" value="done">已完成</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="box" id="ai">
    <div class="sec"><h2>✨ AI 工具使用申請</h2><small>主任在這裡核准／不核准並留意見，結果一小時內寄給申請人</small></div>
    <?php if (!$ai): ?><p class="empty">目前沒有申請。</p><?php endif; ?>
    <?php foreach ($ai as $r): ?>
      <div class="item">
        <div class="row"><span class="tag"><?= h($r['tools'] ?? 'Claude') ?></span><span class="ttl"><?= h($r['name']) ?></span><span class="meta" style="margin:0">（<?= h($r['dept']) ?>）<?= h($r['email']) ?>｜<?= h(ago($r['created_at'])) ?>｜個資：<?= h($r['pii']) ?></span></div>
        <details><summary>使用計畫與理由</summary><p class="desc"><b>使用計畫</b>：<?= h($r['uses']) ?></p><p class="desc"><b>為什麼 Copilot 不夠用</b>：<?= h($r['why']) ?></p></details>
        <?php if (!empty($r['decision'])): ?>
          <div class="meta"><span class="tag <?= $r['decision'] === 'approve' ? 'done' : 'high' ?>"><?= h(AID[$r['decision']] ?? $r['decision']) ?></span>
            <?= h($r['decided_by']) ?>｜<?= h(ago($r['decided_at'])) ?><?= $r['decision_note'] !== '' ? '｜意見：' . h($r['decision_note']) : '' ?>
            ｜<?= empty($r['decision_notified_at']) ? '結果信待寄' : '已通知申請人' ?></div>
        <?php elseif ($email === AI_APPROVER): ?>
        <form method="post" class="act">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="ai"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="note" maxlength="500" placeholder="意見（選填，會寫進給申請人的結果信）">
          <button class="ok" name="decision" value="approve">核准</button>
          <button name="decision" value="reject" onclick="return confirm('確定不核准？')">不核准</button>
        </form>
        <?php else: ?>
          <div class="meta"><span class="tag new">待主任審核</span></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="box" id="cafe">
    <div class="sec"><h2>☕ 咖啡廳加入申請</h2><small>在 Teams 加入頻道後自動變成「已加入」；不同意就婉拒並寫理由</small></div>
    <?php if (!$cafe): ?><p class="empty">目前沒有申請。</p><?php endif; ?>
    <?php foreach ($cafe as $r): $in = in_array(strtolower($r['email']), $members, true); ?>
      <div class="item"><div class="row"><span class="tag <?= $in ? 'done' : 'new' ?>"><?= $in ? '已加入' : '待加入' ?></span><span class="ttl"><?= h($r['name']) ?></span><span class="meta" style="margin:0">（<?= h($r['dept']) ?>）<?= h($r['email']) ?>｜<?= h(ago($r['created_at'])) ?></span></div>
      <?php if ($r['note']): ?><p class="desc"><?= h($r['note']) ?></p><?php endif; ?>
      <?php if (!empty($r['decision'])): ?>
        <div class="meta"><span class="tag high">已婉拒</span> <?= h($r['decided_by']) ?>｜<?= h(ago($r['decided_at'])) ?>｜理由：<?= h($r['decision_note']) ?>｜<?= empty($r['decision_notified_at']) ? '通知信待寄' : '已通知申請人' ?></div>
      <?php elseif (!$in): ?>
        <form method="post" class="act">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="cafe"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="note" maxlength="300" placeholder="婉拒理由（會寄給申請人）" required>
          <button name="do" value="reject" onclick="return confirm('確定婉拒？')">婉拒</button>
        </form>
      <?php endif; ?></div>
    <?php endforeach; ?>
  </div>

  <div class="box" id="meet">
    <div class="sec"><h2>📅 與資訊部主任有約</h2><small>今天起的預約；主任可確認（附一句話）或取消／請改期（撤回邀請並附理由）</small></div>
    <?php if (!$meets): ?><p class="empty">目前沒有預約。</p><?php endif; ?>
    <?php foreach ($meets as $r): $s = strtotime($r['start']); ?>
      <div class="item"><div class="row"><span class="no"><?= h(date('n/j', $s)) ?>（<?= $wd[(int)date('w', $s)] ?>）<?= h(date('H:i', $s)) ?></span><span class="ttl"><?= h($r['name']) ?></span><span class="meta" style="margin:0"><?= h($r['topic']) ?>｜<?= h($r['mode']) ?></span></div>
      <?php if (!empty($r['decision'])): ?>
        <div class="meta"><span class="tag <?= $r['decision'] === 'confirm' ? 'done' : 'high' ?>"><?= $r['decision'] === 'confirm' ? '已確認' : '已取消' ?></span> <?= h(ago($r['decided_at'])) ?><?= ($r['decision_note'] ?? '') !== '' ? '｜' . h($r['decision_note']) : '' ?>｜<?= empty($r['decision_notified_at']) ? '通知待寄' : '已通知' ?></div>
      <?php elseif ($email === MEET_OWNER): ?>
        <form method="post" class="act">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="meet"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="note" maxlength="300" placeholder="給同工的一句話（取消時必填）">
          <button class="ok" name="do" value="confirm">確認</button>
          <button name="do" value="cancel" onclick="return confirm('確定取消並撤回行事曆邀請？')">取消／請改期</button>
        </form>
      <?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</main>
<script>
// 開著就能看最新數字（熊哥 2026-10-04）：每 60 秒重新載入（監控資料快取也是 60 秒）。
// 正在填指派／處理說明、或展開了使用計畫時先不刷，免得打到一半被洗掉；分頁在背景時不刷，切回來若已過期就立刻刷。
(function () {
  var EVERY = 60000, last = Date.now(), KEY = 'ops-scroll';
  try { var y = sessionStorage.getItem(KEY); if (y !== null) { sessionStorage.removeItem(KEY); window.scrollTo(0, +y); } } catch (e) {}
  function busy() {
    var a = document.activeElement;
    var dirty = [].some.call(document.querySelectorAll('form.act input[type=text], form.act select'), function (f) {
      return f.tagName === 'SELECT' ? !f.options[f.selectedIndex].defaultSelected && f.selectedIndex !== 0 : f.value !== f.defaultValue;
    });
    return dirty || (a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName)) || document.querySelector('details[open]');
  }
  function go() {
    if (document.hidden || busy()) return;
    try { sessionStorage.setItem(KEY, String(window.scrollY)); } catch (e) {}
    location.replace(location.pathname + location.search);   // 用 GET 重載，不會重送剛才的表單
  }
  setInterval(function () { if (Date.now() - last >= EVERY) go(); }, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - last >= EVERY) go(); });
  var el = document.getElementById('autoref');
  var txt = el && el.textContent;
  setInterval(function () { if (el) el.textContent = busy() ? '編輯中，暫停自動更新' : txt; }, 2000);
})();
</script>
</body>
</html>
