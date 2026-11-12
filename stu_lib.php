<?php
/**
 * 讀伴 — 學生帳號(email + 密碼)
 *   ・存 data/students.json,與教師/開發者帳號(accounts.json)分開;密碼只存 password_hash()
 *   ・一個帳號可加入多門課:courses = {courseId: 學號};課程名單 students[學號]['acct'] 反向連結
 *   ・登入狀態放在簽章 cookie(dupan_stu,180 天,HttpOnly),不佔用教師後台的 PHP session,
 *     老師在同一台電腦測學生端也不會把後台登出;改密碼(含教師重設)後舊 cookie 立即失效
 *   ・登入失敗節流、隨機密碼產生、密碼規則沿用 account_lib
 *   ・忘記密碼:由授課教師在後台重設成隨機密碼,學生下次登入必須改密碼
 */
require_once __DIR__ . '/account_lib.php';

const STU_COOKIE = 'dupan_stu';
const STU_DAYS   = 180;

function stu_path() { return __DIR__ . '/data/students.json'; }

function stu_read() {
    $p = stu_path();
    if (!file_exists($p)) return ['accounts' => []];
    $d = json_decode((string)file_get_contents($p), true);
    return is_array($d) && isset($d['accounts']) ? $d : ['accounts' => []];
}

/** 加鎖讀改寫;$fn(&$d) 的回傳值原樣傳回 */
function stu_update(callable $fn) {
    stats_ensure_dir();
    $fp = fopen(stu_path(), 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d) || !isset($d['accounts'])) $d = ['accounts' => []];
    $ret = $fn($d);
    json_rewrite($fp, $d, JSON_UNESCAPED_UNICODE);
    flock($fp, LOCK_UN); fclose($fp);
    return $ret;
}

function stu_norm_email($s) { return mb_strtolower(trim((string)$s), 'UTF-8'); }
function stu_get($id) { $a = stu_read()['accounts'][$id] ?? null; return $a ? ['id' => $id] + $a : null; }
function stu_find($email) {
    $email = stu_norm_email($email);
    foreach (stu_read()['accounts'] as $id => $a) if (($a['email'] ?? '') === $email) return ['id' => $id] + $a;
    return null;
}

/** 建立帳號;回傳帳號陣列或 ['error'=>..] */
function stu_create($email, $pwd, $nick) {
    $email = stu_norm_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'Email 格式不正確'];
    if ($p = acc_pwd_problem($pwd)) return ['error' => $p];
    $hash = password_hash((string)$pwd, PASSWORD_DEFAULT);
    $r = stu_update(function (&$d) use ($email, $hash, $nick) {
        foreach ($d['accounts'] as $a) if (($a['email'] ?? '') === $email) return ['error' => '這個 Email 已經有帳號,請直接輸入密碼'];
        $id = 's_' . bin2hex(random_bytes(6));
        $d['accounts'][$id] = ['email' => $email, 'hash' => $hash, 'name' => $nick, 'mustChange' => false,
                               'courses' => [], 'createdAt' => time(), 'lastLogin' => time()];
        return ['id' => $id] + $d['accounts'][$id];
    });
    if (empty($r['error'])) audit($email, 'student.create', $email);
    return $r;
}

/** 驗證帳密(錯誤訊息不區分帳號不存在或密碼錯;不存在時也跑一次 bcrypt 湊時間) */
function stu_verify($email, $pwd) {
    $email = stu_norm_email($email);
    $key = 'stu:' . $email;   // 節流的鍵與教師帳號分開
    if ($email === '' || (string)$pwd === '') return ['error' => '請輸入 Email 與密碼'];
    if ($left = acc_locked_for($key)) return ['error' => '嘗試次數過多,請 ' . ceil($left / 60) . ' 分鐘後再試'];
    $a = stu_find($email);
    if (!password_verify((string)$pwd, $a['hash'] ?? ACC_DUMMY_HASH) || !$a) {
        acc_fail_add($key);
        return ['error' => 'Email 或密碼不正確'];
    }
    acc_fail_clear($key);
    $nh = password_needs_rehash($a['hash'], PASSWORD_DEFAULT) ? password_hash((string)$pwd, PASSWORD_DEFAULT) : null;
    stu_update(function (&$d) use ($a, $nh) {
        $d['accounts'][$a['id']]['lastLogin'] = time();
        if ($nh) $d['accounts'][$a['id']]['hash'] = $nh;
    });
    return $a;
}

/** 設定密碼;$mustChange=true 表示教師重設的臨時密碼,學生登入後必須改 */
function stu_set_password($id, $pwd, $mustChange) {
    $hash = password_hash((string)$pwd, PASSWORD_DEFAULT);
    return stu_update(function (&$d) use ($id, $hash, $mustChange) {
        if (!isset($d['accounts'][$id])) return false;
        $d['accounts'][$id]['hash'] = $hash;
        $d['accounts'][$id]['mustChange'] = $mustChange;
        return true;
    });
}

/* ---------------- 登入狀態(簽章 cookie) ---------------- */

