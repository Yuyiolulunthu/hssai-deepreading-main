<?php
/**
 * 讀伴 — 後台帳號(開發者、教師)
 *   ・帳號存 data/accounts.json,密碼只存 password_hash() 的雜湊
 *   ・同一個 PHP session 裡只放一個身分:$_SESSION['acc'] = [id, role]
 *     每次請求都回帳號檔重讀,所以停用或降權會立即生效
 *   ・登入失敗節流:同一帳號連錯 5 次鎖 15 分鐘;同一 IP 15 分鐘內錯 30 次也鎖
 *   ・稽核紀錄 data/audit.log,一行一筆 JSON
 *   ・學生帳號另存 data/students.json,見 stu_lib.php
 */
require_once __DIR__ . '/stats_lib.php';   // stats_ensure_dir():建立 data/ 與保護檔
require_once __DIR__ . '/school_lib.php';  // course_teacher_ids()

const ACC_ROLES       = ['dev' => '開發者', 'teacher' => '教師'];
const ACC_MIN_PWD     = 8;
const ACC_FAIL_MAX    = 5;      // 同一帳號
const ACC_FAIL_IP_MAX = 30;     // 同一 IP
const ACC_LOCK_SECS   = 900;
// 固定的假雜湊(對應密碼 'dummy'),只拿來湊時間,永遠不會匹配真實登入
const ACC_DUMMY_HASH  = '$2y$10$9Lj1qBffyq/8yI5l.mfC9ekxf/Km7MR1mlLziwo3v.xcSvE93sRAG';

function acc_path()      { return __DIR__ . '/data/accounts.json'; }
function acc_fail_path() { return __DIR__ . '/data/login_fail.json'; }
function audit_path()    { return __DIR__ . '/data/audit.log'; }

function acc_norm_login($s) { return mb_strtolower(trim((string)$s), 'UTF-8'); }

/* ---------------- 讀寫(與 school_update 同樣以 flock 保護) ---------------- */

function acc_read() {
    $p = acc_path();
    if (!is_file($p)) return ['accounts' => []];
    $d = json_decode((string)file_get_contents($p), true);
    return is_array($d) && isset($d['accounts']) ? $d : ['accounts' => []];
}

/** $fn(&$data) 回傳值會被原樣傳回;在鎖內讀改寫 */
function acc_update(callable $fn) {
    stats_ensure_dir();
    $fp = fopen(acc_path(), 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d) || !isset($d['accounts'])) $d = ['accounts' => []];
    $ret = $fn($d);
    json_rewrite($fp, $d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    flock($fp, LOCK_UN); fclose($fp);
    @chmod(acc_path(), 0640);
    return $ret;
}

function acc_get($id) {
    $a = acc_read()['accounts'][$id] ?? null;
    return $a ? ['id' => $id] + $a : null;
}

function acc_find($login) {
    $login = acc_norm_login($login);
    foreach (acc_read()['accounts'] as $id => $a) if (($a['login'] ?? '') === $login) return ['id' => $id] + $a;
    return null;
}

function acc_list($role = null) {
    $out = [];
    foreach (acc_read()['accounts'] as $id => $a) if ($role === null || $a['role'] === $role) $out[] = ['id' => $id] + $a;
    usort($out, fn($x, $y) => strcmp($x['role'] ?? '', $y['role'] ?? '') ?: (($x['createdAt'] ?? 0) <=> ($y['createdAt'] ?? 0)));
    return $out;
}

function acc_count_devs() {
    return count(array_filter(acc_read()['accounts'], fn($a) => ($a['role'] ?? '') === 'dev' && empty($a['disabled'])));
}

/** 產生好念、不易混淆的初始密碼(去掉 0/O/1/l/I) */
function acc_gen_password($len = 12) {
    $cs = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $cs[random_int(0, strlen($cs) - 1)];
    return $s;
}

function acc_pwd_problem($pwd) {
    if (mb_strlen((string)$pwd) < ACC_MIN_PWD) return '密碼至少需要 ' . ACC_MIN_PWD . ' 個字元';
    return '';
}

/* ---------------- 建立與維護 ---------------- */

