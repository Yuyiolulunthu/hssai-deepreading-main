<?php
/**
 * 讀伴 — 免費試用使用紀錄(供管理者後台檢視、偵測濫用)
 *   每位訪客(瀏覽器隨機代號 gid)× 每篇文本(docId)一個檔:data/guest/<gid>__<docId>.json
 *     存:標題、原文(一次)、每次呼叫的模式/人格/讀者提問/讀伴回覆/tokens
 *   另有索引 data/guest/_index.json 只存摘要,後台列表不必讀每個大檔
 *   保留期限與免費解析相同(GA_KEEP_DAYS,預設 30 天),每天清一次;IP 只存雜湊
 *   前端上傳頁已告知「文本與對話會保存 30 天,僅系統管理者可檢視」
 */
require_once __DIR__ . '/stats_lib.php';

define('GUEST_KEEP_SECS', max(1, (int)(getenv('GA_KEEP_DAYS') ?: 30)) * 86400);
const GUEST_MAX_EVENTS = 600;          // 單檔事件上限,避免被灌爆磁碟
/* 免費測試的限制(是測試與體驗,不是無限使用);可用環境變數調整。前端經 site.php 讀取同一組數字 */
define('GUEST_MAX_CHARS', max(1000, (int)(getenv('GUEST_MAX_CHARS') ?: 10000)));   // 每篇字數
define('GUEST_MAX_BYTES', max(1, (int)(getenv('GUEST_MAX_MB') ?: 1)) * 1024 * 1024); // 上傳檔案大小
define('GUEST_MAX_TURNS', max(1, (int)(getenv('GUEST_MAX_TURNS') ?: 50)));          // 每篇學生回應(對話輪數)
define('GUEST_IP_DAILY_TURNS', max(1, (int)(getenv('GUEST_IP_DAILY_TURNS') ?: 300))); // 同一 IP 每天的學生回應總數(最後防線)

/**
 * 這個瀏覽器(gid)在這篇文章已回應幾則。文章以內容指紋(textKey)辨識,不看 docId——
 * 所以登出、重新上傳同一篇都接續計算;換一篇不同的文章才重新開始。
 */
function guest_turns_used(array $req, $textKey) {
    $gid = explode('__', guest_id_from($req))[0];
    $n = 0;
    foreach (guest_index_read() as $r)
        if (($r['gid'] ?? '') === $gid && ($r['textKey'] ?? '') === $textKey) $n += guest_chat_count($r);
    return $n;
}

/* 同一 IP(雜湊)當天的學生回應數:data/guest/_ipturns_YYYYMMDD.json */
function guest_ip_file() { return guest_dir() . '/_ipturns_' . date('Ymd') . '.json'; }
function guest_ip_turns_today() {
    $d = json_decode((string)@file_get_contents(guest_ip_file()), true) ?: [];
    return (int)($d[guest_ip_hash()] ?? 0);
}
function guest_ip_turn_add() {
    $fp = @fopen(guest_ip_file(), 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true) ?: [];
    $h = guest_ip_hash();
    $d[$h] = ($d[$h] ?? 0) + 1;
    json_rewrite($fp, $d);
    flock($fp, LOCK_UN); fclose($fp);
}

function guest_dir() { return __DIR__ . '/data/guest'; }
function guest_clean_id($x) { return substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$x), 0, 40); }
function guest_ip_hash() {
    $ip = $_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'unknown';
    return substr(sha1($ip), 0, 16);   // 與免費解析每日額度用同一種雜湊,可互相對照
}
function guest_cut($s, $n) { return function_exists('mb_substr') ? mb_substr((string)$s, 0, $n) : substr((string)$s, 0, $n * 3); }
function guest_len($s) { return function_exists('mb_strlen') ? mb_strlen((string)$s) : strlen((string)$s); }

/** 由請求取出紀錄 id;沒有 gid 時以 IP 雜湊代替 */
function guest_id_from(array $req) {
    $gid = guest_clean_id($req['gid'] ?? '');
    if ($gid === '') $gid = 'ip' . guest_ip_hash();
    $did = guest_clean_id($req['docId'] ?? '');
    return $gid . '__' . ($did !== '' ? $did : 'nodoc');
}
function guest_path($id) { return guest_dir() . '/' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$id), 0, 90) . '.json'; }

/**
 * 記一筆事件並更新索引。$ev = [mode, persona?, branch?, q?, a?, in, out, cw, cr, status?]
 * 任何錯誤都吞掉,不影響使用者
 */
