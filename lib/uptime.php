<?php
// UptimeRobot 讀取（網站狀況頁與戰情室共用）。唯讀金鑰只在主機端使用、不送到瀏覽器。
// 金鑰放 STATE_DIR/uptimerobot-readonly.key（www-data 640），不進 GitHub；結果快取 60 秒（免費方案有頻率限制）。
// 回傳 [資料或 null, 錯誤訊息]；資料 = ['at' => 時間戳, 'monitors' => [...]]。
function uptime_data() {
    $cache = STATE_DIR . '/uptime_cache.json';
    if (is_file($cache) && time() - filemtime($cache) < 60) return [json_decode(file_get_contents($cache), true), ''];
    $key = trim((string)@file_get_contents(STATE_DIR . '/uptimerobot-readonly.key'));
    $body = http_build_query(['api_key' => $key, 'format' => 'json', 'custom_uptime_ratios' => '1-7-30',
        'response_times' => 1, 'response_times_average' => 30, 'response_times_limit' => 48, 'logs' => 1, 'logs_limit' => 5]);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'content' => $body,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nCache-Control: no-cache\r\nUser-Agent: it.ccra.tw-status\r\n"]]);
    $raw = @file_get_contents('https://api.uptimerobot.com/v2/getMonitors', false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    if ($j && ($j['stat'] ?? '') === 'ok') {
        $data = ['at' => time(), 'monitors' => $j['monitors']];
        file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE));
        return [$data, ''];
    }
    if (is_file($cache)) return [json_decode(file_get_contents($cache), true), '暫時讀不到 UptimeRobot，以下為上次取得的資料。'];
    return [null, '讀不到 UptimeRobot，請稍後再試。'];
}
