<?php
/**
 * 讀伴 — 課程文本的導讀:每篇只生成一份,全班看同一份
 *   存於 data/briefings/<courseId>/<textId>.json
 *     summary / background / mindmap   導讀內容(學生看到的)
 *     bodyHash                         生成或修改時的文本指紋(文本改了就算過期)
 *     mapTs                            生成時所依據的「已審定解析」時間(解析重新審定後就算過期)
 *     edited / editedBy                教師直接修改過(之後不自動覆蓋,只提示過期)
 *     gen / genError / genErrorTs      背景生成中的時間戳記、上次失敗的原因與時間
 *     provisional                      依「尚未審定」的 AI 解析生成的暫用版
 *   生成時參考解析:已審定就用審定版;還沒審定就先用 AI 解析生成暫用版(避免老師忘了審定而開空窗),
 *   老師審定後自動換成正式版。重新生成期間仍提供舊版,學生不會看到空白。
 */
require_once __DIR__ . '/analysis_lib.php';
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';   // 學生端觸發生成時也要有 API 金鑰

const BF_GEN_TIMEOUT = 300;   // 生成超過 5 分鐘視為中斷,可重來
const BF_RETRY_AFTER = 120;   // 失敗後 2 分鐘內不自動重試

function bf_path($cid, $tid) { return __DIR__ . '/data/briefings/' . ta_safe($cid) . '/' . ta_safe($tid) . '.json'; }

function bf_get($cid, $tid) {
    $d = json_decode((string)@file_get_contents(bf_path($cid, $tid)), true);
    return is_array($d) ? $d : null;
}

