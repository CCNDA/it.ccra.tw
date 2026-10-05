<?php
// M365 大頭貼（熊哥 2026-10-05：「可以抓到 m365 帳號的頭像嗎」）。
// 網頁不直接打 Graph：資訊部維運腳本 avatar_sync.py 每天把照片同步到 /var/lib/it-ccra/avatars/<sha1(email)>.jpg，這裡只讀檔。
// 沒設照片的人回 404，前端改顯示姓名第一個字。
require '/var/www/it-lib/access.php';
access_identity();
$e = strtolower(trim((string)($_GET['e'] ?? '')));
$f = STATE_DIR . '/avatars/' . sha1($e) . '.jpg';
if (!preg_match('/^[^@\s\/]+@[^@\s\/]+$/', $e) || !is_file($f)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($f)) . ' GMT');
readfile($f);
