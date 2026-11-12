<?php
/**
 * 讀伴 — 用量統計函式庫
 * 以 data/stats.json 儲存（flock 檔案鎖，免資料庫）。
 * data/ 目錄會自動建立並附 .htaccess 阻擋外部直接讀取。
 */

/* 全站時區:容器的 PHP 預設是 UTC,畫面上的時間(最後登入、最後活動、稽核)會慢 8 小時,
   每日統計與每日額度也會在早上 8 點才換日。所有頁面都會載入這個檔,在這裡統一設定。 */
date_default_timezone_set('Asia/Taipei');

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

/* JSON 寫檔:不合法的 UTF-8 以 U+FFFD 取代;仍編碼失敗時不寫,保留原檔。
   (原本先清空檔案再寫 json_encode 的結果,編碼失敗時會把整個檔案寫成空的) */
function json_enc($data, $flags = 0) {
    $json = json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) error_log('讀伴 json_encode 失敗,未寫入: ' . json_last_error_msg());
    return $json;
}
/** 已上鎖的檔案:重寫全部內容 */
function json_rewrite($fp, $data, $flags = 0) {
    $json = json_enc($data, $flags);
    if ($json === false) return false;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, $json); fflush($fp);
    return true;
}
/** 整檔寫入(file_put_contents + LOCK_EX) */
function json_save($path, $data, $flags = 0) {
    $json = json_enc($data, $flags);
    return $json !== false && @file_put_contents($path, $json, LOCK_EX) !== false;
}

function stats_path() { return __DIR__ . '/data/stats.json'; }

function stats_ensure_dir() {
    $d = __DIR__ . '/data';
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    $ht = $d . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
}

function stats_default() {
    return [
        'views'   => 0,
        'api'     => ['calls' => 0, 'in' => 0, 'out' => 0],
        'by_mode' => [],
        'by_audience' => ['course' => ['calls' => 0, 'in' => 0, 'out' => 0],
                          'guest'  => ['calls' => 0, 'in' => 0, 'out' => 0]],
        'daily'   => [],
        'since'   => date('Y-m-d'),
    ];
}

/** 以獨佔鎖讀-改-寫 */
function stats_update(callable $fn) {
    stats_ensure_dir();
    $fp = @fopen(stats_path(), 'c+');
    if (!$fp) return;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw = stream_get_contents($fp);
    $s = json_decode($raw, true);
    if (!is_array($s) || !isset($s['api'])) $s = stats_default();
    $s = $fn($s);
    // 每日資料保留最近 90 天
    if (isset($s['daily']) && count($s['daily']) > 90) {
        ksort($s['daily']);
        $s['daily'] = array_slice($s['daily'], -90, null, true);
    }
    json_rewrite($fp, $s, JSON_UNESCAPED_UNICODE);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function stats_day(&$s) {
    $d = date('Y-m-d');
    if (!isset($s['daily'][$d])) $s['daily'][$d] = ['calls' => 0, 'in' => 0, 'out' => 0, 'views' => 0];
    return $d;
}

/**
 * 記錄一次 API 呼叫(token 數來自 Anthropic 回應的 usage 欄位)。
 *   in  = 未快取的輸入;cw = 寫入快取的輸入(1.25 倍價);cr = 讀快取的輸入(0.1 倍價);out = 輸出
 * 舊資料沒有 cw/cr 欄位,讀取時以 0 處理。
 */
function stats_record_api($mode, $in, $out, $audience = 'guest', $cw = 0, $cr = 0) {
    $audience = ($audience === 'course') ? 'course' : 'guest';
    stats_update(function ($s) use ($mode, $in, $out, $audience, $cw, $cr) {
        $add = function (&$b) use ($in, $out, $cw, $cr) {
            $b['calls'] = ($b['calls'] ?? 0) + 1;
            $b['in']    = ($b['in']    ?? 0) + $in;
            $b['out']   = ($b['out']   ?? 0) + $out;
            $b['cw']    = ($b['cw']    ?? 0) + $cw;
            $b['cr']    = ($b['cr']    ?? 0) + $cr;
        };
        $add($s['api']);
        if (!isset($s['by_mode'][$mode])) $s['by_mode'][$mode] = [];
        $add($s['by_mode'][$mode]);
        if (!isset($s['by_audience'][$audience])) $s['by_audience'][$audience] = [];
        $add($s['by_audience'][$audience]);
        $d = stats_day($s);
        $add($s['daily'][$d]);
        return $s;
    });
}

/** 估計花費(USD):未快取輸入照價、快取寫入 1.25 倍、快取讀取 0.1 倍、輸出照價 */
function stats_cost(array $b, $priceIn, $priceOut) {
    return (($b['in'] ?? 0) + 1.25 * ($b['cw'] ?? 0) + 0.1 * ($b['cr'] ?? 0)) / 1e6 * $priceIn
         + ($b['out'] ?? 0) / 1e6 * $priceOut;
}

/** 記錄一次頁面瀏覽 */
function stats_record_view() {
    stats_update(function ($s) {
        $s['views']++;
        $d = stats_day($s);
        $s['daily'][$d]['views']++;
        return $s;
    });
}

/** 讀取統計（共享鎖） */
function stats_read() {
    stats_ensure_dir();
    $p = stats_path();
    if (!file_exists($p)) return stats_default();
    $fp = @fopen($p, 'r');
    if (!$fp) return stats_default();
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $s = json_decode($raw, true);
    return (is_array($s) && isset($s['api'])) ? $s : stats_default();
}
