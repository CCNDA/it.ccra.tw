<?php
// 報修截圖檢視：只有報修本人與資訊部看得到。檔案在網站目錄外（STATE_DIR/repair_files）。
require '/var/www/it-lib/access.php';
$id = access_identity();
$email = strtolower($id['email']);
require '/var/www/it-lib/itstaff.php';
$fn = basename((string)($_GET['f'] ?? ''));
if (!preg_match('/^(\d+)-[1-3]\.(jpg|png|gif|webp)$/', $fn, $m)) { http_response_code(404); exit; }
$db = new PDO('sqlite:' . STATE_DIR . '/repair.sqlite');
$st = $db->prepare('SELECT email, files FROM repair WHERE id = ?');
$st->execute([(int)$m[1]]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r || !in_array($fn, json_decode($r['files'] ?: '[]', true), true)) { http_response_code(404); exit; }
if (strtolower($r['email']) !== $email && !is_it_staff($email)) { http_response_code(403); exit('沒有權限檢視這張圖。'); }
$path = STATE_DIR . '/repair_files/' . $fn;
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][$m[2]]);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