/** 建立帳號;回傳 ['ok'=>true,'id'=>..] 或 ['error'=>..] */
function acc_create($login, $name, $role, $pwd, $by, $mustChange = true) {
    $login = acc_norm_login($login);
    $name  = trim((string)$name);
    if ($login === '' || mb_strlen($login) > 80) return ['error' => '帳號不可空白,且不超過 80 字'];
    if (!preg_match('/^[\p{L}\p{N}._@+\-]+$/u', $login)) return ['error' => '帳號只能包含文字、數字與 . _ @ + -'];
    if (!isset(ACC_ROLES[$role])) return ['error' => '未知的角色'];
    if ($p = acc_pwd_problem($pwd)) return ['error' => $p];
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $ret = acc_update(function (&$d) use ($login, $name, $role, $hash, $by, $mustChange) {
        foreach ($d['accounts'] as $a) if ($a['login'] === $login) return ['error' => '這個帳號已經存在'];
        $id = 'u_' . bin2hex(random_bytes(5));
        $d['accounts'][$id] = ['login' => $login, 'name' => ($name !== '' ? $name : $login), 'role' => $role,
            'hash' => $hash, 'mustChange' => $mustChange, 'disabled' => false,
            'createdAt' => time(), 'createdBy' => $by, 'lastLogin' => 0];
        return ['ok' => true, 'id' => $id];
    });
    if (!empty($ret['ok'])) audit($by, 'account.create', $login, ['role' => $role]);
    return $ret;
}

function acc_set_password($id, $pwd, $mustChange, $by) {
    if ($p = acc_pwd_problem($pwd)) return ['error' => $p];
    $hash = password_hash($pwd, PASSWORD_DEFAULT);
    $login = acc_update(function (&$d) use ($id, $hash, $mustChange) {
        if (!isset($d['accounts'][$id])) return null;
        $d['accounts'][$id]['hash'] = $hash;
        $d['accounts'][$id]['mustChange'] = $mustChange;
        return $d['accounts'][$id]['login'];
    });
    if ($login === null) return ['error' => '找不到這個帳號'];
    acc_fail_clear($login);
    audit($by, $mustChange ? 'account.reset_password' : 'account.change_password', $login);
    return ['ok' => true];
}

function acc_set_disabled($id, $disabled, $by) {
    $r = acc_update(function (&$d) use ($id, $disabled) {
        if (!isset($d['accounts'][$id])) return ['error' => '找不到這個帳號'];
        $a = $d['accounts'][$id];
        /* 不能停用最後一位開發者,否則沒有人能管理帳號 */
        if ($disabled && $a['role'] === 'dev') {
            $active = count(array_filter($d['accounts'], fn($x) => $x['role'] === 'dev' && empty($x['disabled'])));
            if ($active <= 1) return ['error' => '這是最後一位啟用中的開發者,不能停用'];
        }
        $d['accounts'][$id]['disabled'] = (bool)$disabled;
        return ['ok' => true, 'login' => $a['login']];
    });
    if (!empty($r['ok'])) audit($by, $disabled ? 'account.disable' : 'account.enable', $r['login']);
    return $r;
}

/* ---------------- 登入節流 ---------------- */

