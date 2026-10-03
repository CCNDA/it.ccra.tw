<?php
// Microsoft Graph（委派）：只用來讀寫熊哥共用給 ituncle 的 ccra 行事曆。
// 用的是專用小 App「it.ccra.tw 預約（僅行事曆）」，權限只有 Calendars.ReadWrite.Shared＋User.Read（2026-10-03）。
// 權杖檔 /var/lib/it-ccra/booking-token.json（www-data 600，不進 GitHub）；refresh token 會輪替，每次更新都寫回。
const GRAPH_TOKEN = STATE_DIR . '/booking-token.json';

function graph_token() {
    $fp = fopen(GRAPH_TOKEN, 'c+');
    if (!$fp) throw new RuntimeException('no token file');
    flock($fp, LOCK_EX);                                   // 同時兩個請求不要各自輪替 refresh token
    $t = json_decode(stream_get_contents($fp), true);
    if (!$t) { flock($fp, LOCK_UN); fclose($fp); throw new RuntimeException('bad token file'); }
    if (($t['expires_at'] ?? 0) <= time()) {
        $body = http_build_query(['grant_type' => 'refresh_token', 'client_id' => $t['client_id'],
            'refresh_token' => $t['refresh_token'],
            'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite.Shared https://graph.microsoft.com/User.Read offline_access']);
        $r = @file_get_contents("https://login.microsoftonline.com/{$t['tenant_id']}/oauth2/v2.0/token", false,
            stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body]]));
        $j = $r ? json_decode($r, true) : null;
        if (empty($j['access_token'])) { flock($fp, LOCK_UN); fclose($fp); error_log('graph refresh failed: ' . substr((string)$r, 0, 200)); throw new RuntimeException('refresh failed'); }
        $t['access_token'] = $j['access_token'];
        $t['refresh_token'] = $j['refresh_token'] ?? $t['refresh_token'];
        $t['expires_at'] = time() + (int)$j['expires_in'] - 120;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($t)); fflush($fp);
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $t['access_token'];
}

function graph($method, $path, $body = null) {
    $h = "Authorization: Bearer " . graph_token() . "\r\nPrefer: outlook.timezone=\"Asia/Taipei\"\r\n";
    $opt = ['method' => $method, 'timeout' => 20, 'ignore_errors' => true, 'header' => $h];
    if ($body !== null) { $opt['header'] .= "Content-Type: application/json\r\n"; $opt['content'] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $r = @file_get_contents('https://graph.microsoft.com/v1.0' . $path, false, stream_context_create(['http' => $opt]));
    $code = (int)(preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0);
    return [$code, $r ? json_decode($r, true) : null];
}
