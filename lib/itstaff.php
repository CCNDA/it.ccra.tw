<?php
// 資訊部帳號（戰情室、報修截圖等只給資訊部看的地方共用）。
// 熊哥 2026-10-04：資訊部 5 人＋大蘇（孔繁英調入；Sara、Jack）。異動時只改這裡。
const IT_STAFF = [
    'black@ccra.org.tw'                => '王獻宗',   // 熊哥，資訊部主任
    'orionlin@cceaccra.onmicrosoft.com' => '林春吉',  // 阿吉
    'irenek@ccra.org.tw'               => '孔繁英',
    'sarahshih@ccra.org.tw'            => '施懿芸',   // Sara
    'jack@ccra.org.tw'                 => '江桓',     // Jack
    'ituncle@ccra.org.tw'              => 'IT 大蘇',
];
function is_it_staff($email) { return isset(IT_STAFF[strtolower($email)]); }
