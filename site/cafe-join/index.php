<?php
// 申請加入「資訊部咖啡廳」頻道。
// 咖啡廳是 Teams 共用頻道，Teams 沒有「申請加入」功能，只能由頻道擁有者加人（熊哥 2026-10-03 選「申請按鈕」）。
// 這裡只登記申請；通知擁有者由資訊部維運腳本處理（同 AI 申請：先記後寄）。
require '/var/www/it-lib/access.php';
$id = access_identity();
$email = strtolower($id['email']);
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];

// 已經在咖啡廳的人直接轉進 Teams 頻道（成員名單由資訊部每小時同步：直接成員＋被分享進來的團隊成員）
$CAFE_URL = 'https://teams.microsoft.com/l/channel/19%3Ad89qZSRpKulhxvxuxwkbfOfegBorZoSonLbWByZUorg1%40thread.tacv2/%E8%B3%87%E8%A8%8A%E9%83%A8%E5%92%96%E5%95%A1%E5%BB%B3?groupId=6e959ba3-f668-49e1-8dcc-e97df5fbfc6d&tenantId=a18de7ab-5b49-41ac-8831-357e9b0d5817';
$members = json_decode((string)@file_get_contents(STATE_DIR . '/cafe_members.json'), true) ?: [];
if (in_array($email, $members, true)) { header('Location: ' . $CAFE_URL, true, 302); exit; }

$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
$csrf = hash_hmac('sha256', 'cafe' . $email . date('Y-m-d'), $secret);
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$db = new PDO('sqlite:' . STATE_DIR . '/cafe_join.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS cafe_join (id INTEGER PRIMARY KEY, created_at TEXT, email TEXT, name TEXT, dept TEXT, note TEXT, notified_at TEXT)');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) $err = '頁面已過期，請重新整理後再送出。';
    elseif ($name === '' || mb_strlen($name) > 50) $err = '請填寫姓名。';
    else {
        $st = $db->prepare('SELECT COUNT(*) FROM cafe_join WHERE email = ?');
        $st->execute([$email]);
        if (!$st->fetchColumn()) {
            $db->prepare('INSERT INTO cafe_join (created_at,email,name,dept,note) VALUES (?,?,?,?,?)')
               ->execute([date('c'), $email, $name, $who['dept'], mb_substr(trim($_POST['note'] ?? ''), 0, 300)]);
        }
        header('Location: ./?sent=1', true, 303);
        exit;
    }
}
$st = $db->prepare('SELECT created_at FROM cafe_join WHERE email = ? ORDER BY id DESC LIMIT 1');
$st->execute([$email]);
$asked = $st->fetchColumn();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>申請加入資訊部咖啡廳｜CCRA 資訊服務</title>
<link rel="icon" href="/img/logo.png">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<link rel="stylesheet" href="/assets/form.css?v=dev">
<script src="/assets/whoami.js?v=dev" defer></script>
<script src="/assets/edgetip.js?v=dev" defer></script>
</head>
<body>
<!-- 版型與「與資訊部主任有約」統一（熊哥 10-04） -->
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">資訊部咖啡廳<small>IT Café</small></div>
</div></header>
<main class="wrap">
  <div class="hero">
    <div class="pic">☕</div>
    <div><h1><?= h($who['name'] !== '' ? preg_replace('/^\d{3}-/', '', $who['name']) . '，' : '') ?>平安！進來坐坐嗎？</h1><p>資訊部在 Teams 上的共用頻道：想找資訊部同仁討論事情，或是一起靈修，都歡迎。</p></div>
  </div>
<?php if ($asked || isset($_GET['sent'])): ?>
  <div class="box yay">
    <div class="big">☕</div>
    <h2>收到你的申請了！</h2>
    <p><?= $asked ? '申請日期 ' . h(substr($asked, 0, 10)) . '。' : '' ?>頻道管理者會把你加進來。</p>
    <p class="hint">加入後，Teams 左側「資訊部」團隊底下會出現「資訊部咖啡廳」。</p>
    <p><a class="btn ghost" href="/">回首頁</a></p>
  </div>
<?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="box">
      <p class="step"><b>1</b>申請加入</p>
      <?php if ($err): ?><p class="err"><?= h($err) ?></p><?php endif; ?>
      <label class="q" for="name">姓名</label>
      <input type="text" id="name" name="name" maxlength="50" required value="<?= h($who['name']) ?>">
      <label class="q">登入帳號</label>
      <p style="margin:0;color:var(--ink-2)"><?= h($email) ?></p>
      <label class="q" for="note">想說的話（選填）</label>
      <textarea id="note" name="note" maxlength="300"></textarea>
      <button class="go" type="submit">申請加入 →</button>
    </div>
  </form>
<?php endif; ?>
</main>
</body>
</html>
