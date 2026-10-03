<?php
// AI 工具使用申請（內部版）。題目照 Google 表單 ccra.tw/ai-apply（2026-08-26 熊哥定案）。
// 資料存 /var/lib/it-ccra/ai_apply.sqlite；通知信由 IT大蘇本機腳本讀取後寄出（熊哥審核／主管知會／申請人確認）。
require '/var/www/it-lib/access.php';
$id = access_identity();
$email = strtolower($id['email']);

$DEPTS = ['秘書長室','公關與品牌溝通中心','傳媒組','文宣組','活動企劃組','緬甸辦事處','社會服務部','家庭發展組','服務管理組','捐款服務中心','資訊部','蘆洲食物銀行','創新拓展部','教會發展中心','職場宣教中心','行政處','財務室','彰化食物銀行','食物銀行','營運及災害管理組','個案管理&教育發展組','資源管理組','食物銀行台中園區','北基宜辦事處','桃竹苗辦事處','中彰投辦事處','高屏辦事處','花蓮辦事處','台東辦事處','雲嘉南辦事處','基隆實物銀行'];
$DEPTS[] = '其他';
// 登入者的姓名／部門預選（熊哥 10-03：依顯示名稱前的編號判斷，沒編號放其他）。對照表由 IT大蘇本機 it_dept_map.py 產生。
$who = (json_decode((string)@file_get_contents(STATE_DIR . '/dept_map.json'), true) ?: [])[$email] ?? ['name' => '', 'dept' => ''];
if ($who['dept'] !== '' && !in_array($who['dept'], $DEPTS, true)) array_splice($DEPTS, -1, 0, [$who['dept']]);
$USES  = ['逐字稿整理','新聞稿與文案撰寫','影片腳本與分鏡','跨語言翻譯','統計資料整理','其他'];

$secret = trim((string)@file_get_contents(STATE_DIR . '/form_secret'));
if ($secret === '') { $secret = bin2hex(random_bytes(32)); file_put_contents(STATE_DIR . '/form_secret', $secret); }
$csrf = hash_hmac('sha256', $email . date('Y-m-d'), $secret);

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$err = []; $done = false;
$v = ['name' => $who['name'], 'dept' => $who['dept'], 'uses' => [], 'other' => '', 'why' => '', 'pii' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) $err[] = '頁面已過期，請重新整理後再送出。';
    $v['name']  = trim($_POST['name'] ?? '');
    $v['dept']  = $_POST['dept'] ?? '';
    $v['uses']  = array_values(array_intersect($USES, (array)($_POST['uses'] ?? [])));
    $v['other'] = trim($_POST['other'] ?? '');
    $v['why']   = trim($_POST['why'] ?? '');
    $v['pii']   = $_POST['pii'] ?? '';
    if ($v['name'] === '' || mb_strlen($v['name']) > 50) $err[] = '請填寫姓名。';
    if (!in_array($v['dept'], $DEPTS, true)) $err[] = '請選擇部門。';
    if (!$v['uses']) $err[] = '請至少勾選一項預計使用方式。';
    if (in_array('其他', $v['uses'], true) && $v['other'] === '') $err[] = '勾選「其他」請簡述用途。';
    if ($v['why'] === '' || mb_strlen($v['why']) > 2000) $err[] = '請簡述為什麼 Copilot Chat 不夠用（2000 字內）。';
    if (!in_array($v['pii'], ['不會', '會'], true)) $err[] = '請選擇是否處理個資。';
    if (!$err) {
        $db = new PDO('sqlite:' . STATE_DIR . '/ai_apply.sqlite');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE IF NOT EXISTS ai_apply (id INTEGER PRIMARY KEY, created_at TEXT, email TEXT, name TEXT, dept TEXT, uses TEXT, why TEXT, pii TEXT, notified_at TEXT)');
        $uses = implode('、', $v['uses']) . ($v['other'] !== '' ? '（其他：' . $v['other'] . '）' : '');
        $st = $db->prepare('INSERT INTO ai_apply (created_at,email,name,dept,uses,why,pii) VALUES (?,?,?,?,?,?,?)');
        $st->execute([date('c'), $email, $v['name'], $v['dept'], $uses, $v['why'], $v['pii']]);
        $done = true;
    }
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI 工具使用申請｜CCRA 資訊服務</title>
<link rel="icon" href="/img/logo.png">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500&family=Noto+Sans+TC:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=dev">
<link rel="stylesheet" href="/assets/form.css?v=dev">
<style>
.errs{margin:12px 0 0;padding-left:20px}
.ro{margin:0;color:var(--ink-2)}
#other{margin-top:10px}
</style>
<script src="/assets/whoami.js?v=dev" defer></script>
</head>
<body>
<!-- 版型與「與資訊部主任有約」統一（熊哥 10-04） -->
<header class="bar"><div class="wrap">
  <a href="/" style="display:flex;align-items:center;gap:12px;text-decoration:none"><img class="mark" src="/img/logo.png" alt="CCRA 資訊服務首頁">
  <img class="word word-light" src="/img/textlogo_black.png" alt="中華基督教救助協會">
  <img class="word word-dark" src="/img/textlogo_white.png" alt="中華基督教救助協會"></a>
  <span class="sep" aria-hidden="true"></span>
  <div class="title">AI 工具使用申請<small>Apply for Claude</small></div>
