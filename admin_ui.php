<?php
/**
 * 讀伴 ・ 後台共用的畫面元件(管理者後台 dev.php、教師後台 teacher.php)
 * — 只負責輸出 HTML,不處理資料或權限。
 */

/** 單色線條圖示(stroke=currentColor);純裝飾,一律 aria-hidden */
function ui_icon($name, $cls = 'ic') {
    static $P = [
        'usage'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'guests'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'audit'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'courses'  => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/>',
        'accounts' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/>',
        'prompts'  => '<path d="M4 6h10M4 12h16M4 18h7"/><circle cx="17" cy="6" r="2"/><circle cx="14" cy="18" r="2"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'logout'   => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4"/>',
        'chev'     => '<path d="M6 9l6 6 6-6"/>',
        'warn'     => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.01"/>',
    ];
    return '<svg class="' . $cls . '" viewBox="0 0 24 24" aria-hidden="true">' . ($P[$name] ?? '') . '</svg>';
}

/** 與前台相同的讀伴書本標誌 */
function ui_logo($cls = 'brand-logo') {
    return '<svg class="' . $cls . '" viewBox="0 0 640 620" aria-hidden="true"><path class="bl-cover" d="M0,108 L32,92 L32,80 C120,20 250,0 338,42 C420,-5 520,0 597,30 L597,50 L630,62 L630,528 C540,520 420,535 338,605 C250,545 120,530 10,595 L0,592 Z"/><path class="bl-page" d="M52,78 C130,30 250,20 328,58 L328,520 C250,480 140,475 52,528 Z"/><path class="bl-page" d="M348,58 C420,15 520,15 580,40 L580,470 C500,450 410,460 348,515 Z"/><g class="bl-ink"><path d="M80,180 C150,140 240,135 310,165"/><path d="M80,255 C150,215 240,210 310,240"/><path d="M80,330 C150,290 240,285 310,315"/><path d="M160,385 C210,365 260,370 310,400"/><path d="M362,165 C395,138 420,140 440,165 C456,186 466,225 484,240"/><path d="M362,245 C398,220 445,215 484,240"/><path d="M362,325 C398,302 445,285 484,240"/><path d="M362,400 C392,374 412,368 430,345 C452,318 470,265 484,240"/><path d="M484,240 C512,246 535,246 556,252"/></g></svg>';
}

/**
 * 頂列:品牌、身分切換(只給開發者)、回前台、帳號選單。
 * $page    'dev' | 'teacher'(目前在哪個後台)
 * $me      登入者(未登入為 null,只顯示品牌)
 * $opt     ['meUrl' => 我的帳號網址, 'logoutUrl' => 登出網址, 'roleNote' => 角色旁的附註]
 */
function ui_topbar($page, $me, $authed, array $opt = []) {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $o = '<header class="topbar">'
       . '<a class="brand" href="' . ($page === 'dev' ? 'dev.php' : 'teacher.php') . '" aria-label="讀伴後台首頁">' . ui_logo()
       . '<span class="brand-name">讀伴</span><span class="brand-rule" aria-hidden="true"></span><span class="brand-tag">' . ($page === 'dev' ? '管理者後台' : '教師後台') . '</span></a>';
    $o .= '<div class="topbar-mid">';
    if ($authed && $me && auth_is_dev($me)) {
        $o .= '<nav class="seg" aria-label="切換後台">'
            . '<a href="dev.php"' . ($page === 'dev' ? ' class="on" aria-current="page"' : '') . '>管理者</a>'
            . '<a href="teacher.php"' . ($page === 'teacher' ? ' class="on" aria-current="page"' : '') . '>教師</a></nav>';
    }
    $o .= '</div>';
    if ($authed && $me) {
        $name = (string)($me['name'] ?: $me['login']);
        $first = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
        $role = ACC_ROLES[$me['role']] ?? '';
        if (!empty($opt['roleNote'])) $role .= '・' . $opt['roleNote'];
        $o .= '<div class="topbar-end">'
            . '<a class="back-link" href="index.html">‹ 回前台</a>'
            . '<details class="acct"><summary aria-label="帳號選單"><span class="avatar" aria-hidden="true">' . $e($first) . '</span>'
            . '<span class="acct-name">' . $e($name) . '</span>' . ui_icon('chev', 'ic chev') . '</summary>'
            . '<div class="acct-menu"><div class="acct-who"><b>' . $e($name) . '</b><span>' . $e($me['login']) . '・' . $e($role) . '</span></div>'
            . '<a href="' . $e($opt['meUrl'] ?? '#') . '">' . ui_icon('user') . '我的帳號</a>'
            . '<a href="' . $e($opt['logoutUrl'] ?? '?logout=1') . '">' . ui_icon('logout') . '登出</a></div></details>'
            . '</div>';
    }
    return $o . '</header>'
        . '<script>document.addEventListener("click",function(e){document.querySelectorAll("details.acct[open]").forEach(function(d){if(!d.contains(e.target))d.removeAttribute("open")})});'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")document.querySelectorAll("details.acct[open]").forEach(function(d){d.removeAttribute("open");d.querySelector("summary").focus()})});</script>';
}

/** 「萬」為單位的大數字(194.8萬);一萬以下照原樣加千分位 */
function ui_wan($x) {
    $x = (float)$x;
    if ($x < 10000) return number_format($x);
    return rtrim(rtrim(number_format($x / 10000, 1, '.', ''), '0'), '.') . '萬';
}
