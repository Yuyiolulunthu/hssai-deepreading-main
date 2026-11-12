<?php
/**
 * 讀伴 — Prompt 管理與後台 LLM 呼叫
 * 後台編輯的 prompt 存於 data/prompts.json;api.php 於執行時套用覆寫。
 */
require_once __DIR__ . '/stats_lib.php';

function prompts_path() { return __DIR__ . '/data/prompts.json'; }

/** 讀取後台覆寫（空 = 使用內建預設） */
function prompts_overrides() {
    stats_ensure_dir();
    if (!is_file(prompts_path())) return [];
    $raw = @file_get_contents(prompts_path());
    $p = json_decode($raw, true);
    return is_array($p) ? $p : [];
}

function prompts_save(array $p) {
    stats_ensure_dir();
    // 只保留非空覆寫
    $p = array_filter(array_map('trim', $p), fn($v) => $v !== '');
    if (!json_save(prompts_path(), $p, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) return false;
    // 讀回驗證:確認實際落地的內容與送入一致
    clearstatcache(true, prompts_path());
    $back = json_decode((string)@file_get_contents(prompts_path()), true);
    return is_array($back) && count($back) === count($p);
}


/**
 * 內建 prompt（單一來源：api.php 與後台顯示皆取自此處）
 * 總則、三個人格、經典參照、階段一解析、閱讀回顧摘要 → prompts_design.php（教授的設計版）
 * 導讀、閱讀筆記 → 設計資料夾未涵蓋，沿用原本內容
 */
function prompts_builtin() {
    static $b = null;
    if ($b !== null) return $b;
    $d = require __DIR__ . '/prompts_design.php';
    $briefing = "你是「讀伴」的導讀編輯，為讀者在正式閱讀與討論之前，準備一份初淺的導讀。\n"
        . "僅輸出 JSON（不要 Markdown 圍欄、不要任何多餘文字）,格式如下：\n"
        . "{\"summary\":\"...\",\"background\":\"...\",\"mindmap\":{\"center\":\"...\",\"branches\":[{\"label\":\"...\",\"children\":[\"...\"]}]}}\n"
        . "規範：\n"
        // 摘要與背景:2026-11-08 教授核定的修訂版(設計資料夾「討論中(未定案)/導讀prompt修訂提案_20261108.md」)
        . "- summary:約 500 字（450–550 字，分 2–3 個自然段）。目的是讓讀者在讀之前知道「這篇文本在做什麼、怎麼走」，讀的時候才認得出重點在哪裡。\n"
        . "  依文本自己的順序介紹。論述性文本（論文、評論、教科書、思想文獻）要交代：它回應的問題或現象是什麼、核心主張是什麼、用哪幾步論證、依據什麼材料（引用的經典、資料、案例、對手的說法）、最後收在哪裡；"
        . "敘事或抒情文本則交代場景、視角、情節或情緒如何推進、關鍵意象出現在哪裡。\n"
        . "  關鍵概念與轉折用文本自己的用語，必要時以「」照抄原文的詞語，讓讀者能在原文裡找到它。\n"
        . "  只做內容與結構層面的介紹：不評價、不下詮釋結論、不替讀者讀出「意義」、不說「作者想表達的是」；把發現留給讀者。\n"
        . "- background:約 500 字（450–550 字，分 2–3 個自然段）。目的只有一個：讀者讀這篇之前需要先知道什麼，才讀得懂、讀得進去。不重述文本內容（那是 summary 的工作），不評價文本。\n"
        . "  依下面三層寫，每層都要連到這一篇文本，不寫放到任何文本都成立的通論：\n"
        . "  1. 作者與出處：只寫確知的事。以文本內的線索為優先——署名、頭銜、註腳、引用的書目、提到的年代與事件、發表的場合；你另外知道的作者資料，只在確定時寫。不確定的寧可不寫，絕不捏造；作者不明就略過這一層，不要用「這類作者通常⋯」填補。\n"
        . "  2. 文本所處的脈絡：它在回應什麼問題、跟誰對話（從文本引用、反駁的對象可以看出）、屬於哪個學科傳統或哪一類寫作（學術論文、評論、教科書章節、經典原典、政策文件⋯），以及這個傳統的讀者通常期待它做什麼。\n"
        . "  3. 讀之前該先認識的東西：文本預設讀者已經知道、初學者卻可能不熟的專有名詞、理論家、人物、典故、歷史事件或制度，各用一兩句說清楚它是什麼、在文中扮演什麼角色；人文社會文本常引用的理論家（例如康德、韋伯、傅柯、Goffman）給最簡要的定位即可，不展開他們的整套理論。\n"
        . "  三層裡資訊充足的多寫、沒有的略過；不要為了湊到 500 字而寫空話（「這類文本的常見讀法是⋯」「閱讀時可以留意作者的用字」這類句子不要出現）。\n"
        . "- 導讀只介紹【目前共讀的文本】本身。若另附有「閱讀地圖」或任何解析資料，那是讀伴的內部參考，讀者看不到：summary 與 background 都不得提到它，也不得介紹解析的方法、分析框架或理論（例如 White 的四元框架），不寫「解析指出」「這份解析」之類的話。\n"
        . "- mindmap 是讀者進入文本的路線圖，要呈現「這篇文本自己是怎麼組織的」,不是套用固定的分析類別。\n"
        . "- mindmap.center:文本處理的核心主題或問題（10 字以內，可以是標題）。\n"
        . "- branches:3–5 個面向，依文本自身的組織方式決定——論述性文本（論文、教科書、評論）用它的主要論點、核心概念或論證步驟；"
        . "涉及多個學科或多條主線的文本，每條主線或每個學科視角各成一支；敘事、抒情文本才用意象、結構、人物等切入。"
        . "label 用文本裡實際出現的關鍵詞或概念名（2–8 字）,避免「背景」「結構」「主題」這類放到任何文本都成立的泛用分類。\n"
        . "- 每個 branch 的 children 為 2–4 條、各 4–16 字：該面向下文本實際提出的概念、主張、例證或關鍵詞；可以點出概念之間的關係（例如「A 導致 B」）,但不下詮釋結論。\n"
        . "- 若附有閱讀地圖，branches 要與其中辨識出的主線或核心概念一致。\n"
        . "- 全部使用繁體中文（引用簡體或外文原文時照原文）。";
    return $b = [
        'guard'       => $d['guard'],
        'personas'    => [
            'guide'    => "\n\n" . $d['persona_guide'],
            'critic'   => "\n\n" . $d['persona_critic'],
            'explorer' => "\n\n" . $d['persona_explorer'],
        ],
        'briefing'    => $briefing,
        'analysis'    => $d['analysis'],
        'classics'    => $d['classics'],
        'note_gen'    => "你是「讀伴」的閱讀筆記整理者。根據讀者與讀伴的完整對話,整理成一份帶有讀者個人觀點的閱讀筆記。\n原則:\n1、以讀者為主詞——寫的是「你注意到⋯」「你提出的疑問是⋯」「你尚未解開的線頭是⋯」,不是讀伴的分析。\n2、保留讀者思考的轉折與過程,包括中途改變想法的地方;那正是閱讀發生的痕跡。\n3、分支的討論獨立成段,標明它從哪個問題岔出去、最後收在哪裡。\n4、不補充對話中沒有出現過的觀點,不替讀者總結出他沒說過的結論。\n5、結尾列出「仍待追問的問題」,把未完成的思考留成下一次的入口。\n6、全文使用繁體中文,語氣平實,不使用條列式術語堆疊。",
        'summary_gen' => $d['summary_gen'],
    ];
}

/** 欄位用途說明（後台 textarea 的 placeholder) */
/** 可在後台覆寫的各項 prompt 的內建版本(key => 全文);後台檢視、比對「是否與內建相同」都用這一份 */
function prompts_builtin_map() {
    $bi = prompts_builtin();
    return [
        'guard'            => $bi['guard'],
        'persona_guide'    => trim($bi['personas']['guide']),
        'persona_critic'   => trim($bi['personas']['critic']),
        'persona_explorer' => trim($bi['personas']['explorer']),
        'classics'         => $bi['classics'],
        'analysis'         => $bi['analysis'],
        'briefing'         => $bi['briefing'],
        'note_gen'         => $bi['note_gen'],
        'summary_gen'      => $bi['summary_gen'],
    ];
}


/**
 * 後台呼叫 Anthropic（與 api.php 相同的 curl/stream 雙軌）
 * $stream = true:以串流接收,適合長輸出(例如完整的階段一解析要 3–4 分鐘、近萬 token,
 * 一次性等待回覆容易被網路逾時切斷)。回傳 ['text' => ..., 'stop' => 結束原因]。
 */
/**
 * 依模型補上必要的請求欄位,維持「不做預先思考」的行為(讀伴一直以 Sonnet 4.6 不思考的方式運作):
 *   Sonnet 5.5 省略 thinking 會自動開啟思考、且不接受 disabled → 用 between_tools 關閉預先思考(沒有工具時等同關閉)
 *   Sonnet 5 / Opus 5 省略 thinking 也會自動思考 → 明確設 disabled
 *   4.x 省略就是不思考,不需要加
 * 自動思考會把思考 token 算進 max_tokens,讀伴 1,600 的上限可能被吃光而回傳空白或截斷的回覆。
 */
function dupan_model_extra($model) {
    $m = (string)$model;
    if (strpos($m, 'claude-sonnet-5-5') === 0) return ['thinking' => ['type' => 'between_tools']];
    if (in_array($m, ['claude-sonnet-5', 'claude-opus-5'], true)) return ['thinking' => ['type' => 'disabled']];
    return [];
}

/**
 * 送給模型的文本每段加上【N】段落編號,讓讀伴能說出「第N段」(前端點擊引文也以此對位)。
 * 分段規則必須與前端 splitParas 完全相同:先以空行分段,不足兩段才退回單一換行。
 */
function dupan_number_paras($text, $from = 0, $to = null) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $trim = fn($s) => preg_replace('/^[\s\pZ]+|[\s\pZ]+$/u', '', $s);
    $paras = array_values(array_filter(array_map($trim, preg_split('/\n\s*\n/u', $text)), 'strlen'));
    if (count($paras) < 2) $paras = array_values(array_filter(array_map($trim, explode("\n", $text)), 'strlen'));
    $out = [];
    foreach ($paras as $i => $p) {                                   // 分段共讀:只取 $from..$to(段落索引),編號仍是全文的編號
        if ($i < $from || ($to !== null && $i > $to)) continue;
        $out[] = '【' . ($i + 1) . '】' . $p;
    }
    return implode("\n\n", $out);
}
/** 文本區塊開頭的說明:編號不是原文,引用時不要抄進去 */
const DUPAN_PARA_NOTE = "（每段開頭的【N】是系統加的段落編號，不是原文的一部分。指出位置時說「第N段」；逐字引用時只抄原文，不抄編號。以 # 開頭的段落是原文的章節標題。）";

