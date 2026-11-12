<?php
/**
 * 讀伴 — 課程文本解析的背景執行與狀態查詢(僅限已登入教師)
 *   action=run     開始解析;立即回應,之後在伺服器端繼續執行(教師離開頁面也會完成)
 *   action=status  回傳課程內各文本的解析狀態
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/analysis_lib.php';
require_once __DIR__ . '/school_lib.php';
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';

auth_session_start();
$me = auth_user();
// 取得身分後即釋放 session 鎖,避免長時間解析卡住同一位教師的其他頁面請求
session_write_close();
if (!$me || !in_array($me['role'], ['teacher', 'dev'], true) || !empty($me['mustChange']) || !auth_same_origin()) {
    http_response_code(403);
    echo json_encode(['error' => '請先登入教師後台'], JSON_UNESCAPED_UNICODE);
    exit;
}

$req = json_decode(file_get_contents('php://input'), true) ?: [];
$action   = $req['action'] ?? '';
/* JSON 欄位可能被塞成陣列或物件;只接受純量,否則當空字串(不然 (string) 轉型會發 Warning) */
$scalar   = fn($v) => is_scalar($v) ? (string)$v : '';
$courseId = ta_safe($scalar($req['courseId'] ?? ''));

/* 只能操作自己的課(開發者不限);不是自己的就當作不存在 */
$okCourse = false; $theCourse = null;
foreach (school_read()['courses'] as $c) {
    if ($c['id'] === $courseId) { $okCourse = auth_is_dev($me) || in_array($me['id'], course_teacher_ids($c), true); $theCourse = $c; break; }
}
if (!$okCourse) {
    http_response_code(404);
    echo json_encode(['error' => '找不到這門課'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'status') {
    $out = [];
    foreach (school_norm_texts($theCourse['texts'] ?? []) as $t) {
        $rec = ta_get($courseId, $t['id']);
        $st = $rec['status'] ?? 'none';
        if ($rec && ta_is_stuck($rec)) $st = 'stuck';
        $out[$t['id']] = $st;
    }
    echo json_encode(['ok' => true, 'status' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'run') {
    $textId = ta_safe($scalar($req['textId'] ?? ''));
    $force  = !empty($req['force']);
    $rec = ta_get($courseId, $textId);
    $st = $rec['status'] ?? 'none';

    // 已在執行中就不重複啟動(除非卡住)
    if ($st === 'running' && !ta_is_stuck($rec)) {
        echo json_encode(['ok' => true, 'status' => 'running'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 已完成且非強制重跑:不動(保護教師的編修)
    if ($st === 'done' && !$force) {
        echo json_encode(['ok' => true, 'status' => 'done'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* 真的要開始跑了才記:開發者在別人的課按「重新解析」;自動觸發的首次解析(force 未設)不算代改 */
    if ($force) audit_dev_edit($me, $theCourse, 'analysis_run', ['text_id' => $textId]);

    /* 先回應瀏覽器,再於伺服器端繼續執行 */
    ignore_user_abort(true);
    @set_time_limit(600);   // 串流產生完整解析約 3–4 分鐘
    $resp = json_encode(['ok' => true, 'status' => 'running'], JSON_UNESCAPED_UNICODE);
    while (ob_get_level() > 0) ob_end_clean();
    header('Connection: close');
    header('Content-Length: ' . strlen($resp));
    echo $resp;
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    ta_run($courseId, $textId);
    exit;
}

/* ---------- 導讀:教師頁面自動觸發(force=0)或按「重新生成」(force=1) ---------- */
if ($action === 'brief_run' || $action === 'brief_status') {
    require_once __DIR__ . '/briefing_lib.php';
    if ($action === 'brief_status') {
        $out = [];
        foreach (school_norm_texts($theCourse['texts'] ?? []) as $t) {
            $bs = bf_state($courseId, $t['id']);
            $rec = $bs['rec'] ?: [];
            $out[$t['id']] = ['state' => $bs['state'], 'gen' => !empty($rec['gen']) && time() - (int)$rec['gen'] < BF_GEN_TIMEOUT];
        }
        echo json_encode(['ok' => true, 'status' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $textId = ta_safe($scalar($req['textId'] ?? ''));
    $force  = !empty($req['force']);
    $bs = bf_state($courseId, $textId);
    $st = function ($s) { echo json_encode(['ok' => true, 'status' => $s], JSON_UNESCAPED_UNICODE); exit; };
    if (!bf_source_map($courseId, $textId)) $st('waiting');
    if (!$force && !$bs['regen']) $st($bs['state']);
    if (!bf_claim($courseId, $textId)) $st('running');
    if ($force) audit_dev_edit($me, $theCourse, 'briefing_run', ['text_id' => $textId]);
    bf_respond_then_run($courseId, $textId, ['ok' => true, 'status' => 'running']);
}

http_response_code(400);
echo json_encode(['error' => '未知的操作'], JSON_UNESCAPED_UNICODE);
