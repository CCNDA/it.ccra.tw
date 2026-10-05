<?php
// 網站監控儀表板（熊哥 10-04：UptimeRobot 是網站監控，改名避免與 Zabbix 主機監控混淆）：讀 UptimeRobot API（唯讀金鑰，只在主機端使用，不送到瀏覽器）。
// 金鑰放 /var/lib/it-ccra/uptimerobot-readonly.key（網站根目錄外、www-data 640），不進 GitHub。
// API 結果快取 60 秒，避免每次開頁都打 UptimeRobot（免費方案有呼叫頻率限制）。
require '/var/www/it-lib/access.php';
access_identity();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

require '/var/www/it-lib/uptime.php';
[$data, $err] = uptime_data();
$mons = $data['monitors'] ?? [];
// 狀態排序：異常在前
$rank = [9 => 0, 8 => 1, 1 => 2, 2 => 3, 0 => 4];
usort($mons, fn($a, $b) => [$rank[$a['status']] ?? 5, $a['friendly_name']] <=> [$rank[$b['status']] ?? 5, $b['friendly_name']]);
$label = [0 => '暫停', 1 => '尚未檢查', 2 => '正常', 8 => '疑似中斷', 9 => '中斷'];
$cls = [0 => 'paused', 1 => 'paused', 2 => 'up', 8 => 'warn', 9 => 'down'];
$count = ['up' => 0, 'down' => 0, 'paused' => 0];
foreach ($mons as $m) {
    $c = $cls[$m['status']] ?? 'paused';
    $count[$c === 'warn' ? 'down' : $c]++;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="120">
<title>網站監控｜CCRA 資訊服務</title>
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="apple-touch-icon" href="/img/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#109a4b">
<meta name="apple-mobile-web-app-title" content="資訊服務">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<style>
.sum{display:flex;flex-wrap:wrap;gap:12px;margin:22px 0 6px}
.sum div{flex:1 1 140px;background:var(--panel);border:1px solid var(--edge);border-radius:16px;padding:14px 16px;box-shadow:var(--glow)}
.sum b{display:block;font-size:30px;font-weight:900;line-height:1.1}
.sum span{color:var(--muted);font-size:14px}
.sum .up b{color:var(--green)} .sum .down b{color:var(--red)} .sum .paused b{color:var(--muted)}
.meta{color:var(--muted);font-size:13px;font-family:"IBM Plex Mono",ui-monospace,monospace;margin:4px 0 16px}
.err{color:var(--red);font-weight:700}
.list{display:grid;gap:10px;grid-template-columns:1fr}
@media (min-width:820px){.list{grid-template-columns:1fr 1fr}}
.mon{background:var(--panel);border:1px solid var(--edge);border-radius:14px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;gap:4px 12px;align-items:center}
.dot{width:12px;height:12px;border-radius:50%;grid-row:span 2}
.up .dot{background:var(--green);box-shadow:0 0 0 4px color-mix(in srgb,var(--green) 20%,transparent)}
.down .dot,.warn .dot{background:var(--red);box-shadow:0 0 0 4px color-mix(in srgb,var(--red) 25%,transparent)}
.paused .dot{background:var(--muted)}
.mon.down,.mon.warn{border-color:color-mix(in srgb,var(--red) 50%,transparent)}
.name{font-weight:800;overflow-wrap:anywhere}
.st{font-size:13px;font-weight:700;text-align:right}
.up .st{color:var(--green)} .down .st,.warn .st{color:var(--red)} .paused .st{color:var(--muted)}
.nums{grid-column:2 / 4;color:var(--muted);font:500 12.5px/1.5 "IBM Plex Mono",ui-monospace,monospace;display:flex;flex-wrap:wrap;gap:4px 14px}
.nums em{font-style:normal;color:var(--ink-2)}
</style>
<script src="/assets/whoami.js?v=dev" defer></script>
</head>
<body>
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">網站監控<small>Website uptime</small></div>
</div></header>
<main class="wrap">
  <div class="sum">
    <div class="up"><b><?= $count['up'] ?></b><span>正常</span></div>
    <div class="down"><b><?= $count['down'] ?></b><span>中斷／疑似中斷</span></div>
    <div class="paused"><b><?= $count['paused'] ?></b><span>暫停監測</span></div>
  </div>
  <p class="meta">資料來源 UptimeRobot（探測點在美國）・更新於 <?= $data ? date('Y-m-d H:i:s', $data['at']) : '—' ?>・頁面每 2 分鐘自動重新整理</p>
  <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
  <div class="list">
  <?php foreach ($mons as $m):
      $c = $cls[$m['status']] ?? 'paused';
      [$d1, $d7, $d30] = array_pad(explode('-', $m['custom_uptime_ratio'] ?? ''), 3, '');
      $avg = isset($m['average_response_time']) ? round((float)$m['average_response_time']) . ' ms' : '—';
      $lastDown = '';
      foreach ($m['logs'] ?? [] as $l) if (($l['type'] ?? 0) == 1) { $lastDown = date('m/d H:i', $l['datetime']) . '（' . round(($l['duration'] ?? 0) / 60) . ' 分）'; break; }
  ?>
    <div class="mon <?= $c ?>">
      <span class="dot" aria-hidden="true"></span>
      <span class="name"><?= h($m['friendly_name']) ?></span>
      <span class="st"><?= h($label[$m['status']] ?? '未知') ?></span>
      <span class="nums">
        <span>24h <em><?= h($d1 === '' ? '—' : rtrim(rtrim($d1, '0'), '.') . '%') ?></em></span>
        <span>7d <em><?= h($d7 === '' ? '—' : rtrim(rtrim($d7, '0'), '.') . '%') ?></em></span>
        <span>30d <em><?= h($d30 === '' ? '—' : rtrim(rtrim($d30, '0'), '.') . '%') ?></em></span>
        <span>回應 <em><?= h($avg) ?></em></span>
        <?php if ($lastDown): ?><span>上次中斷 <em><?= h($lastDown) ?></em></span><?php endif; ?>
      </span>
    </div>
  <?php endforeach; ?>
  </div>
</main>
</body>
</html>
