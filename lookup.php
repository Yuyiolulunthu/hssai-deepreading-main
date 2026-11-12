<?php
/**
 * 讀伴 — 簡要查詢
 * 單次性的名詞解釋與資料搜索;不提供價值性或概念性的問題回答(那是讀伴主對話的工作)。
 * 供應商:優先 Perplexity(config 設 PERPLEXITY_API_KEY,便宜且自帶搜尋);
 *        未設定則回退 Anthropic(config 的 ANTHROPIC_API_KEY,模型可用 LOOKUP_MODEL 覆寫)。
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/stats_lib.php';

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['error' => '尚未設定 config.php'], JSON_UNESCAPED_UNICODE); exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/prompt_lib.php';   // dupan_model_extra

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '僅接受 POST'], JSON_UNESCAPED_UNICODE); exit;
}
$req = json_decode(file_get_contents('php://input'), true) ?: [];
$q = trim((string)($req['q'] ?? ''));
if ($q === '') { http_response_code(400); echo json_encode(['error' => '請輸入查詢內容'], JSON_UNESCAPED_UNICODE); exit; }
if (function_exists('mb_substr')) $q = mb_substr($q, 0, 300);
else $q = substr($q, 0, 900);

$system = '你是「讀伴」的簡要查詢助手。只回答:名詞解釋、人物或作品的基本資料、年代事實、字詞出處等單次性的資料型問題。'
        . '回答精簡(100 字內為佳,最多 200 字),直接給出事實,使用繁體中文。'
        . '若問題屬於價值判斷、文本詮釋、概念性思辨(例如「這篇文章想表達什麼」「作者這樣寫好不好」),'
        . '請不要回答內容,只溫和說明:「這個問題值得帶回與讀伴的正式討論中慢慢展開,簡要查詢只處理資料型問題。」';

function http_post_json($url, $headers, $payload) {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$resp, $code];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers),
        'content' => $body, 'timeout' => 60, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    return [$resp, $code];
}

$answer = null;
$errMsg = null;

if (defined('PERPLEXITY_API_KEY') && PERPLEXITY_API_KEY !== '') {
    /* ---- Perplexity(sonar:便宜、自帶網路搜尋)---- */
    [$resp, $code] = http_post_json('https://api.perplexity.ai/chat/completions',
        ['Content-Type: application/json', 'Authorization: Bearer ' . PERPLEXITY_API_KEY],
        ['model' => defined('PERPLEXITY_MODEL') ? PERPLEXITY_MODEL : 'sonar',
         'max_tokens' => 400,
         'messages' => [
             ['role' => 'system', 'content' => $system],
             ['role' => 'user', 'content' => $q],
         ]]);
    $data = json_decode((string)$resp, true);
    if ($code < 400 && isset($data['choices'][0]['message']['content'])) {
        $answer = trim($data['choices'][0]['message']['content']);
        // Perplexity 常在文中夾引用編號 [1][2],清掉
        $answer = preg_replace('/\[\d+\]/', '', $answer);
    } else {
        $errMsg = $data['error']['message'] ?? ('Perplexity 錯誤 HTTP ' . $code);
    }
}

if ($answer === null && defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '') {
    /* ---- 回退 Anthropic ---- */
    [$resp, $code] = http_post_json('https://api.anthropic.com/v1/messages',
        ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
        ['model' => ($lkModel = defined('LOOKUP_MODEL') ? LOOKUP_MODEL : (defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6')),
         'max_tokens' => 400,
         'system' => $system,
         'messages' => [['role' => 'user', 'content' => $q]]] + dupan_model_extra($lkModel));
    $data = json_decode((string)$resp, true);
    if ($code < 400 && isset($data['content'])) {
        $answer = '';
        foreach ($data['content'] as $b) if (($b['type'] ?? '') === 'text') $answer .= $b['text'];
        $answer = trim($answer);
    } else {
        $errMsg = $data['error']['message'] ?? ($errMsg ?: 'API 錯誤 HTTP ' . $code);
    }
}

if ($answer === null) {
    http_response_code(502);
    echo json_encode(['error' => $errMsg ?: '查詢服務暫時無法使用'], JSON_UNESCAPED_UNICODE); exit;
}

try { stats_record_api('lookup', 0, 0); } catch (Throwable $e) { /* 忽略 */ }
echo json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE);
