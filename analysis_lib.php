<?php
/**
 * 讀伴 — 課程文本的階段一解析(閱讀地圖)
 *
 * 流程:教師上傳文本 → 自動於背景解析 → 教師審閱、可編修後儲存
 *       → 儲存的版本作為學生對話的「閱讀地圖」注入 LLM(不直接呈現給學生)
 *
 * 儲存:data/analysis/<courseId>/<textId>.json
 *   status : pending | running | done | error
 *   md     : 解析內容(教師編修後即為審定版)
 *   edited : 教師是否手動編修過
 *   stale  : 文本內容在解析後被修改過(僅在已編修時標記,不自動覆蓋教師的修改)
 *
 * 解析 prompt 一律使用管理者設定(全站),教師無法調整。
 */
require_once __DIR__ . '/school_lib.php';
require_once __DIR__ . '/prompt_lib.php';

function ta_safe($s) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string)$s); }

function ta_path($courseId, $textId) {
    return __DIR__ . '/data/analysis/' . ta_safe($courseId) . '/' . ta_safe($textId) . '.json';
}

function ta_get($courseId, $textId) {
    $p = ta_path($courseId, $textId);
    if (!is_file($p)) return null;
    $d = json_decode((string)@file_get_contents($p), true);
    return is_array($d) ? $d : null;
}

function ta_put($courseId, $textId, array $rec) {
    stats_ensure_dir();
    $dir = dirname(ta_path($courseId, $textId));
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return json_save(ta_path($courseId, $textId), $rec, JSON_UNESCAPED_UNICODE);
}

function ta_delete($courseId, $textId) {
    $p = ta_path($courseId, $textId);
    if (is_file($p)) @unlink($p);
}

/**
 * 引號校正:「」只保留在原文中逐字找得到的字句,找不到的(模型自己的轉述、命題、概念名稱)改成『』。
 * 解析會成為讀伴的閱讀地圖,讀伴把「」內的文字當成原文引給學生——引錯就是誤導學生。
 * 比對時忽略空白與標點;以刪節號省略的引文逐段比對。
 */
function ta_fix_quotes($md, $text) {
    $norm = fn($s) => preg_replace('/[\s\p{P}\p{S}]+/u', '', (string)$s);
    $src = $norm($text);
    return preg_replace_callback('/「([^」\n]{1,300})」/u', function ($m) use ($norm, $src) {
        foreach (preg_split('/…+|⋯+|\.{3,}/u', $m[1]) as $seg) {
            $s = $norm($seg);
            if ($s !== '' && mb_strpos($src, $s) === false) return '『' . $m[1] . '』';
        }
        return $m[0];
    }, $md);
}

/** 取得課程中的單篇文本(含全文) */
function ta_text($courseId, $textId) {
    foreach (school_read()['courses'] as $c) {
        if ($c['id'] !== $courseId) continue;
        foreach (school_norm_texts($c['texts'] ?? []) as $t) {
            if ($t['id'] === $textId) return $t;
        }
    }
    return null;
}

/** 目前生效的解析 prompt(僅管理者可設定;教師不可調整) */
function ta_prompt() {
    $ovr = prompts_overrides();
    return !empty($ovr['analysis']) ? $ovr['analysis'] : prompts_builtin()['analysis'];
}

/** 標記為待解析(文本上傳或內容變更時呼叫) */
function ta_mark_pending($courseId, $textId, $bodyHash) {
    $cur = ta_get($courseId, $textId);
    // 教師已編修過:不自動覆蓋,只標記內容已變更
    if ($cur && !empty($cur['edited']) && ($cur['status'] ?? '') === 'done') {
        if (($cur['bodyHash'] ?? '') !== $bodyHash) {
            $cur['stale'] = true;
            ta_put($courseId, $textId, $cur);
        }
        return;
    }
    if ($cur && ($cur['bodyHash'] ?? '') === $bodyHash && ($cur['status'] ?? '') === 'done') return;
    ta_put($courseId, $textId, [
        'status' => 'pending', 'md' => '', 'edited' => false, 'stale' => false,
        'bodyHash' => $bodyHash, 'ts' => time(),
    ]);
}

/** 解析是否卡住(running 超過 10 分鐘視為中斷,可重跑) */
function ta_is_stuck($rec) {
    return ($rec['status'] ?? '') === 'running' && (time() - ($rec['started'] ?? 0)) > 600;
}

/** 執行解析(同步;由 analyze.php 以背景方式呼叫) */
function ta_run($courseId, $textId) {
    $t = ta_text($courseId, $textId);
    if (!$t || trim((string)$t['text']) === '') {
        ta_put($courseId, $textId, ['status' => 'error', 'error' => '文本沒有內容', 'ts' => time()]);
        return false;
    }
    $bodyHash = md5($t['text']);
    ta_put($courseId, $textId, [
        'status' => 'running', 'md' => '', 'edited' => false, 'stale' => false,
        'bodyHash' => $bodyHash, 'started' => time(), 'ts' => time(),
    ]);
    $sys = ta_prompt() . "\n\n【文本】《{$t['title']}》\n" . DUPAN_PARA_NOTE . "\n---\n" . dupan_number_paras($t['text']) . "\n---";
    // 完整解析約 7,000–10,000 token、需 3–4 分鐘:放寬上限並改用串流(原本 4,000 token 會寫到一半被截斷)
    $r = dupan_llm($sys, '請依系統指示輸出四領域 × 四層的完整解析。', 16000, true);
    if (isset($r['text']) && trim($r['text']) !== '') {
        ta_put($courseId, $textId, [
            'status' => 'done', 'md' => ta_fix_quotes($r['text'], $t['text']), 'edited' => false, 'stale' => false,
            'bodyHash' => $bodyHash, 'ts' => time(), 'truncated' => ($r['stop'] ?? '') === 'max_tokens',
        ]);
        return true;
    }
    ta_put($courseId, $textId, [
        'status' => 'error', 'error' => $r['error'] ?? '解析失敗', 'bodyHash' => $bodyHash, 'ts' => time(),
    ]);
    return false;
}