</div></header>
<main class="wrap">
  <div class="hero">
    <div class="pic"><img src="/img/itsu-avatar.webp" alt=""></div>
    <div><h1><?= h($v['name'] !== '' ? preg_replace('/^\d{3}-/', '', $v['name']) . '，' : '') ?>平安！想申請 AI 工具嗎？</h1><p>先看看 Copilot Chat 夠不夠用；不夠的話填下面，資訊部審核後會寄信通知你。</p></div>
  </div>
<?php if ($done): ?>
  <div class="box yay">
    <div class="big">📨</div>
    <h2>收到你的申請了！</h2>
    <p>資訊部會審核，並同時知會你的部門主管；結果會寄到 <?= h($email) ?>。</p>
    <p class="hint">等待期間，逐字稿整理、摘要、文案初稿、翻譯、潤稿、影片腳本、生成圖片，用公司帳號登入 <a href="https://m365.cloud.microsoft/chat" target="_blank" rel="noopener">M365 Copilot Chat</a> 就可以先做。</p>
    <p><a class="btn ghost" href="/">回首頁</a></p>
  </div>
<?php else: ?>
  <div class="box note">
    <p class="step"><b>1</b>申請前先確認</p>
    <p style="margin:0">用公司帳號登入 <a href="https://m365.cloud.microsoft/chat" target="_blank" rel="noopener">M365 Copilot Chat</a>，逐字稿整理、摘要、文案初稿、翻譯、潤稿、影片腳本、生成圖片都已經可以做，<b>不需要申請</b>。<br>需要它交回檔案、一次比對多份文件、或長篇來回分析，才需要申請 Claude。</p>
  </div>
  <?php if ($err): ?><div class="box"><ul class="errs err"><?php foreach ($err as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="box">
      <p class="step"><b>2</b>你是誰</p>
      <label class="q" for="name">姓名</label>
      <input type="text" id="name" name="name" maxlength="50" required value="<?= h($v['name']) ?>">
      <label class="q">公司信箱</label>
      <p class="ro"><?= h($email) ?>（登入帳號，核准結果會寄到這裡）</p>
      <label class="q" for="dept">部門</label>
      <p class="hint" style="margin:0 0 6px">已依你的 M365 帳號預先選好，不對請自行更改；系統會依此知會你的部門主管。</p>
      <select id="dept" name="dept" required>
        <option value="">請選擇</option>
        <?php foreach ($DEPTS as $d) echo '<option' . ($v['dept'] === $d ? ' selected' : '') . '>' . h($d) . '</option>'; ?>
      </select>
    </div>
    <div class="box">
      <p class="step"><b>3</b>想用來做什麼（可複選）</p>
      <div class="pills">
        <?php foreach ($USES as $u): ?>
          <label class="pill"><input type="checkbox" name="uses[]" value="<?= h($u) ?>"<?= in_array($u, $v['uses'], true) ? ' checked' : '' ?>><span><?= h($u) ?></span></label>
        <?php endforeach; ?>
      </div>
      <input type="text" id="other" name="other" maxlength="200" placeholder="勾選「其他」請簡述" value="<?= h($v['other']) ?>">
      <label class="q" for="why">為什麼 Copilot Chat 不夠用？</label>
      <p class="hint" style="margin:0 0 6px">簡述你的工作內容與卡住的地方</p>
      <textarea id="why" name="why" maxlength="2000" required style="min-height:120px"><?= h($v['why']) ?></textarea>
    </div>
    <div class="box">
      <p class="step"><b>4</b>工作會不會處理個資或敏感資料？</p>
      <div class="pills">
        <?php foreach (['不會', '會'] as $o): ?>
          <label class="pill"><input type="radio" name="pii" value="<?= $o ?>"<?= $v['pii'] === $o ? ' checked' : '' ?> required><span><?= $o ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="hint">僅留紀錄，不影響核准。案主姓名、地址、身分證號、健康狀況等，不論用哪個 AI 都不應直接輸入。</p>
      <button class="go" type="submit">送出申請 →</button>
    </div>
  </form>
<script>
// 姓名從 M365 登入資料帶入（可修改）
var n = document.getElementById('name');
if (!n.value) fetch('/cdn-cgi/access/get-identity').then(function (r) { return r.json(); }).then(function (j) { if (j.name && !n.value) n.value = j.name; }).catch(function () {});
</script>
<?php endif; ?>
</main>
</body>
</html>