/** 正式站在 nginx 反向代理後面,REMOTE_ADDR 是代理的 IP;nginx 以 X-Real-IP 帶真實來源(與 ga_client_ip 同) */
function acc_client_ip() {
    $trustProxy = !defined('TRUST_X_REAL_IP') || TRUST_X_REAL_IP;   // 沒有反向代理時在 config.php 設 false,免得客戶端自己塞標頭
    $ip = $trustProxy ? ($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')) : ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function acc_fail_rw(callable $fn) {
    stats_ensure_dir();
    $fp = fopen(acc_fail_path(), 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true) ?: [];
    $now = time();
    foreach ($d as $k => $v) if ($now - ($v['t'] ?? 0) > ACC_LOCK_SECS) unset($d[$k]);   // 過期清掉
    $ret = $fn($d, $now);
    json_rewrite($fp, $d);
    flock($fp, LOCK_UN); fclose($fp);
    return $ret;
}

/** 還在鎖定中就回傳剩餘秒數,否則 0 */
function acc_locked_for($login) {
    $ku = 'u:' . acc_norm_login($login); $ki = 'ip:' . acc_client_ip();
    return (int)acc_fail_rw(function (&$d, $now) use ($ku, $ki) {
        $left = 0;
        if (($d[$ku]['n'] ?? 0) >= ACC_FAIL_MAX)    $left = max($left, ACC_LOCK_SECS - ($now - $d[$ku]['t']));
        if (($d[$ki]['n'] ?? 0) >= ACC_FAIL_IP_MAX) $left = max($left, ACC_LOCK_SECS - ($now - $d[$ki]['t']));
        return $left;
    });
}

function acc_fail_add($login) {
    $ku = 'u:' . acc_norm_login($login); $ki = 'ip:' . acc_client_ip();
    acc_fail_rw(function (&$d, $now) use ($ku, $ki) {
        foreach ([$ku, $ki] as $k) {
            if (!isset($d[$k])) $d[$k] = ['n' => 0, 't' => $now];
            $d[$k]['n']++;
            $d[$k]['t'] = $now;   // 以最後一次失敗起算鎖定時間
        }
    });
}

function acc_fail_clear($login) {
    $ku = 'u:' . acc_norm_login($login);
    acc_fail_rw(function (&$d) use ($ku) { unset($d[$ku]); });
}

/**
 * 驗證帳密;成功回傳帳號陣列,失敗回傳 ['error'=>..]
 * 錯誤訊息刻意不區分「沒有這個帳號」與「密碼錯」,避免被用來探測帳號
 */
function acc_verify($login, $pwd) {
    $login = acc_norm_login($login);
    if ($login === '' || (string)$pwd === '') return ['error' => '請輸入帳號與密碼'];
    if ($left = acc_locked_for($login)) return ['error' => '嘗試次數過多,請 ' . ceil($left / 60) . ' 分鐘後再試'];
    $a = acc_find($login);
    /* 帳號不存在時也算一次 bcrypt,回應時間才不會洩漏「有沒有這個帳號」 */
    if (!password_verify((string)$pwd, $a['hash'] ?? ACC_DUMMY_HASH) || !$a) {
        acc_fail_add($login);
        audit($login, 'login.fail', $login);
        return ['error' => '帳號或密碼不正確'];
    }
    /* 停用的帳號:訊息與密碼錯相同,不讓人用它探測密碼;管理者從稽核紀錄看得到 login.disabled */
    if (!empty($a['disabled'])) { acc_fail_add($login); audit($login, 'login.disabled', $login); return ['error' => '帳號或密碼不正確']; }
    acc_fail_clear($login);
    /* 一次寫入:最後登入時間,以及雜湊演算法升級時順便重算 */
    $nh = password_needs_rehash($a['hash'], PASSWORD_DEFAULT) ? password_hash((string)$pwd, PASSWORD_DEFAULT) : null;
    acc_update(function (&$d) use ($a, $nh) {
        $d['accounts'][$a['id']]['lastLogin'] = time();
        if ($nh) $d['accounts'][$a['id']]['hash'] = $nh;
    });
    audit($login, 'login.ok', $login);
    return $a;
}

/* ---------------- session ---------------- */

function auth_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https,
                               'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function auth_login($acc) {
    session_regenerate_id(true);          // 防 session fixation
    foreach (['acc_once', 'acc_err', 'auth_msg', 'auth_err'] as $k) unset($_SESSION[$k]);   // 不帶前一個人的殘留
    $_SESSION['acc'] = ['id' => $acc['id']];
}

function auth_logout() {
    /* 整個清掉:acc_once 裡可能還有沒看過的一次性初始密碼 */
    $_SESSION = [];
    session_regenerate_id(true);
}

/** 目前登入者(每次重讀帳號檔);停用或被刪的帳號會被踢出 */
function auth_user() {
    $id = $_SESSION['acc']['id'] ?? '';
    if ($id === '') return null;
    $a = acc_get($id);
    if (!$a || !empty($a['disabled'])) {
        unset($_SESSION['acc']);
        $_SESSION['auth_err'] = '這個帳號已被停用或移除,請洽管理者';
        return null;
    }
    return $a;
}

function auth_is_dev($u) { return $u && $u['role'] === 'dev'; }

/**
 * 表單 CSRF 防護:POST 必須來自同一個網站。
 * 配合 SameSite=Lax 的 session cookie,不必在每張表單塞 token。
 */