function dupan_llm($system, $userContent, $maxTokens = 3000, $stream = false) {
    if (!defined('ANTHROPIC_API_KEY')) return ['error' => '尚未設定 config.php'];
    $model = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6';
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $userContent]],
        'stream'     => (bool)$stream,
    ] + dupan_model_extra($model), JSON_UNESCAPED_UNICODE);
    $url = 'https://api.anthropic.com/v1/messages';
    $headers = ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'];
    if ($stream && function_exists('curl_init')) return dupan_llm_stream($url, $headers, $payload);
    $resp = false; $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers),
            'content' => $payload, 'timeout' => 120, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    if ($resp === false) return ['error' => '無法連線至 Anthropic API'];
    $data = json_decode($resp, true);
    if ($code >= 400 || !isset($data['content'])) {
        return ['error' => $data['error']['message'] ?? ('API 錯誤 HTTP ' . $code)];
    }
    $text = '';
    foreach ($data['content'] as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
    $usage = $data['usage'] ?? [];
    $in = (int)($usage['input_tokens'] ?? 0); $out = (int)($usage['output_tokens'] ?? 0);
    stats_record_api('analysis', $in, $out);
    return ['text' => trim($text), 'in' => $in, 'out' => $out];
}

/* ---------- 階段一解析儲存 ---------- */
function analysis_path() { return __DIR__ . '/data/analysis.json'; }
function analysis_read() {
    stats_ensure_dir();
    if (!file_exists(analysis_path())) return [];
    $a = json_decode(@file_get_contents(analysis_path()), true);
    return is_array($a) ? $a : [];
}
function analysis_save($title, $entry) {
    stats_ensure_dir();
    $a = analysis_read();
    if ($entry === null) unset($a[$title]); else $a[$title] = $entry;
    json_save(analysis_path(), $a, JSON_UNESCAPED_UNICODE);
}