function guest_log(array $req, $title, $text, array $ev) {
    try {
        stats_ensure_dir();
        if (!is_dir(guest_dir())) @mkdir(guest_dir(), 0755, true);
        guest_cleanup();
        $id = guest_id_from($req);
        $now = time();
        $ev['ts'] = $now;
        foreach (['in', 'out', 'cw', 'cr'] as $k) $ev[$k] = (int)($ev[$k] ?? 0);

        $fp = @fopen(guest_path($id), 'c+');
        if (!$fp) return;
        flock($fp, LOCK_EX);
        $g = json_decode((string)stream_get_contents($fp), true) ?: [];
        if (!$g) {
            $g = ['id' => $id, 'gid' => explode('__', $id)[0], 'ip' => guest_ip_hash(), 'created' => $now,
                  'ua' => guest_cut($_SERVER['HTTP_USER_AGENT'] ?? '', 160), 'counts' => [], 'tok' => ['in' => 0, 'out' => 0, 'cw' => 0, 'cr' => 0], 'events' => []];
        }
        $title = trim((string)$title);
        if ($title !== '') $g['title'] = guest_cut($title, 120);
        if ((string)$text !== '' && empty($g['text'])) {
            $g['text'] = guest_cut($text, 30000);
            $g['chars'] = guest_len($text);
            if (function_exists('ga_key')) $g['textKey'] = ga_key($text);
        }
        $g['ip'] = guest_ip_hash();
        $g['updated'] = $now;
        // 分支與筆記只存在使用者的瀏覽器,由前端隨每次呼叫回報最新數量
        if (is_array($req['gstat'] ?? null)) {
            foreach (['branches', 'notes'] as $k) if (isset($req['gstat'][$k])) $g[$k] = max(0, min(100000, (int)$req['gstat'][$k]));
        }
        $m = (string)$ev['mode'];
        $g['counts'][$m] = ($g['counts'][$m] ?? 0) + 1;
        foreach (['in', 'out', 'cw', 'cr'] as $k) $g['tok'][$k] += $ev[$k];
        if ($m === 'chat') guest_ip_turn_add();   // api.php 只有「讀者有送訊息」才記成 chat(開場是 chat_open)
        if (count($g['events']) < GUEST_MAX_EVENTS) $g['events'][] = $ev;
        else $g['dropped'] = ($g['dropped'] ?? 0) + 1;
        json_rewrite($fp, $g, JSON_UNESCAPED_UNICODE);
        flock($fp, LOCK_UN); fclose($fp);

        $sum = $g; unset($sum['text'], $sum['events'], $sum['ua']);
        guest_index_update(function ($idx) use ($id, $sum) { $idx[$id] = $sum; return $idx; });
    } catch (Throwable $e) { /* 忽略 */ }
}

function guest_index_update(callable $fn) {
    $fp = @fopen(guest_dir() . '/_index.json', 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $idx = json_decode((string)stream_get_contents($fp), true) ?: [];
    $idx = $fn($idx);
    json_rewrite($fp, $idx, JSON_UNESCAPED_UNICODE);
    flock($fp, LOCK_UN); fclose($fp);
}

/** 刪除超過保留期限的紀錄(每天最多執行一次) */
function guest_cleanup() {
    $mark = guest_dir() . '/.cleanup';
    if (is_file($mark) && date('Ymd', filemtime($mark)) === date('Ymd')) return;
    @touch($mark);
    $limit = time() - GUEST_KEEP_SECS;
    $today = basename(guest_ip_file());
    foreach (glob(guest_dir() . '/*.json') ?: [] as $f) {
        $b = basename($f);
        if (strpos($b, '_ipturns_') === 0) { if ($b !== $today) @unlink($f); continue; }
        if ($b !== '_index.json' && filemtime($f) < $limit) @unlink($f);
    }
    guest_index_update(fn($idx) => array_filter($idx, fn($r) => ($r['updated'] ?? 0) >= $limit));
}

/** 後台列表:摘要,依最後活動新到舊 */
function guest_list() {
    $idx = guest_index_read();
    uasort($idx, fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));
    return $idx;
}

/** 索引(未過期的摘要,不排序) */
function guest_index_read() {
    $idx = json_decode((string)@file_get_contents(guest_dir() . '/_index.json'), true) ?: [];
    $limit = time() - GUEST_KEEP_SECS;
    return array_filter($idx, fn($r) => ($r['updated'] ?? 0) >= $limit);
}

function guest_get($id) {
    $p = guest_path($id);
    if (!is_file($p) || filemtime($p) < time() - GUEST_KEEP_SECS) return null;
    $g = json_decode((string)@file_get_contents($p), true);
    return is_array($g) ? $g : null;
}

/** 學生回應(使用者送出的訊息,不含讀伴開場)與其他呼叫次數 */
function guest_chat_count(array $g) { return (int)($g['counts']['chat'] ?? 0); }
function guest_other_count(array $g) { return array_sum($g['counts'] ?? []) - guest_chat_count($g); }