/**
 * 取得供學生對話使用的閱讀地圖:只有「已完成」的解析才會提供。
 * 回傳 md 字串,沒有則回傳空字串。
 */
function ta_map_for_chat($courseId, $textId) {
    $rec = ta_get($courseId, $textId);
    if (!$rec || ($rec['status'] ?? '') !== 'done') return '';
    $md = trim((string)($rec['md'] ?? ''));
    if ($md === '') return '';
    // 控制長度,避免每輪對話的輸入 token 過度膨脹
    return u_cut($md, 12000);
}

/* ================= 免費試用:使用者自行上傳文本的自動解析 =================
 * 與課程無關的獨立頻道。上傳後與導讀同時在背景解析(guest_analyze.php);
 * 以文本指紋為索引存在伺服器,不送到使用者瀏覽器;讀伴對話時 api.php 依全文指紋查找。
 * 這份地圖是 AI 自動產生、未經教師審定。
 */
const GA_MAX_CHARS = 30000;   // 超過不解析(仍有導讀與對話,只是沒有地圖)
// 每個 IP 每天最多啟動幾次新解析(同一篇文本重複使用不計)。
// 可用環境變數 GA_DAILY_MAX 調高——例如展示場合大家共用同一個對外 IP 時。
define('GA_DAILY_MAX', max(1, (int)(getenv('GA_DAILY_MAX') ?: 10)));
// 解析結果保留期限,預設 30 天;可用環境變數 GA_KEEP_DAYS 調整。只存解析、不存原文
define('GA_KEEP_SECS', max(1, (int)(getenv('GA_KEEP_DAYS') ?: 30)) * 86400);

function ga_dir() { return __DIR__ . '/data/analysis/_guest'; }

/** 與 api.php 收到的 docText 同樣處理(截 COURSE_MAX_CHARS 字、統一換行),確保兩邊算出同一個指紋 */
function ga_norm($text) { return trim(str_replace(["\r\n", "\r"], "\n", u_cut((string)$text, COURSE_MAX_CHARS))); }
function ga_key($text)  { return sha1(ga_norm($text)); }
function ga_path($key)  { return ga_dir() . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.json'; }

function ga_get($key) {
    $p = ga_path($key);
    if (!is_file($p) || time() - filemtime($p) > GA_KEEP_SECS) return null;
    $d = json_decode((string)@file_get_contents($p), true);
    return is_array($d) ? $d : null;
}

function ga_put($key, array $rec) {
    stats_ensure_dir();
    if (!is_dir(ga_dir())) @mkdir(ga_dir(), 0755, true);
    return json_save(ga_path($key), $rec, JSON_UNESCAPED_UNICODE);
}

/** 刪除超過保留期限的解析與用量紀錄 */
function ga_cleanup() {
    foreach (glob(ga_dir() . '/*.json') ?: [] as $f) {
        if (time() - filemtime($f) > GA_KEEP_SECS) @unlink($f);
    }
}

/** 使用者 IP:正式站前面有 nginx 反向代理,真實 IP 在 X-Real-IP */
function ga_client_ip() {
    $ip = $_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/** 登記一次新解析;超過當日上限回傳 false。IP 只存雜湊 */
function ga_take_quota($ip) {
    stats_ensure_dir();
    if (!is_dir(ga_dir())) @mkdir(ga_dir(), 0755, true);
    $fp = @fopen(ga_dir() . '/_quota_' . date('Ymd') . '.json', 'c+');
    if (!$fp) return true;   // 記錄失敗時不擋使用者
    flock($fp, LOCK_EX);
    $q = json_decode((string)stream_get_contents($fp), true) ?: [];
    $h = substr(sha1($ip), 0, 16);
    $ok = ($q[$h] ?? 0) < GA_DAILY_MAX;
    if ($ok) {
        $q[$h] = ($q[$h] ?? 0) + 1;
        json_rewrite($fp, $q);
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

/** 執行解析(同步;由 guest_analyze.php 以背景方式呼叫)。回傳 dupan_llm 的結果(含 in/out tokens) */
function ga_run($text, $title) {
    $body = ga_norm($text);
    $key  = ga_key($text);
    ga_put($key, ['status' => 'running', 'started' => time()]);
    $sys = ta_prompt() . "\n\n【文本】《{$title}》\n" . DUPAN_PARA_NOTE . "\n---\n" . dupan_number_paras($body) . "\n---";
    $r = dupan_llm($sys, '請依系統指示輸出四領域 × 四層的完整解析。', 16000, true);
    if (isset($r['text']) && trim($r['text']) !== '') {
        ga_put($key, ['status' => 'done', 'md' => ta_fix_quotes($r['text'], $body),
                      'truncated' => ($r['stop'] ?? '') === 'max_tokens', 'ts' => time(),
                      'in' => (int)($r['in'] ?? 0), 'out' => (int)($r['out'] ?? 0)]);
        return $r;
    }
    ga_put($key, ['status' => 'error', 'error' => $r['error'] ?? '解析失敗', 'ts' => time()]);
    return $r;
}

/** 供對話使用的地圖:只有已完成的解析才提供;沒有則回傳空字串 */
function ga_map_for_chat($text) {
    $rec = ga_get(ga_key($text));
    if (!$rec || ($rec['status'] ?? '') !== 'done') return '';
    $md = trim((string)($rec['md'] ?? ''));
    return $md === '' ? '' : u_cut($md, 12000);
}
