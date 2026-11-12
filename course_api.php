<?php
/**
 * 讀伴 — 課程模式的學生端 API(學生以 email 帳號登入,見 stu_lib)
 *   me          目前登入的學生與他的課程
 *   login       email + 密碼登入
 *   logout      登出
 *   join        以邀請碼加入課程(未登入時:email 已有帳號就驗密碼,沒有就用這組密碼建立帳號)
 *   change_pwd  修改密碼(教師重設的臨時密碼,登入後必須改)
 *   texts / text / briefing   課程文本清單、全文、導讀——必須是登入者自己的課程與學號
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/stu_lib.php';

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => '僅接受 POST'], 405);

/** 課程的文本清單(不含全文) */
function stu_text_list($course) {
    $list = [];
    foreach (school_norm_texts($course['texts'] ?? []) as $t) {
        $body = (string)$t['text'];
        $list[] = ['id' => $t['id'], 'title' => $t['title'], 'chars' => $body === '' ? 0 : mb_strlen($body), 'ready' => $body !== '', 'orig' => $t['orig'] ?? ''];
    }
    return $list;
}
function stu_me($a) {
    return ['ok' => true, 'email' => $a['email'], 'name' => $a['name'] ?? '', 'mustChange' => !empty($a['mustChange']), 'courses' => stu_courses($a)];
}
/** 課程類動作的權限:要登入,而且這門課的這個學號是登入者自己的 */
function stu_need_course($courseId, $sid) {
    $a = stu_user();
    if (!$a) out(['error' => '請先登入', 'login' => true], 401);
    if (!empty($a['mustChange'])) out(['error' => '請先設定新密碼'], 403);
    $course = null;
    foreach (school_read()['courses'] as $c) if ($c['id'] === $courseId) { $course = $c; break; }
    if (!$course) out(['error' => '課程不存在'], 404);
    if (!isset($course['students'][$sid]) || !stu_owns($courseId, $sid)) out(['error' => '你已不在這門課的名單中'], 403);
    return $course;
}

$req = json_decode(file_get_contents('php://input'), true) ?: [];
$str = fn($k, $len) => u_cut(trim(is_scalar($req[$k] ?? null) ? (string)$req[$k] : ''), $len);
$action = $req['action'] ?? '';

if ($action === 'logout') { stu_logout(); out(['ok' => true]); }

if ($action === 'me') {
    $a = stu_user();
    if (!$a) out(['error' => '尚未登入'], 401);
    stu_login($a);   // 延長 180 天
    out(stu_me($a));
}

if ($action === 'login') {
    $a = stu_verify($str('email', 120), (string)($req['pwd'] ?? ''));
    if (!empty($a['error'])) out(['error' => $a['error']], 401);
    stu_login($a);
    out(stu_me($a));
}

if ($action === 'join') {
    $code = strtoupper($str('code', 20)); $sid = $str('sid', 30); $name = $str('name', 30);
    if ($code === '' || $sid === '') out(['error' => '請填寫邀請碼與學號'], 400);
    if (!school_find_by_code(school_read(), $code)) out(['error' => '邀請碼不正確,請向老師確認'], 404);
    $a = stu_user();
    if (!$a) {
        $email = $str('email', 120); $pwd = (string)($req['pwd'] ?? '');
        if (stu_find($email)) {
            $a = stu_verify($email, $pwd);
        } else {
            $a = stu_create($email, $pwd, $name);
        }
        if (!empty($a['error'])) out(['error' => $a['error']], 400);
        stu_login($a);
    }
    $r = stu_join($a, $code, $sid, $name);
    if (!empty($r['error'])) out(['error' => $r['error']], 409);
    out(stu_me(stu_get($a['id'])) + ['courseId' => $r['courseId']]);
}

