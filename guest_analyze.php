<?php
/**
 * 讀伴 — 免費試用的階段一解析(背景執行)
 *   POST {action: "run", docTitle, docText}
 *   立即回應狀態,之後在伺服器端繼續解析(約 3–4 分鐘);完成後讀伴對話時自動作為閱讀地圖
 *   回傳 status:running | done | too_long | limited
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/analysis_lib.php';
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';

function out($a, $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => '僅接受 POST'], 405);
if (defined('SITE_MODE') && SITE_MODE === 'course') out(['error' => '本站未開放自由上傳'], 403);

$req = json_decode(file_get_contents('php://input'), true) ?: [];
if (($req['action'] ?? '') !== 'run') out(['error' => '未知的操作'], 400);
$text  = ga_norm($req['docText'] ?? '');
$title = u_cut(trim((string)($req['docTitle'] ?? '')), 120);
if ($text === '') out(['error' => '沒有文本內容'], 400);
$len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
require_once __DIR__ . '/guest_lib.php';
if ($len > min(GA_MAX_CHARS, GUEST_MAX_CHARS)) out(['ok' => true, 'status' => 'too_long']);

ga_cleanup();
$rec = ga_get(ga_key($text));
if ($rec && ($rec['status'] ?? '') === 'done') out(['ok' => true, 'status' => 'done']);
if ($rec && ($rec['status'] ?? '') === 'running' && time() - (int)($rec['started'] ?? 0) < 600) out(['ok' => true, 'status' => 'running']);
if (!ga_take_quota(ga_client_ip())) {
    guest_log($req, $title, $text, ['mode' => 'analysis', 'status' => 'limited']);   // 超過每日解析額度:濫用訊號
    out(['ok' => true, 'status' => 'limited']);
}

/* 先回應瀏覽器,再於伺服器端繼續執行(與 analyze.php 相同) */
ignore_user_abort(true);
@set_time_limit(600);
$resp = json_encode(['ok' => true, 'status' => 'running'], JSON_UNESCAPED_UNICODE);
while (ob_get_level() > 0) ob_end_clean();
header('Connection: close');
header('Content-Length: ' . strlen($resp));
echo $resp;
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$r = ga_run($text, $title);
guest_log($req, $title, $text, ['mode' => 'analysis', 'status' => isset($r['text']) && trim($r['text']) !== '' ? 'done' : 'error',
    'in' => $r['in'] ?? 0, 'out' => $r['out'] ?? 0]);