function auth_same_origin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $src  = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($src === '' || $src === 'null') {
        /* 沒有 Origin/Referer 時退而看 Fetch Metadata;兩者都沒有就放行(舊瀏覽器) */
        $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        return $site === '' || $site === 'same-origin' || $site === 'none';
    }
    return strtolower((string)parse_url($src, PHP_URL_HOST)) === preg_replace('/:\d+$/', '', $host);
}

/* ---------------- 稽核紀錄 ---------------- */

const AUDIT_ROTATE_BYTES = 5 * 1024 * 1024;

function audit($actor, $action, $target = '', $detail = []) {
    stats_ensure_dir();
    $p = audit_path();
    if (is_file($p) && filesize($p) > AUDIT_ROTATE_BYTES) @rename($p, $p . '.1');   // 只留上一份
    $line = json_enc(['t' => time(), 'actor' => (string)$actor, 'action' => $action, 'target' => (string)$target,
                      'ip' => acc_client_ip(), 'detail' => $detail], JSON_UNESCAPED_UNICODE);
    if ($line !== false) @file_put_contents(audit_path(), $line . "\n", FILE_APPEND | LOCK_EX);
}

/** 最新在前的最後 $n 筆 */
function audit_tail($n = 300) {
    $p = audit_path();
    if (!is_file($p)) return [];
    $lines = array_slice(file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$n);
    return array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), $lines))));
}

/** 目前登入者的稽核名稱 */
function auth_actor($u) { return $u ? $u['login'] : '(未登入)'; }

/* ---------------- 後台頁面共用的登入流程 ---------------- */

/**
 * 處理登出、登入、首次改密碼;回傳頁面要用的狀態:
 *   user        目前登入者(可能為 null)
 *   authed      可以使用後台(已登入、角色符合、不需改密碼)
 *   mustChange  已登入但必須先改密碼
 *   denied      已登入但角色不符
 *   err / msg   要顯示的訊息
 * $self 是本頁檔名,用於重新導向(POST-Redirect-GET,避免重新整理時重送密碼)
 */
function auth_handle(array $roles, $self) {
    auth_session_start();
    $S = ['user' => null, 'authed' => false, 'mustChange' => false, 'denied' => false, 'err' => '', 'msg' => ''];

    if (isset($_GET['logout'])) {
        $u = auth_user();
        if ($u) audit($u['login'], 'logout', $u['login']);
        auth_logout();
        header('Location: ' . $self); exit;
    }
    if (!auth_same_origin()) { http_response_code(403); exit('請求來源不符'); }

    $post = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['auth'] ?? '') : '';
    if ($post === 'login') {
        $r = acc_verify($_POST['login'] ?? '', $_POST['pwd'] ?? '');
        if (!empty($r['error'])) { $_SESSION['auth_err'] = $r['error']; header('Location: ' . $self); exit; }
        auth_login($r);
        header('Location: ' . $self); exit;
    }

    $u = auth_user();
    $S['user'] = $u;
    if ($u && $post === 'change_pwd') {
        $cur = (string)($_POST['cur'] ?? ''); $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if (!password_verify($cur, $u['hash'])) $e = '目前的密碼不正確';
        elseif ($p1 !== $p2)                    $e = '兩次輸入的新密碼不一致';
        elseif ($p1 === $cur)                   $e = '新密碼不能與目前的相同';
        else { $r = acc_set_password($u['id'], $p1, false, $u['login']); $e = $r['error'] ?? ''; }
        if ($e !== '') $_SESSION['auth_err'] = $e; else $_SESSION['auth_msg'] = '密碼已更新';
        /* 回到送出表單的同一頁(例如「我的帳號」分頁):只用本頁檔名加原本的 query string,不拿 REQUEST_URI 當導向目標 */
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        header('Location: ' . $self . ($qs !== '' ? '?' . $qs : '')); exit;
    }

    $S['err'] = $_SESSION['auth_err'] ?? ''; unset($_SESSION['auth_err']);
    $S['msg'] = $_SESSION['auth_msg'] ?? ''; unset($_SESSION['auth_msg']);
    if ($u) {
        if (!in_array($u['role'], $roles, true)) $S['denied'] = true;
        elseif (!empty($u['mustChange']))        $S['mustChange'] = true;
        else                                     $S['authed'] = true;
    }
    return $S;
}