/** 在檔案鎖內讀出、修改、寫回;$fn 回傳 false 表示不寫 */
function bf_update($cid, $tid, callable $fn) {
    stats_ensure_dir();
    $p = bf_path($cid, $tid);
    if (!is_dir(dirname($p))) @mkdir(dirname($p), 0755, true);
    $fp = @fopen($p, 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $rec = json_decode((string)stream_get_contents($fp), true) ?: [];
    $new = $fn($rec);
    if (is_array($new)) json_rewrite($fp, $new, JSON_UNESCAPED_UNICODE);
    flock($fp, LOCK_UN); fclose($fp);
    return is_array($new) ? $new : $rec;
}

function bf_delete($cid, $tid) { $p = bf_path($cid, $tid); if (is_file($p)) @unlink($p); }

/**
 * 生成導讀要參考的解析:已審定(且文本之後沒改過)→ 審定版;否則用已完成的 AI 解析(暫用);都沒有 → null
 * 回傳 ['md', 'ts', 'approved']
 */
function bf_source_map($cid, $tid) {
    $ar = ta_get($cid, $tid);
    if (!$ar || ($ar['status'] ?? '') !== 'done' || trim((string)($ar['md'] ?? '')) === '') return null;
    if (!empty($ar['edited']) && !empty($ar['stale'])) return null;   // 教師審定後文本改了:等重新審定或重新解析
    return ['md' => (string)$ar['md'], 'ts' => (int)($ar['ts'] ?? 0), 'approved' => !empty($ar['edited'])];
}

/**
 * 目前狀態:
 *   state   done(有內容,可能過期)| running(生成中)| waiting(還沒有可用的解析)| error | notext
 *   regen   是否該在背景(重新)生成——沒有導讀,或過期且教師沒改過
 *   outdated 文本或解析在導讀之後有變,或暫用版之後解析已審定
 */
function bf_state($cid, $tid) {
    $tx = ta_text($cid, $tid);
    if (!$tx || trim((string)$tx['text']) === '') return ['state' => 'notext', 'rec' => null, 'regen' => false, 'outdated' => false];
    $ar  = bf_source_map($cid, $tid);
    $rec = bf_get($cid, $tid) ?: [];
    $has = trim((string)($rec['summary'] ?? '')) !== '';
    $running = !empty($rec['gen']) && time() - (int)$rec['gen'] < BF_GEN_TIMEOUT;
    $outdated = $has && (($rec['bodyHash'] ?? '') !== md5($tx['text'])
        || ($ar && ((int)($rec['mapTs'] ?? 0) < $ar['ts'] || (!empty($rec['provisional']) && $ar['approved']))));
    $cooling = !empty($rec['genErrorTs']) && time() - (int)$rec['genErrorTs'] < BF_RETRY_AFTER;
    $regen = $ar && !$running && !$cooling && (!$has || ($outdated && empty($rec['edited'])));
    $state = $has ? 'done' : ($running || $regen ? 'running' : (!$ar ? 'waiting' : 'error'));
    return ['state' => $state, 'rec' => $rec, 'regen' => $regen, 'outdated' => $outdated];
}

/** 取得生成權(避免全班同時打開時重複生成):成功回傳 true */
function bf_claim($cid, $tid) {
    $got = false;
    bf_update($cid, $tid, function ($rec) use (&$got) {
        if (!empty($rec['gen']) && time() - (int)$rec['gen'] < BF_GEN_TIMEOUT) return false;
        $got = true;
        $rec['gen'] = time();
        return $rec;
    });
    return $got;
}

/** 整理模型回傳或教師輸入的心智圖,只留合法欄位 */
function bf_clean_mindmap($m) {
    if (!is_array($m)) return null;
    $out = ['center' => u_cut(trim((string)($m['center'] ?? '')), 20), 'branches' => []];
    foreach (array_slice(is_array($m['branches'] ?? null) ? $m['branches'] : [], 0, 8) as $b) {
        if (!is_array($b) || trim((string)($b['label'] ?? '')) === '') continue;
        $kids = [];
        foreach (array_slice(is_array($b['children'] ?? null) ? $b['children'] : [], 0, 8) as $k)
            if (is_scalar($k) && trim((string)$k) !== '') $kids[] = u_cut(trim((string)$k), 30);
        $out['branches'][] = ['label' => u_cut(trim((string)$b['label']), 20), 'children' => $kids];
    }
    return ($out['center'] !== '' || $out['branches']) ? $out : null;
}

/** 解析模型輸出的導讀 JSON;不完整回傳 null */
function bf_parse($reply) {
    $j = json_decode(trim(preg_replace('/```(?:json)?/u', '', (string)$reply)), true);
    if (!is_array($j) || trim((string)($j['summary'] ?? '')) === '' || trim((string)($j['background'] ?? '')) === '') return null;
    return ['summary' => u_cut(trim((string)$j['summary']), 800), 'background' => u_cut(trim((string)$j['background']), 800),
            'mindmap' => bf_clean_mindmap($j['mindmap'] ?? null)];
}

/** 心智圖 ⇄ 教師編輯用的大綱文字(第一行是中心;「- 」開頭是面向;縮排的「- 」是細項) */
function bf_mindmap_to_text($m) {
    if (!is_array($m)) return '';
    $lines = [(string)($m['center'] ?? '')];
    foreach ($m['branches'] ?? [] as $b) {
        $lines[] = '- ' . $b['label'];
        foreach ($b['children'] ?? [] as $k) $lines[] = '  - ' . $k;
    }
    return implode("\n", $lines);
}
function bf_text_to_mindmap($text) {
    $m = ['center' => '', 'branches' => []];
    foreach (explode("\n", str_replace("\r\n", "\n", (string)$text)) as $ln) {
        if (trim($ln) === '') continue;
        if (preg_match('/^\s+[-*•]\s*(.+)$/u', $ln, $x)) {
            if ($m['branches']) $m['branches'][count($m['branches']) - 1]['children'][] = trim($x[1]);
        } elseif (preg_match('/^[-*•]\s*(.+)$/u', $ln, $x)) {
            $m['branches'][] = ['label' => trim($x[1]), 'children' => []];
        } elseif ($m['center'] === '') {
            $m['center'] = trim($ln);
        }
    }
    return bf_clean_mindmap($m);
}

/** 生成導讀(同步;呼叫前先 bf_claim)。參考審定的解析,還沒審定就用 AI 解析生成暫用版 */
function bf_run($cid, $tid) {
    $tx = ta_text($cid, $tid);
    $ar = bf_source_map($cid, $tid);
    $fail = function ($why) use ($cid, $tid) {
        bf_update($cid, $tid, function ($rec) use ($why) { unset($rec['gen']); $rec['genError'] = $why; $rec['genErrorTs'] = time(); return $rec; });
        return false;
    };
    if (!$tx || trim((string)$tx['text']) === '') return $fail('文本沒有內容');
    if (!$ar) return $fail('解析尚未完成');
    $ovr = prompts_overrides();
    $sys = (!empty($ovr['briefing']) ? $ovr['briefing'] : prompts_builtin()['briefing'])
         . ($ar['approved']
            ? "\n\n【授課教師審定的閱讀地圖(內部參考)】\n以下是授課教師審定的文本解析。請用它確認摘要與背景的事實正確、介紹的重點方向與教師一致;"
            : "\n\n【閱讀地圖(內部參考,系統自動產生、尚未經教師審定)】\n以下是系統自動產生的文本解析,尚未經教師審定。請用它輔助確認摘要與背景,但以文本本身為準;")
         . "但導讀只做初淺介紹:不要揭露其中的詮釋結論、層次判斷或提問設計,把發現留給學生。"
         . "閱讀地圖不是文本的一部分,導讀裡不得提到它,也不得介紹它用的分析方法或框架。\n---\n"
         . u_cut(trim($ar['md']), 12000) . "\n---"
         . "\n\n【目前共讀的文本】\n標題:《{$tx['title']}》\n---\n{$tx['text']}\n---";
    $r = dupan_llm($sys, '請為這篇文本生成導讀 JSON。', 4000);
    $b = isset($r['text']) ? bf_parse($r['text']) : null;
    if (!$b) return $fail($r['error'] ?? '導讀格式不完整');
    $hash = md5($tx['text']); $mapTs = $ar['ts']; $prov = !$ar['approved'];
    bf_update($cid, $tid, function ($rec) use ($b, $hash, $mapTs, $prov) {
        // 生成期間教師剛好存了修改:保留教師的版本
        if (!empty($rec['edited']) && (int)($rec['ts'] ?? 0) > (int)($rec['gen'] ?? 0)) { unset($rec['gen']); return $rec; }
        return $b + ['bodyHash' => $hash, 'mapTs' => $mapTs, 'provisional' => $prov, 'edited' => false, 'ts' => time()];
    });
    return true;
}

/** 先回應瀏覽器,再在伺服器端繼續生成(與 analyze.php 相同的做法) */
function bf_respond_then_run($cid, $tid, array $resp) {
    ignore_user_abort(true);
    @set_time_limit(BF_GEN_TIMEOUT);
    $body = json_encode($resp, JSON_UNESCAPED_UNICODE);
    while (ob_get_level() > 0) ob_end_clean();
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    bf_run($cid, $tid);
    exit;
}

/** 給學生端的導讀內容 */
function bf_public($rec) {
    return ['summary' => $rec['summary'] ?? '', 'background' => $rec['background'] ?? '', 'mindmap' => $rec['mindmap'] ?? null,
            'v' => (int)($rec['ts'] ?? 0)];
}

/* ============ 免費測試:同一篇文章(內容指紋)共用一份導讀 ============ */
function bf_guest_path($key) { return __DIR__ . '/data/briefings/_guest/' . preg_replace('/[^a-f0-9]/', '', $key) . '.json'; }
function bf_guest_get($key) {
    $p = bf_guest_path($key);
    if (!is_file($p) || time() - filemtime($p) > GA_KEEP_SECS) return null;
    $d = json_decode((string)@file_get_contents($p), true);
    return is_array($d) && !empty($d['reply']) ? $d['reply'] : null;
}
function bf_guest_put($key, $reply) {
    stats_ensure_dir();
    $p = bf_guest_path($key);
    if (!is_dir(dirname($p))) @mkdir(dirname($p), 0755, true);
    json_save($p, ['reply' => $reply, 'ts' => time()], JSON_UNESCAPED_UNICODE);
    // 順手清掉過期的(每天最多一次)
    $mark = dirname($p) . '/.cleanup';
    if (!is_file($mark) || date('Ymd', filemtime($mark)) !== date('Ymd')) {
        @touch($mark);
        foreach (glob(dirname($p) . '/*.json') ?: [] as $f) if (time() - filemtime($f) > GA_KEEP_SECS) @unlink($f);
    }
}