if ($action === 'change_pwd') {
    $a = stu_user();
    if (!$a) out(['error' => '請先登入', 'login' => true], 401);
    $p1 = (string)($req['p1'] ?? '');
    if (!password_verify((string)($req['cur'] ?? ''), $a['hash'])) out(['error' => '目前的密碼不正確'], 400);
    if ($p = acc_pwd_problem($p1)) out(['error' => $p], 400);
    if ($p1 !== (string)($req['p2'] ?? '')) out(['error' => '兩次輸入的新密碼不一致'], 400);
    if ($p1 === (string)$req['cur']) out(['error' => '新密碼不可與目前的密碼相同'], 400);
    stu_set_password($a['id'], $p1, false);
    $a = stu_get($a['id']);
    stu_login($a);   // 密碼版本變了,重發 cookie(其他裝置上的舊登入會失效)
    audit($a['email'], 'student.password', $a['email']);
    out(stu_me($a));
}

$courseId = $str('courseId', 20);
$sid      = $str('sid', 30);

if ($action === 'texts') {
    $course = stu_need_course($courseId, $sid);
    school_update(function (&$s) use ($courseId, $sid) {   // 最後活動時間
        $c = &school_find_course($s, $courseId);
        if ($c !== null && isset($c['students'][$sid])) $c['students'][$sid]['last'] = time();
        return null;
    });
    out(['ok' => true, 'courseName' => $course['name'], 'texts' => stu_text_list($course)]);
}

if ($action === 'text') {
    stu_need_course($courseId, $sid);
    $t = school_get_text($courseId, $str('textId', 30));
    if (!$t) out(['error' => '找不到這篇文本'], 404);
    if ((string)$t['text'] === '') out(['error' => '老師尚未上傳這篇文本的內容'], 409);
    out(['ok' => true, 'id' => $t['id'], 'title' => $t['title'], 'text' => $t['text'], 'orig' => $t['orig'] ?? '']);
}

/* ---------- 接續閱讀:這位學生在這篇文本最近一次存到伺服器的進度(對話、筆記、重點畫記、書籤) ----------
   舊紀錄沒有 textId,以標題比對 */
if ($action === 'progress') {
    stu_need_course($courseId, $sid);
    $t = school_get_text($courseId, $str('textId', 30));
    if (!$t) out(['error' => '找不到這篇文本'], 404);
    $dir = __DIR__ . '/data/transcripts/' . preg_replace('/[^A-Za-z0-9_-]/', '', $courseId);
    $best = null;
    foreach (glob($dir . '/' . preg_replace('/[^A-Za-z0-9_\-]/u', '_', $sid) . '__*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || ($d['sid'] ?? '') !== $sid) continue;
        $same = ($d['textId'] ?? '') !== '' ? $d['textId'] === $t['id'] : ($d['title'] ?? '') === $t['title'];
        if (!$same || ($best && ($best['updatedAt'] ?? 0) >= ($d['updatedAt'] ?? 0))) continue;
        $has = !empty($d['notes']) || !empty($d['highlights']);
        foreach (($d['nodes'] ?? []) as $nd) if (!empty($nd['messages'])) { $has = true; break; }
        if ($has) $best = $d;
    }
    if (!$best) out(['ok' => true, 'found' => false]);
    out(['ok' => true, 'found' => true, 'docId' => $best['docId'] ?? '', 'updatedAt' => $best['updatedAt'] ?? 0,
         'nodes' => $best['nodes'] ?? [], 'notes' => $best['notes'] ?? [], 'highlights' => $best['highlights'] ?? [], 'state' => $best['state'] ?? []]);
}

/* ---------- 導讀:全班共用一份(依教師審定的解析生成,教師可修改) ---------- */
if ($action === 'briefing') {
    stu_need_course($courseId, $sid);
    $textId = $str('textId', 30);
    require_once __DIR__ . '/briefing_lib.php';
    $bs = bf_state($courseId, $textId);
    if ($bs['state'] === 'notext') out(['error' => '找不到這篇文本'], 404);
    $resp = ['ok' => true, 'status' => $bs['state']];
    if ($bs['state'] === 'done') $resp['briefing'] = bf_public($bs['rec']);
    if ($bs['state'] === 'error') $resp['error_detail'] = $bs['rec']['genError'] ?? '';
    // 第一個打開的學生觸發生成(或過期後重新生成);其他人看到「生成中」或舊版
    if ($bs['regen'] && bf_claim($courseId, $textId)) bf_respond_then_run($courseId, $textId, $resp);
    out($resp);
}

out(['error' => '未知的操作'], 400);