/** dupan_llm 的串流分支:逐行解析 SSE 事件,累積文字與用量 */
function dupan_llm_stream($url, $headers, $payload) {
    $buf = ''; $text = ''; $stop = null; $err = null; $in = 0; $out = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$text, &$stop, &$err, &$in, &$out) {
            $buf .= $chunk;
            while (($p = strpos($buf, "\n")) !== false) {
                $line = trim(substr($buf, 0, $p)); $buf = substr($buf, $p + 1);
                if (strncmp($line, 'data:', 5) !== 0) continue;
                $ev = json_decode(substr($line, 5), true);
                switch ($ev['type'] ?? '') {
                    case 'message_start':       $in = (int)($ev['message']['usage']['input_tokens'] ?? 0); break;
                    case 'content_block_delta': $text .= $ev['delta']['text'] ?? ''; break;
                    case 'message_delta':       $stop = $ev['delta']['stop_reason'] ?? $stop;
                                                $out = (int)($ev['usage']['output_tokens'] ?? $out); break;
                    case 'error':               $err = $ev['error']['message'] ?? 'API 錯誤'; break;
                }
            }
            return strlen($chunk);
        }]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($code >= 400) {   // 錯誤時回的是一般 JSON,不是 SSE,會留在 $buf
        $d = json_decode($buf, true);
        return ['error' => $d['error']['message'] ?? ('API 錯誤 HTTP ' . $code)];
    }
    if ($ok === false) return ['error' => '無法連線至 Anthropic API' . ($cerr !== '' ? "({$cerr})" : '')];
    if ($err !== null) return ['error' => $err];
    stats_record_api('analysis', $in, $out);
    return ['text' => trim($text), 'stop' => $stop, 'in' => $in, 'out' => $out];
}
