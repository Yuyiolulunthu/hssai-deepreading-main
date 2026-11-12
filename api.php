<?php
/**
 * 讀伴・樹狀閱讀 — 後端 API 代理
 * 將前端請求轉發至 Anthropic API,並套用「防護層」系統提示。
 *
 * 模式：
 *   chat            — 讀伴對話（主幹或分支）
 *   branch_summary  — 將分支對話收束成 2–3 句摘要，帶回主幹
 *   doc_summary     — 依讀者的對話與筆記，整理閱讀歷程回顧（仍不代寫心得）
 */

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['error' => '尚未設定：請將 config.sample.php 複製為 config.php 並填入 API Key。'], JSON_UNESCAPED_UNICODE);
    exit;
}
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '僅接受 POST 請求。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!$req) {
    http_response_code(400);
    echo json_encode(['error' => '請求格式錯誤。'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** UTF-8 安全截字（不依賴 mbstring 擴充） */
if (!function_exists('u_cut')) {
    function u_cut($s, $len) {
        $s = (string)$s;
        $len = (int)$len;
        if (function_exists('mb_substr')) return mb_substr($s, 0, $len);
        // PCRE 量詞上限為 65535,超過時分段處理避免編譯失敗
        if ($len <= 60000 && preg_match('/^.{0,' . $len . '}/us', $s, $m)) return $m[0];
        // 逐字元計數的備援(不依賴 mbstring 與 PCRE 量詞)
        $out = ''; $n = 0; $i = 0; $blen = strlen($s);
        while ($i < $blen && $n < $len) {
            $c = ord($s[$i]);
            $step = $c < 0x80 ? 1 : ($c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4));
            $out .= substr($s, $i, $step);
            $i += $step; $n++;
        }
        return $out;
    }
}

$mode     = $req['mode'] ?? 'chat';
$docTitle = u_cut(trim($req['docTitle'] ?? ''), 120);
require_once __DIR__ . '/school_lib.php';
$docText  = u_cut($req['docText'] ?? '', COURSE_MAX_CHARS);   // 教師指定文本上限見 school_lib(教師上傳時已檢查)
// 免費測試(使用者自行上傳、非教師指定的文本):字數與對話輪數有上限(guest_lib);前端已擋,這裡再保險一次
require_once __DIR__ . '/guest_lib.php';
$isFree = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($req['courseTextId'] ?? '')) === '';
if ($isFree) $docText = u_cut($docText, GUEST_MAX_CHARS);
$messages = $req['messages'] ?? [];
/* 讀者這次送出的訊息(沒有就是讀伴開場);用於免費測試的輪數上限與使用紀錄 */
$lastQ = '';
if ($mode === 'chat' && is_array($messages) && $messages) {
    $lm = end($messages);
    if (is_array($lm) && ($lm['role'] ?? '') === 'user' && is_string($lm['content'] ?? null)) $lastQ = guest_cut($lm['content'], 2000);
}
if ($isFree && $lastQ !== '' && empty($req['dry_run'])) {
    require_once __DIR__ . '/analysis_lib.php';   // ga_key:文章內容指紋
    $lim = null;
    if (guest_ip_turns_today() >= GUEST_IP_DAILY_TURNS)
        $lim = ['ip', '這個網路今天的免費測試對話量已達上限（每天 ' . GUEST_IP_DAILY_TURNS . ' 輪），請明天再試。'];
    elseif (guest_turns_used($req, ga_key($docText)) >= GUEST_MAX_TURNS)
        $lim = ['turns', '免費測試每篇文章最多 ' . GUEST_MAX_TURNS . ' 輪對話，這篇已經用完了（重新上傳同一篇也會接續計算）。可以先「匯出筆記」保存紀錄，或換一篇文章再試。'];
    if ($lim) {
        http_response_code(429);
        echo json_encode(['error' => $lim[1], 'limit' => $lim[0]], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
$branchQuote = u_cut($req['branchQuote'] ?? '', 600);
$nickname = u_cut(trim($req['nickname'] ?? ''), 20);
$isBranch = !empty($req['isBranch']);
$persona  = $req['persona'] ?? 'guide';
if ($persona === 'ta') $persona = 'explorer';   // 舊版相容

/* ---------- 內建 prompt 與後台覆寫(需在人格區塊之前載入) ---------- */
require_once __DIR__ . '/prompt_lib.php';
$B   = prompts_builtin();
$OVR = prompts_overrides();
$guard = !empty($OVR['guard']) ? $OVR['guard'] : $B['guard'];

/* ---------- 人格區塊 ---------- */

$personaBlocks = $B['personas'];
foreach (['guide', 'critic', 'explorer'] as $pk) {
    if (!empty($OVR['persona_' . $pk])) $personaBlocks[$pk] = "\n\n" . $OVR['persona_' . $pk];
}
$personaBlock = $personaBlocks[$persona] ?? $personaBlocks['guide'];

/* ---------- 組裝 system prompt ---------- */

$frame = "\n\n【目前共讀的文本】\n標題：《{$docTitle}》\n" . DUPAN_PARA_NOTE . "\n---\n" . dupan_number_paras($docText) . "\n---";

/* 分段共讀:讀者用書籤標出這次讀完的範圍(段落索引 seg.start..seg.end)。對話只送這一段原文(編號沿用全文),
   長文本(5–8 萬字)不必每輪送全文;閱讀地圖仍是全文的,只用來定位 */
$seg = null;
if (isset($req['seg']) && is_array($req['seg'])) {
    $s0 = (int)($req['seg']['start'] ?? -1); $s1 = (int)($req['seg']['end'] ?? -1);
    if ($s0 >= 0 && $s1 >= $s0) $seg = [$s0, $s1];
}
$segLabel = $seg ? ($seg[0] === $seg[1] ? '第 ' . ($seg[0] + 1) . ' 段' : '第 ' . ($seg[0] + 1) . '–' . ($seg[1] + 1) . ' 段') : '';
if ($seg && $mode === 'chat') {
    $frame = "\n\n【目前共讀的文本】\n標題：《{$docTitle}》\n" . DUPAN_PARA_NOTE . "\n"
           . "讀者是分段閱讀這篇長文本。這次讀完、要對話的是全文的{$segLabel}；下面只列出這一段，段落編號沿用全文。"
           . "對話只談這一段：引用、提問與討論都以這一段為範圍；閱讀地圖涵蓋全文，只用來定位這一段在全文中的位置，"
           . "不要談到讀者還沒讀的後面段落，也不要假設讀者記得前面段落的細節。\n---\n"
           . dupan_number_paras($docText, $seg[0], $seg[1]) . "\n---";
}

$systemTail = ''; $systemBlocks = null;
if ($mode === 'chat') {
    /* 閱讀地圖:地圖怎麼用,由總則「內部解析與對話的關係:連結,而非傳達」與各模式模組規定,這裡只標示內容 */
    $mapWrap = fn($source, $md) => "\n\n【階段一解析(閱讀地圖,{$source})】\n"
        . "以下即總則所說的解析地圖,依總則「內部解析與對話的關係:連結,而非傳達」與目前模式的規定使用;"
        . "它只供你內部定位與設計回應,不得向讀者誦出、引述或暗示其內容。\n---\n" . $md . "\n---";
    /* 課程學生閱讀教師指定文本:帶入教師審定的階段一解析 */
    $mapBlock = '';
    $stu = $req['student'] ?? null;
    $ctid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($req['courseTextId'] ?? ''));
    if (is_array($stu) && !empty($stu['courseId']) && !empty($stu['sid']) && $ctid !== '') {
        require_once __DIR__ . '/analysis_lib.php';
        // 僅限登入者本人、且仍在該課程名單中的學號
        require_once __DIR__ . '/stu_lib.php';
        $inCourse = false;
        if (stu_owns((string)$stu['courseId'], (string)$stu['sid'])) {
            foreach (school_read()['courses'] as $cc) {
                if ($cc['id'] === $stu['courseId'] && isset($cc['students'][$stu['sid']])) { $inCourse = true; break; }
            }
        }
        $map = $inCourse ? ta_map_for_chat($stu['courseId'], $ctid) : '';
        if ($map !== '') $mapBlock = $mapWrap('已經授課教師審定', $map);
    }
    /* 免費試用(使用者自行上傳、非教師指定的文本):依全文指紋查自動解析——AI 產生、未經教師審定 */
    if ($mapBlock === '' && $ctid === '') {
        require_once __DIR__ . '/analysis_lib.php';
        $gmap = ga_map_for_chat($docText);
        if ($gmap !== '') $mapBlock = $mapWrap('由系統自動產生,未經教師審定', $gmap);
    }
    if (!empty($OVR['full'])) {
        /* 整站 System Prompt 完全覆寫(最高優先);可用 {{map}} 指定閱讀地圖的位置 */
        $personaNames = ['guide' => '引導者', 'critic' => '批判者', 'explorer' => '探索者'];
        $system = strtr($OVR['full'], [
            '{{title}}'    => $docTitle,
            '{{text}}'     => $docText,
            '{{persona}}'  => $personaNames[$persona] ?? '引導者',
            '{{branch}}'   => $isBranch ? $branchQuote : '',
            '{{nickname}}' => $nickname,
            '{{map}}'      => $mapBlock,
        ]);
        if ($nickname !== '' && strpos($OVR['full'], '{{nickname}}') === false) {
            $system .= "\n\n【讀者稱呼】這位讀者希望你稱他為「{$nickname}」,可在合適時機自然使用。";
        }
        if (strpos($OVR['full'], '{{map}}') === false) $system .= $mapBlock;
        if (strpos($OVR['full'], '{{text}}') === false) $system .= $frame;
        if ($isBranch && $branchQuote !== '' && strpos($OVR['full'], '{{branch}}') === false) {
            $systemTail .= "\n\n【分支對話】目前為節外生枝的分支，起點：「{$branchQuote}」。";
        }
    } else {
        $classics = !empty($OVR['classics']) ? $OVR['classics'] : $B['classics'];
        $nickBlock = $nickname !== ''
            ? "\n\n【讀者稱呼】這位讀者希望你稱他為「{$nickname}」。在合適的時機自然地使用這個稱呼(例如回應開頭、或想特別對他說話時),不必每則訊息都喊,以免顯得刻意。"
            : '';
        $system = $guard . $personaBlock . "\n\n" . $classics . $nickBlock . $mapBlock . $frame;
        if ($isBranch) {
            $systemTail .= "\n\n【分支對話】這是一條從主幹「節外生枝」的分支，聚焦於單一疑問或片段";
            if ($branchQuote !== '') {
                $systemTail .= ",起點是讀者圈選的這段文字：「{$branchQuote}」";
            }
            $systemTail .= "。請讓討論收攏在這個焦點上，挖得深而不是鋪得廣；若讀者岔題太遠，輕輕把他帶回這條枝上。";
        }
    }
    if ($seg) {
        $cutList = function ($arr, $n, $len) { $o = []; foreach (array_slice(is_array($arr) ? $arr : [], -$n) as $x) { if (is_string($x) && trim($x) !== '') $o[] = u_cut(trim($x), $len); } return $o; };
        $prev = [];
        foreach (array_slice(is_array($req['prevSummaries'] ?? null) ? $req['prevSummaries'] : [], -10) as $p) {
            if (is_array($p) && trim((string)($p['text'] ?? '')) !== '') $prev[] = '- ' . u_cut((string)($p['range'] ?? ''), 20) . '：' . u_cut(trim((string)$p['text']), 300);
        }
        if ($prev) $systemTail .= "\n\n【讀者已經對話過的其他段落（摘要，依段落順序）】讀者先前已就以下段落與讀伴對話過（可能跳著讀，不一定都在這一段之前）。可以自然地接續這些想法，但不要重複已經談過的問題，也不要替讀者重述：\n" . implode("\n", $prev);
        $notes = $cutList($req['segNotes'] ?? [], 20, 400);
        $marks = $cutList($req['segMarks'] ?? [], 20, 200);
        if ($notes || $marks) {
            $systemTail .= "\n\n【讀者在這一段留下的筆記與重點畫記】這些是讀者閱讀時自己標出、寫下的，代表他注意到的地方。可在適當時候回應或從這裡切入，但不要逐條朗讀：";
            if ($notes) $systemTail .= "\n筆記：\n- " . implode("\n- ", $notes);
            if ($marks) $systemTail .= "\n重點畫記：\n- 「" . implode("」\n- 「", $marks) . "」";
        }
    }
    /* 對話的第一則回應:在 system 最後重申開場選項規則(規則原文在總則中段,容易被長 prompt 稀釋) */
    $roles = array_column(array_filter($messages, 'is_array'), 'role');
    if (!$isBranch && !in_array('assistant', $roles, true) && count(array_keys($roles, 'user', true)) <= 1) {
        $systemTail .= "\n\n【這是本次對話的第一則回應】請依總則「開場與選項式提問」開場,"
                     . "結尾提供三個編號選項(每個選項單獨成行、不超過 25 字),第 3 項固定為「我想自己提問」。";
    }
    /* prompt caching:總則＋人格＋經典＋地圖＋全文這一大段每輪都一樣,標成快取前綴;
       5 分鐘內的下一輪讀快取只收 1/10 的價格(8 萬字文本每輪從 ~$0.4 降到 ~$0.05)。
       會變的尾巴(分支說明、第一則提醒)放在後面,不影響前綴命中。 */
    $systemBlocks = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];
    if ($systemTail !== '') $systemBlocks[] = ['type' => 'text', 'text' => $systemTail];
    $system .= $systemTail;   // 預覽與舊路徑仍用完整字串
    $maxTokens = MAX_TOKENS;
} elseif ($mode === 'branch_summary') {
    $system = "你是「讀伴」。以下是一段從主幹對話「節外生枝」出來的分支討論。請用繁體中文，以 2–3 句話（80 字以內）摘要這條分支「討論了什麼、讀者形成了什麼想法或還留著什麼疑問」。摘要以讀者為主詞（例如「讀者認為⋯」「這條分支釐清了⋯」),不添加分支中沒有的結論，不加開頭問候與結尾提問，只輸出摘要本身。" . $frame;
    $maxTokens = 300;
} elseif ($mode === 'segment_summary') {
    $system = "你是「讀伴」。以下是讀者就《{$docTitle}》" . ($segLabel ?: '這篇文本') . "與讀伴的對話。"
            . "請用繁體中文，以 2–3 句話（100 字以內）摘要這段對話「談了什麼、讀者形成了什麼想法、還留著什麼疑問」。"
            . "摘要以讀者為主詞（例如「讀者認為⋯」「讀者還在想⋯」），不添加對話中沒有的結論，不加開頭問候與結尾提問，只輸出摘要本身。";
    $maxTokens = 300;
} elseif ($mode === 'doc_summary') {
    // kind=note → 閱讀筆記生成(閱讀回顧);kind=summary → AI 摘要生成(Word 匯出)
    $kind = (($req['kind'] ?? 'summary') === 'note') ? 'note_gen' : 'summary_gen';
    $system = (!empty($OVR[$kind]) ? $OVR[$kind] : $B[$kind]) . $frame;
    $maxTokens = $kind === 'note_gen' ? 1400 : 1200;
} elseif ($mode === 'briefing') {
    /* 免費試用的導讀也參考閱讀地圖(guest_analyze 已解析完成時);前端會等解析完成再來要導讀,
       解析失敗或超過額度時才在沒有地圖的情況下生成(那種結果不進共用快取,之後有地圖會重新生成) */
    $mapBlock = '';
    if ($isFree) {
        require_once __DIR__ . '/analysis_lib.php';
        $gmap = ga_map_for_chat($docText);
        if ($gmap !== '') {
            $mapBlock = "\n\n【閱讀地圖(內部參考,系統自動產生、尚未經教師審定)】\n以下是系統自動產生的文本解析,尚未經教師審定。請用它輔助確認摘要與背景,但以文本本身為準;"
                      . "但導讀只做初淺介紹:不要揭露其中的詮釋結論、層次判斷或提問設計,把發現留給讀者。"
                      . "閱讀地圖不是文本的一部分,導讀裡不得提到它,也不得介紹它用的分析方法或框架。\n---\n" . $gmap . "\n---";
        }
    }
    $briefHasMap = $mapBlock !== '';
    $system = (!empty($OVR['briefing']) ? $OVR['briefing'] : $B['briefing']) . $mapBlock . $frame;
    $maxTokens = 3500;   // 摘要與背景各約 500 字,加上心智圖
} else {
    http_response_code(400);
    echo json_encode(['error' => '未知的模式。'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 整理訊息（僅保留 user / assistant,並限制長度） ---------- */

$clean = [];
foreach ($messages as $m) {
    $role = $m['role'] ?? '';
    if ($role !== 'user' && $role !== 'assistant') continue;
    $content = u_cut((string)($m['content'] ?? ''), 4000);
    if ($content === '') continue;
    $clean[] = ['role' => $role, 'content' => $content];
}
// 只保留最近 30 則，避免超出脈絡
$clean = array_slice($clean, -30);
if (empty($clean)) {
    $clean[] = ['role' => 'user', 'content' => '（讀者剛讀完這篇文本，還沒有發言。請依總則「開場與選項式提問」開場。）'];
}
// Anthropic 要求第一則為 user
if ($clean[0]['role'] !== 'user') {
    array_unshift($clean, ['role' => 'user', 'content' => '（接續先前的共讀。)']);
}

// 合併連續同角色訊息，避免角色交替錯誤
$mergedList = [];
foreach ($clean as $m) {
    $last = count($mergedList) - 1;
    if ($last >= 0 && $mergedList[$last]['role'] === $m['role']) {
        $mergedList[$last]['content'] .= "\n\n" . $m['content'];
    } else {
        $mergedList[] = $m;
    }
}
$clean = $mergedList;

if ($mode === 'chat') {
    // 對話不能以 assistant 結尾（例如從讀伴訊息節外生枝、分支剛開啟時）
    if ($clean[count($clean) - 1]['role'] === 'assistant') {
        $clean[] = ['role' => 'user', 'content' => '（讀者剛以上面這段為起點展開這條討論。請以簡短的一段話聚焦這個起點開場，並以一個問題作結。)'];
    }
} else {
    // 總結模式：把整段對話攤平成一則 user 訊息，永遠以 user 結尾
    $transcript = '';
    foreach ($clean as $m) {
        $transcript .= ($m['role'] === 'user' ? '【讀者】' : '【讀伴】') . "\n" . $m['content'] . "\n\n";
    }
    $clean = [[
        'role'    => 'user',
        'content' => "以下是完整的對話紀錄：\n\n" . $transcript . "———\n請依系統指示輸出。",
    ]];
}

/* ---------- 管理者預覽(不呼叫 API) ---------- */
if (!empty($req['dry_run'])) {
    require_once __DIR__ . '/account_lib.php';
    auth_session_start();
    $devUser = auth_user();
    session_write_close();
    if (!auth_is_dev($devUser) || !empty($devUser['mustChange'])) {
        http_response_code(403);
        echo json_encode(['error' => '僅限管理者'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'mode'      => $mode,
        'system'    => $system,
        'chars'     => function_exists('mb_strlen') ? mb_strlen($system) : strlen($system),
        'overrides' => array_keys(array_filter($OVR)),
        'map'       => isset($mapBlock) && $mapBlock !== '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- 免費測試的導讀:同一篇文章(內容指紋)已經生成過就直接用 ---------- */
if ($mode === 'briefing' && $isFree && empty($req['dry_run'])) {
    require_once __DIR__ . '/briefing_lib.php';
    $bkey = ga_key($docText);
    if (($cached = bf_guest_get($bkey)) !== null) { echo json_encode(['reply' => $cached], JSON_UNESCAPED_UNICODE); exit; }
}

/* ---------- 呼叫 Anthropic API ---------- */

$payload = json_encode([
    'model'      => ANTHROPIC_MODEL,
    'max_tokens' => $maxTokens,
    'system'     => $systemBlocks ?: $system,
    'messages'   => $clean,
] + dupan_model_extra(ANTHROPIC_MODEL), JSON_UNESCAPED_UNICODE);   // 5.x 模型:關閉預先思考(見 prompt_lib)

$apiUrl  = 'https://api.anthropic.com/v1/messages';
$headers = [
    'Content-Type: application/json',
    'x-api-key: ' . ANTHROPIC_API_KEY,
    'anthropic-version: 2023-06-01',
];

$resp = false; $err = ''; $code = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    // 備援：主機未啟用 curl 擴充時，改用 stream
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $payload,
            'timeout'       => 90,
            'ignore_errors' => true,   // 讓 4xx/5xx 也回傳內容
        ],
    ]);
    $resp = @file_get_contents($apiUrl, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    if ($resp === false) $err = error_get_last()['message'] ?? '連線失敗';
}

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['error' => '無法連線至 Anthropic API:' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($resp, true);
if ($code >= 400 || !isset($data['content'])) {
    $msg = $data['error']['message'] ?? ('API 回傳錯誤（HTTP ' . $code . ')');
    http_response_code(502);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = '';
foreach ($data['content'] as $block) {
    if (($block['type'] ?? '') === 'text') $text .= $block['text'];
}

/* 總則禁止 Markdown，但模型偶爾仍會用 **粗體** 強調（多半發生在引用原文時），在輸出端補一道 */
$text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text);

/* ---------- 用量統計（失敗不影響回覆） ---------- */
try {
    require_once __DIR__ . '/stats_lib.php';
    $usage = $data['usage'] ?? [];
    $inTok  = (int)($usage['input_tokens'] ?? 0);              // 未快取的輸入
    $outTok = (int)($usage['output_tokens'] ?? 0);
    $cwTok  = (int)($usage['cache_creation_input_tokens'] ?? 0); // 寫入快取(1.25 倍價)
    $crTok  = (int)($usage['cache_read_input_tokens'] ?? 0);     // 讀快取(0.1 倍價)
    $aud = (isset($req['audience']) && $req['audience'] === 'course') ? 'course' : 'guest';
    if (is_array($req['student'] ?? null) && !empty($req['student']['courseId'])) $aud = 'course';
    stats_record_api($mode, $inTok, $outTok, $aud, $cwTok, $crTok);
    // 免費測試:記下文本、對話與用量,供管理者後台檢視是否被濫用(以「非教師指定文本」判斷,不依賴前端的 audience)
    if ($isFree) {
        require_once __DIR__ . '/analysis_lib.php';
        // 沒有讀者訊息的 chat 是讀伴的開場白,另外歸類,「對話次數」才等於讀者實際送出的訊息數
        $gMode = ($mode === 'chat' && $lastQ === '') ? 'chat_open' : $mode;
        guest_log($req, $docTitle, $docText, ['mode' => $gMode, 'persona' => $mode === 'chat' ? $persona : null,
            'branch' => $mode === 'chat' && $isBranch ? guest_cut($branchQuote, 200) : null,
            'q' => $lastQ, 'a' => guest_cut(trim($text), 4000),
            'in' => $inTok, 'out' => $outTok, 'cw' => $cwTok, 'cr' => $crTok]);
    }
    // 課程學生活動
    $stu = $req['student'] ?? null;
    if (is_array($stu) && !empty($stu['courseId']) && !empty($stu['sid'])) {
        require_once __DIR__ . '/stu_lib.php';
        if (stu_owns((string)$stu['courseId'], (string)$stu['sid']))   // 只記登入者本人的活動,不能代別人灌數字
            school_record_activity((string)$stu['courseId'], (string)$stu['sid'], $mode, $docTitle, $inTok, $outTok);
    }
} catch (Throwable $e) { /* 忽略 */ }

if (isset($bkey) && !empty($briefHasMap) && bf_parse($text)) bf_guest_put($bkey, trim($text));   // 有閱讀地圖且格式完整才共用
echo json_encode(['reply' => trim($text)], JSON_UNESCAPED_UNICODE);