/** 登入框內容(沿用各頁既有的 .login-box / .login-err 樣式) */
function auth_box_html(array $S, $loginHint) {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $o = '';
    if ($S['err']) $o .= '<div class="login-err">' . $h($S['err']) . '</div>';
    if ($S['msg']) $o .= '<div class="login-ok">' . $h($S['msg']) . '</div>';
    if ($S['denied']) {
        $o .= '<p style="font-size:13px;line-height:1.9">目前登入的帳號 <b>' . $h($S['user']['login']) . '</b>(' . $h(ACC_ROLES[$S['user']['role']] ?? '') . ')'
            . '沒有權限進入這個頁面。</p><p><a href="?logout=1">登出,改用其他帳號</a></p>';
    } elseif ($S['mustChange']) {
        $o .= '<p style="font-size:13px;line-height:1.9">歡迎,' . $h($S['user']['name']) . '。<br>這是第一次登入(或密碼剛被重設),請先設定自己的密碼。</p>'
            . '<form method="post" autocomplete="off"><input type="hidden" name="auth" value="change_pwd">'
            . '<label class="fld"><span>目前的密碼（初始密碼）</span><input type="password" name="cur" autocomplete="current-password" required></label>'
            . '<label class="fld"><span>新密碼<em>至少 ' . ACC_MIN_PWD . ' 個字元</em></span><input type="password" name="p1" autocomplete="new-password" required></label>'
            . '<label class="fld"><span>再輸入一次新密碼</span><input type="password" name="p2" autocomplete="new-password" required></label>'
            . '<button type="submit">設定密碼並進入</button></form>'
            . '<p style="margin-top:10px;font-size:12px"><a href="?logout=1">登出</a></p>';
    } else {
        $o .= '<form method="post"><input type="hidden" name="auth" value="login">'
            . '<label class="fld"><span>' . $h($loginHint) . '</span><input type="text" name="login" autocomplete="username" autofocus required></label>'
            . '<label class="fld"><span>密碼</span><input type="password" name="pwd" autocomplete="current-password" required></label>'
            . '<button type="submit">登入</button></form>';
    }
    return $o;
}

/** 已登入者自行改密碼的表單(放在後台的「我的帳號」區) */
function auth_change_form_html() {
    return '<form method="post" autocomplete="off" class="acc-pwd-form"><input type="hidden" name="auth" value="change_pwd">'
         . '<label class="fld"><span>目前的密碼</span><input type="password" name="cur" autocomplete="current-password" required></label>'
         . '<label class="fld"><span>新密碼<em>至少 ' . ACC_MIN_PWD . ' 個字元</em></span><input type="password" name="p1" autocomplete="new-password" required></label>'
         . '<label class="fld"><span>再輸入一次新密碼</span><input type="password" name="p2" autocomplete="new-password" required></label>'
         . '<button type="submit" class="btn primary">更新密碼</button></form>';
}

/* ---------------- 課程保留期限 ---------------- */

const COURSE_KEEP_DAYS = 180;                 // 課程自建立起保留半年
const COURSE_WARN_DAYS = 30;                  // 到期前這麼多天開始提醒
const COURSE_EXTEND_DAYS = 180;               // 授課教師每次可延長的天數
const COURSE_MAX_AHEAD_DAYS = 365;            // 到期日最遠只能排到今天起這麼多天後(學年課夠用,又不會被無限往後推)
/** 到期時間;createdAt 缺失(手動編輯過的舊資料)回傳 null,表示不知道、不清理。教師延長過就以 keepUntil 為準 */
function course_expires_at($c) {
    $t = (int)($c['createdAt'] ?? 0);
    return $t > 0 ? max($t + COURSE_KEEP_DAYS * 86400, (int)($c['keepUntil'] ?? 0)) : null;
}

/**
 * 延長課程的保留期限:從目前的到期日往後加 COURSE_EXTEND_DAYS 天,最遠到今天起 COURSE_MAX_AHEAD_DAYS 天後。
 * 回傳 ['ok'=>true,'from'=>舊到期日,'to'=>新到期日],或 ['error'=>..]
 */
