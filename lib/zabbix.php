<?php
// Zabbix 主機監控摘要（戰情室用）。走 ITSrv01 本機的 Zabbix API，用唯讀帳號 it-ccra-ops 的 API token。
// token 放 STATE_DIR/zabbix-ops.token（www-data 600），不進 GitHub；結果快取 60 秒。
// 回傳 [主機陣列或 null, 錯誤訊息]；每台：name, host, hostid, group, avail(1 正常/2 連不到/0 未知), cpu, mem, disk, problems[]。
function zbx_call($method, $params, $token) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
        'header' => "Content-Type: application/json-rpc\r\nHost: mon.ccra.tw\r\nAuthorization: Bearer $token\r\n",
        'content' => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])]]);
    $r = @file_get_contents('http://127.0.0.1/api_jsonrpc.php', false, $ctx);
    $j = $r ? json_decode($r, true) : null;
    if (!$j || isset($j['error'])) throw new RuntimeException($method . ': ' . ($j['error']['data'] ?? 'no response'));
    return $j['result'];
}

function zabbix_hosts() {
    $cache = STATE_DIR . '/zabbix_cache.json';
    if (is_file($cache) && time() - filemtime($cache) < 60) return [json_decode(file_get_contents($cache), true), ''];
    $token = trim((string)@file_get_contents(STATE_DIR . '/zabbix-ops.token'));
    try {
        $hosts = zbx_call('host.get', ['output' => ['hostid', 'host', 'name'], 'filter' => ['status' => 0],
            'selectInterfaces' => ['type', 'available', 'error'], 'selectHostGroups' => ['name']], $token);
        $ids = array_column($hosts, 'hostid');
        $keys = ['system.cpu.util', 'vm.memory.utilization', 'vm.memory.util', 'vfs.fs.dependent.size[/,pused]', 'vfs.fs.dependent.size[C:,pused]', 'icmppingsec'];
        $items = zbx_call('item.get', ['hostids' => $ids, 'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
            'filter' => ['key_' => $keys]], $token);
        $probs = zbx_call('problem.get', ['hostids' => $ids, 'output' => ['eventid', 'name', 'severity', 'clock', 'objectid'],
            'recent' => false, 'suppressed' => false, 'sortfield' => ['eventid'], 'sortorder' => 'DESC'], $token);
        $trig = $probs ? zbx_call('trigger.get', ['triggerids' => array_column($probs, 'objectid'), 'output' => ['triggerid'],
            'selectHosts' => ['hostid']], $token) : [];
        $t2h = [];
        foreach ($trig as $t) $t2h[$t['triggerid']] = $t['hosts'][0]['hostid'] ?? null;
        $out = [];
        foreach ($hosts as $h) {
            $av = 0;
            foreach ($h['interfaces'] as $i) $av = max($av, (int)$i['available']);
            foreach ($h['interfaces'] as $i) if ((int)$i['available'] === 2) $av = 2;
            $row = ['hostid' => $h['hostid'], 'name' => $h['name'], 'group' => $h['hostgroups'][0]['name'] ?? '', 'avail' => $av,
                'cpu' => null, 'mem' => null, 'disk' => null, 'problems' => []];
            foreach ($items as $it) {
                if ($it['hostid'] !== $h['hostid'] || !$it['lastclock']) continue;
                $v = round((float)$it['lastvalue'], 1);
                if ($it['key_'] === 'system.cpu.util') $row['cpu'] = $v;
                elseif ($it['key_'] === 'vm.memory.utilization' || $it['key_'] === 'vm.memory.util') $row['mem'] = $v;   // Linux／Windows 範本鍵名不同
                elseif (str_starts_with($it['key_'], 'vfs.fs.dependent.size')) $row['disk'] = $v;
            }
            foreach ($probs as $p) if (($t2h[$p['objectid']] ?? null) === $h['hostid'])
                $row['problems'][] = ['name' => $p['name'], 'severity' => (int)$p['severity'], 'clock' => (int)$p['clock']];
            $out[] = $row;
        }
        usort($out, fn($a, $b) => [count($b['problems']) > 0, $a['avail'] !== 1, $a['group'], $a['name']] <=> [count($a['problems']) > 0, $b['avail'] !== 1, $b['group'], $b['name']]);
        $data = ['at' => time(), 'hosts' => $out];
        file_put_contents($cache, json_encode($data, JSON_UNESCAPED_UNICODE));
        return [$data, ''];
    } catch (Throwable $e) {
        error_log('zabbix: ' . $e->getMessage());
        if (is_file($cache)) return [json_decode(file_get_contents($cache), true), '暫時讀不到 Zabbix，以下為上次取得的資料。'];
        return [null, '讀不到 Zabbix，請稍後再試。'];
    }
}