/** 簽章金鑰:第一次用時隨機產生,存在 data/(對外 403) */
function stu_secret() {
    static $k = null;
    if ($k !== null) return $k;
    stats_ensure_dir();
    $p = __DIR__ . '/data/.stu_secret';
    $k = is_file($p) ? trim((string)file_get_contents($p)) : '';
    if (strlen($k) < 32) { $k = bin2hex(random_bytes(32)); @file_put_contents($p, $k, LOCK_EX); @chmod($p, 0600); }
    return $k;
}
/** 密碼雜湊的指紋:改密碼後舊 cookie 對不上就失效 */
function stu_pwd_ver($a) { return substr(hash_hmac('sha256', (string)($a['hash'] ?? ''), stu_secret()), 0, 16); }

function stu_set_cookie($val, $expires) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(STU_COOKIE, $val, ['expires' => $expires, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[STU_COOKIE] = $val;
}
function stu_login($a) {
    $exp = time() + STU_DAYS * 86400;
    $body = base64_encode(json_encode(['u' => $a['id'], 'e' => $exp, 'v' => stu_pwd_ver($a)]));
    stu_set_cookie($body . '.' . hash_hmac('sha256', $body, stu_secret()), $exp);
}
function stu_logout() { stu_set_cookie('', time() - 3600); }

/** 目前登入的學生(驗簽章、到期、密碼版本);沒登入回 null */
function stu_user() {
    $raw = (string)($_COOKIE[STU_COOKIE] ?? '');
    $dot = strrpos($raw, '.');
    if ($dot === false) return null;
    $body = substr($raw, 0, $dot);
    if (!hash_equals(hash_hmac('sha256', $body, stu_secret()), substr($raw, $dot + 1))) return null;
    $t = json_decode((string)base64_decode($body, true), true);
    if (!is_array($t) || ($t['e'] ?? 0) < time() || !is_string($t['u'] ?? null)) return null;
    $a = stu_get($t['u']);
    if (!$a || !hash_equals(stu_pwd_ver($a), (string)($t['v'] ?? ''))) return null;
    return $a;
}

/* ---------------- 課程 ---------------- */

/** 帳號目前有效的課程(課程還在、學號仍在名單上) */
function stu_courses($a) {
    $out = [];
    $byId = [];
    foreach (school_read()['courses'] as $c) $byId[$c['id']] = $c;
    foreach (($a['courses'] ?? []) as $cid => $sid) {
        $c = $byId[$cid] ?? null;
        if (!$c || !isset($c['students'][$sid])) continue;
        $out[] = ['courseId' => $cid, 'courseName' => $c['name'], 'sid' => (string)$sid, 'name' => $c['students'][$sid]['name'] ?? ''];
    }
    return $out;
}

/** 目前登入者是否就是這門課的這個學號 */
function stu_owns($courseId, $sid) {
    $a = stu_user();
    return $a && (string)($a['courses'][$courseId] ?? '') === (string)$sid && $sid !== '';
}

/** 以邀請碼加入課程;回傳 ['ok'=>true,'courseId'=>..] 或 ['error'=>..] */
function stu_join($a, $code, $sid, $name) {
    $course = school_find_by_code(school_read(), strtoupper(trim($code)));
    if (!$course) return ['error' => '邀請碼不正確,請向老師確認'];
    $cid = $course['id'];
    if (isset($a['courses'][$cid]) && isset($course['students'][$a['courses'][$cid]])) {
        return ['ok' => true, 'courseId' => $cid, 'already' => true];   // 已經在這門課:直接進去
    }
    $err = school_update(function (&$s) use ($cid, $sid, $name, $a) {
        $c = &school_find_course($s, $cid);
        if ($c === null) return '課程不存在';
        $st = $c['students'][$sid] ?? null;
        if ($st && !empty($st['acct']) && $st['acct'] !== $a['id']) return '這個學號已由其他帳號加入這門課,若有誤請洽授課老師';
        if (!$st) {
            $c['students'][$sid] = ['name' => ($name !== '' ? $name : '(未填姓名)'), 'self' => true,
                'calls' => 0, 'turns' => 0, 'in' => 0, 'out' => 0, 'last' => time(), 'texts' => []];
        } elseif ($name !== '' && in_array($st['name'], ['(未登記)', '(未填姓名)', '（未登記）', '（未填姓名）', ''], true)) {
            $c['students'][$sid]['name'] = $name;
        }
        $c['students'][$sid]['acct'] = $a['id'];
        $c['students'][$sid]['last'] = time();
        return null;
    });
    if ($err) return ['error' => $err];
    stu_update(function (&$d) use ($a, $cid, $sid) { $d['accounts'][$a['id']]['courses'][$cid] = $sid; });
    return ['ok' => true, 'courseId' => $cid];
}

/** 教師把學生移出名單時,一併解除帳號的課程連結 */
function stu_unlink($acctId, $courseId) {
    if (!$acctId) return;
    stu_update(function (&$d) use ($acctId, $courseId) { unset($d['accounts'][$acctId]['courses'][$courseId]); });
}