function course_extend($cid, $actor) {
    $now = time();
    $r = school_update(function (&$s) use ($cid, $now) {
        $c = &school_find_course($s, $cid);
        if ($c === null) return ['error' => '找不到這門課'];
        $from = course_expires_at($c);
        if ($from === null) return ['error' => '這門課沒有建立日期,不會自動刪除,不需要延長'];
        $to = min(max($from, $now) + COURSE_EXTEND_DAYS * 86400, $now + COURSE_MAX_AHEAD_DAYS * 86400);
        if ($to - $from < 86400) return ['error' => '保留期限已經排到 ' . date('Y/m/d', $from) . ',最遠只能排到一年後;之後再回來延長即可'];
        $c['keepUntil'] = $to;
        return ['ok' => true, 'from' => $from, 'to' => $to, 'name' => $c['name']];
    });
    if (!empty($r['ok'])) audit($actor, 'course.extend', $r['name'], ['course' => $cid, 'from' => date('Y-m-d', $r['from']), 'to' => date('Y-m-d', $r['to'])]);
    return $r ?: ['error' => '延長失敗'];
}

/**
 * 刪除已過保留期限的課程(連同解析、逐字稿、學習分析)。
 * 後台每次載入都會呼叫,但一天只真的執行一次;回傳這次刪掉的課程名稱。
 */
function course_cleanup() {
    stats_ensure_dir();
    $mark = __DIR__ . '/data/.course_cleanup';
    if (is_file($mark) && time() - (int)filemtime($mark) < 86400) return [];
    @touch($mark);
    $now = time();
    $gone = school_update(function (&$s) use ($now) {
        $gone = [];
        $s['courses'] = array_values(array_filter($s['courses'], function ($c) use ($now, &$gone) {
            $exp = course_expires_at($c);
            if ($exp === null || $exp > $now) return true;
            $gone[] = ['id' => $c['id'], 'name' => $c['name'], 'createdAt' => $c['createdAt'] ?? 0];
            return false;
        }));
        return $gone;
    }) ?: [];
    foreach ($gone as $g) {
        course_purge_files($g['id']);
        audit('system', 'course.expire', $g['name'], ['course' => $g['id'], 'createdAt' => date('Y-m-d', $g['createdAt'])]);
    }
    return array_column($gone, 'name');
}

/* ---------------- 開發者代改他人課程的紀錄 ---------------- */

const AUDIT_ACT_LABEL = [
    'analysis_edit'   => '審定／修改解析', 'analysis_run' => '重新解析',
    'briefing_edit'   => '修改導讀',       'briefing_run' => '重新生成導讀',
    'text_add'        => '新增或修改文本', 'text_del'     => '移除文本',
    'student_add'     => '新增學生',       'student_del'  => '移除學生', 'students_import' => '匯入學生名單',
    'code_regen'      => '重新產生邀請碼', 'course_del'   => '刪除課程',
    'course_extend'   => '延長課程期限', 'course_rename' => '修改課名',
    'review_student'  => '產生單一學生分析', 'review_class' => '產生全班綜覽',
    'teacher_add'     => '加入授課教師',   'teacher_remove' => '移除授課教師',
];

/**
 * 開發者在「不是自己建立的課程」做了修改就記一筆;自己的課不記。
 * 對象記課程名稱與擁有者,細節記動作與關鍵欄位(學號、文本 id),不記文本或解析內容本身。
 */
function audit_dev_edit($me, $course, $act, array $post = []) {
    $ids = $course ? course_teacher_ids($course) : [];
    if (!auth_is_dev($me) || !$course || in_array($me['id'], $ids, true)) return;   // 自己也是授課教師就不算代改
    if (!isset(AUDIT_ACT_LABEL[$act]) || $act === 'course_del') return;             // 未知動作不記;刪課已有 course.delete
    $logins = array_filter(array_map(fn($id) => acc_get($id)['login'] ?? '', $ids));
    $keep = array_intersect_key($post, array_flip(['sid', 'text_id', 'doc', 'title', 'name', 'login', 'tid']));
    audit($me['login'], 'dev.edit', $course['name'] . '(' . ($logins ? implode('、', $logins) : '無授課教師') . ')',
          ['act' => $act, 'label' => AUDIT_ACT_LABEL[$act] ?? $act, 'course' => $course['id']] + $keep);
}
