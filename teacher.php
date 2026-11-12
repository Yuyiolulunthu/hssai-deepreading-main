<?php
/**
 * 讀伴 ・ 教師後台
 * — 我的課程：名單邀請（邀請碼、貼上名單匯入/手動新增/匯出 CSV)、文本解析、學習紀錄
 * — 使用統計：API 次數、token 花費估計、網站瀏覽數、每日趨勢
 * 登入:教師帳號(由開發者在 dev.php 建立);開發者帳號也可進入並看到所有課程。
 * 教師只看得到、也只能操作自己是授課教師的課程(course.teachers;建立者 course.owner 另可刪課)。
 */
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/stats_lib.php';
require_once __DIR__ . '/school_lib.php';
require_once __DIR__ . '/prompt_lib.php';
require_once __DIR__ . '/analysis_lib.php';
require_once __DIR__ . '/review_lib.php';
require_once __DIR__ . '/briefing_lib.php';
require_once __DIR__ . '/stu_lib.php';
require_once __DIR__ . '/admin_ui.php';

$configured = file_exists(__DIR__ . '/config.php');
if ($configured) require_once __DIR__ . '/config.php';
$priceIn  = defined('PRICE_IN_PER_M')  ? PRICE_IN_PER_M  : 3.0;
$priceOut = defined('PRICE_OUT_PER_M') ? PRICE_OUT_PER_M : 15.0;
$model    = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : '（未設定）';

$AUTH   = auth_handle(['teacher', 'dev'], 'teacher.php');
$me     = $AUTH['user'];
$authed = $AUTH['authed'];
$isDev  = auth_is_dev($me);
$actor  = auth_actor($me);
$ACC    = [];   // id => 帳號,顯示名稱用
if ($authed) foreach (acc_list() as $a) $ACC[$a['id']] = $a;

/** 這個人能不能看/改這門課:教師限自己列在授課教師名單的課,開發者全部 */
function can_course($c, $me) {
    if (!$c || !$me) return false;
    return auth_is_dev($me) || in_array($me['id'], course_teacher_ids($c), true);
}
/** 依 id 取課程並檢查權限;沒有權限一律當作不存在 */
function my_course($cid, $me) {
    foreach (school_read()['courses'] as $c) if ($c['id'] === $cid) return can_course($c, $me) ? $c : null;
    return null;
}
function h($x) { return htmlspecialchars((string)$x, ENT_QUOTES); }
/** 放進 onclick/onsubmit 之類 inline JS 字串的值:先做成 JS 字串常值(含引號),再做 HTML 屬性跳脫 */
function js($x) { return h(json_encode((string)$x, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)); }
function n($x) { return number_format((int)$x); }
function mdview_php($md) {
    $h = htmlspecialchars((string)$md);
    $h = preg_replace('/^### (.+)$/m', '<div class="a-h4">$1</div>', $h);
    $h = preg_replace('/^## (.+)$/m', '<div class="a-h3">$1</div>', $h);
    $h = preg_replace('/^# (.+)$/m', '<div class="a-h3">$1</div>', $h);
    $h = preg_replace('/\*\*([^*\n]+)\*\*/', '<b>$1</b>', $h);
    $h = preg_replace('/^[-*] /m', '・', $h);
    return nl2br($h);
}

/* ================= 已登入：處理管理動作 ================= */
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = $_POST['act'];
    $cid = $_POST['course'] ?? '';
    /* 除了建立新課程,所有動作都針對某一門課——先確認是自己的課。
       不是自己的就當作不存在,不透露那門課是否存在。 */
    $theCourse = $act !== 'course_add' ? my_course($cid, $me) : null;
    if ($act !== 'course_add' && !$theCourse) {
        /* 課真的不存在(例如同事剛刪掉、或自己被移除後仍開著舊分頁)與越權嘗試分開記 */
        $exists = (bool)array_filter(school_read()['courses'], fn($c) => $c['id'] === $cid);
        audit($actor, $exists ? 'course.denied' : 'course.gone', $cid, ['act' => $act]);
        header('Location: teacher.php'); exit;
    }
    /* 刪課只有建立者(或開發者)可以;其他授課教師權限平等,但不能刪掉別人建的課 */
    if ($act === 'course_del' && !$isDev && ($theCourse['owner'] ?? '') !== $me['id']) {
        audit($actor, 'course.denied', $cid, ['act' => $act, 'why' => 'not_owner']);
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=students&terr=' . urlencode('只有建立這門課的老師可以刪除課程。')); exit;
    }
    /* 開發者動到別人的課:一律留紀錄(原則上老師自己處理,偶爾由開發者代改) */
    if ($theCourse && $isDev) audit_dev_edit($me, $theCourse, $act, $_POST);
    if ($act === 'review_student') {
        /* 學習分析:單一學生單篇(同步執行,約 20-40 秒) */
        @set_time_limit(180);
        $sid = trim((string)($_POST['sid'] ?? '')); $doc = trim((string)($_POST['doc'] ?? ''));
        $cc = $theCourse;
        $tr = transcript_get($cid, $sid, $doc);
        $back = 'teacher.php?course=' . urlencode($cid) . '&tab=detail&sid=' . urlencode($sid) . '&doc=' . urlencode($doc);
        if (!$tr || sr_student_turns($tr) < 1) { header('Location: ' . $back . '&rerr=' . urlencode('這位學生在這篇還沒有任何回應')); exit; }
        $r = sr_run_student($cid, $cc, $tr, $me['name']);
        header('Location: ' . $back . ($r['ok'] ? '&rdone=1' : '&rerr=' . urlencode($r['error']))); exit;
    }
    if ($act === 'review_class') {
        /* 學習分析:整門課單篇的全班綜覽 */
        @set_time_limit(180);
        $tid = ta_safe($_POST['text_id'] ?? '');
        $cc = $theCourse;
        $tx = null; foreach (school_norm_texts($cc['texts'] ?? []) as $t2) if ($t2['id'] === $tid) { $tx = $t2; break; }
        $back = 'teacher.php?course=' . urlencode($cid) . '&tab=texts&cls=' . urlencode($tid);
        if (!$tx) { header('Location: ' . $back . '&rerr=' . urlencode('找不到這篇文本')); exit; }
        $r = sr_run_class($cid, $cc, $tx, $me['name']);
        header('Location: ' . $back . ($r['ok'] ? '&rdone=1' : '&rerr=' . urlencode($r['error']))); exit;
    }
    if ($act === 'analysis_edit') {
        /* 教師審定:儲存編修後的解析,作為學生對話的閱讀地圖 */
        $tid = ta_safe($_POST['text_id'] ?? '');
        $md  = trim(str_replace("\r\n", "\n", (string)($_POST['analysis_md'] ?? '')));
        $rec = ta_get($cid, $tid) ?: [];
        $txt = ta_text($cid, $tid);
        if ($md !== '' && $txt) {
            $rec['status']   = 'done';
            $rec['md']       = $md;
            $rec['edited']   = true;
            $rec['editedBy'] = $me['name'];
            $rec['stale']    = false;
            $rec['bodyHash'] = md5($txt['text']);
            $rec['ts']       = time();
            ta_put($cid, $tid, $rec);
            /* 存檔後收起編輯框回到清單:該列會變成「已審定」,看得出確實生效 */
            header('Location: teacher.php?course=' . urlencode($cid) . '&tab=texts&asaved=' . urlencode($tid)); exit;
        }
        /* 內容空白等於沒東西可存,保持展開並說明原因 */
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=texts&an=' . urlencode($tid) . '&aerr=1#an-' . $tid); exit;
    } elseif ($act === 'briefing_edit') {
        /* 教師直接修改導讀:全班共用這一份,之後不會被自動覆蓋 */
        $tid = ta_safe($_POST['text_id'] ?? '');
        $txt = ta_text($cid, $tid);
        $nl  = fn($k) => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
        $sum = $nl('summary'); $bg = $nl('background');
        if ($sum === '' || $bg === '' || !$txt) {
            header('Location: teacher.php?course=' . urlencode($cid) . '&tab=texts&bf=' . urlencode($tid) . '&berr=1#bf-' . $tid); exit;
        }
        $ar = ta_get($cid, $tid); $by = $me['name'];
        $mm = bf_text_to_mindmap($_POST['mindmap'] ?? '');
        bf_update($cid, $tid, function ($rec) use ($sum, $bg, $mm, $txt, $ar, $by) {
            $new = ['summary' => u_cut($sum, 800), 'background' => u_cut($bg, 800), 'mindmap' => $mm,
                    'bodyHash' => md5($txt['text']), 'mapTs' => (int)($ar['ts'] ?? 0),
                    'edited' => true, 'editedBy' => $by, 'ts' => time()];
            if (!empty($rec['gen'])) $new['gen'] = $rec['gen'];   // 生成中的就讓它跑完(完成時會保留教師版本)
            return $new;
        });
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=texts&bf=' . urlencode($tid) . '&bsaved=1#bf-' . $tid); exit;
    } elseif ($act === 'course_add') {
        $name = u_cut(trim($_POST['name'] ?? ''), 40);
        if ($name !== '') {
            $newId = 'c_' . bin2hex(random_bytes(4));
            /* 課程歸建立者所有;教師名稱取自帳號,不再讓人自由填寫 */
            $owner = $me['id']; $tname = $me['name'];
            school_update(function (&$s) use ($name, $tname, $owner, $newId) {
                $s['courses'][] = ['id' => $newId, 'name' => $name, 'teacher' => $tname, 'owner' => $owner, 'teachers' => [$owner],
                    'code' => school_gen_code(), 'createdAt' => time(), 'texts' => [], 'students' => []];
                return null;
            });
            audit($actor, 'course.create', $newId, ['name' => $name]);
            header('Location: teacher.php?course=' . $newId . '&tab=invite'); exit;
        }
    } elseif ($act === 'teacher_add') {
        /* 任何一位授課教師都能加人(教授決定:授課教師權限完全平等) */
        $t = acc_find($_POST['login'] ?? '');
        $back = 'teacher.php?course=' . urlencode($cid) . '&tab=students';
        if (!$t || $t['role'] !== 'teacher' || !empty($t['disabled'])) {
            header('Location: ' . $back . '&terr=' . urlencode('找不到這個教師帳號。請確認帳號正確,或請開發者先建立帳號。') . '#teachers'); exit;
        }
        $added = school_update(function (&$s) use ($cid, $t) {
            $c = &school_find_course($s, $cid);
            if ($c === null) return false;
            $ids = course_teacher_ids($c);
            if (in_array($t['id'], $ids, true)) return false;
            $ids[] = $t['id'];
            $c['teachers'] = $ids;
            if (empty($c['owner'])) $c['owner'] = $t['id'];   // 舊資料沒有建立者:第一位加入的老師補上
            return true;
        });
        if ($added) audit($actor, 'course.teacher_add', $theCourse['name'], ['teacher' => $t['login']]);
        header('Location: ' . $back . ($added ? '&tok=' . urlencode('已加入 ' . $t['name']) : '&terr=' . urlencode($t['name'] . ' 已經是這門課的教師')) . '#teachers'); exit;
    } elseif ($act === 'teacher_remove') {
        $tid = (string)($_POST['tid'] ?? '');
        $cname = $theCourse['name'];
        $r = school_update(function (&$s) use ($cid, $tid) {
            $c = &school_find_course($s, $cid);
            if ($c === null) return 'nf';
            $ids = course_teacher_ids($c);
            if (!in_array($tid, $ids, true)) return 'nf';
            if ($tid === ($c['owner'] ?? '')) return 'owner';   // 建立者不能被移除或退出,否則沒有老師能刪這門課
            if (count($ids) <= 1) return 'last';          // 課程不能沒有老師
            $c['teachers'] = array_values(array_diff($ids, [$tid]));
            return 'ok';
        });
        $back = 'teacher.php?course=' . urlencode($cid) . '&tab=students';
        if ($r === 'ok') {
            audit($actor, 'course.teacher_remove', $cname, ['teacher' => $ACC[$tid]['login'] ?? $tid]);
            /* 移除的是自己:已經看不到這門課了,回首頁 */
            if ($tid === $me['id'] && !$isDev) { header('Location: teacher.php?left=' . urlencode($cname)); exit; }
            header('Location: ' . $back . '&tok=' . urlencode('已移除 ' . ($ACC[$tid]['name'] ?? '')) . '#teachers'); exit;
        }
        $why = ['last' => '這是這門課唯一的老師,不能移除。要結束這門課請改用「刪除課程」。',
                'owner' => '建立者不能被移除或退出。要結束這門課請用「刪除課程」;要換人負責請洽開發者。'];
        header('Location: ' . $back . '&terr=' . urlencode($why[$r] ?? '找不到這位老師') . '#teachers'); exit;
    } elseif ($act === 'course_del') {
        school_update(function (&$s) use ($cid) {
            $s['courses'] = array_values(array_filter($s['courses'], fn($c) => $c['id'] !== $cid));
            return null;
        });
        course_purge_files($cid);
        audit($actor, 'course.delete', $theCourse['name'], ['course' => $cid]);
        header('Location: teacher.php'); exit;
    } elseif ($act === 'course_rename') {
        /* 修改課名:任何一位授課教師都可以(權限平等);名稱規則與建立時相同 */
        $name = u_cut(trim((string)($_POST['name'] ?? '')), 40);
        $back = 'teacher.php?course=' . urlencode($cid) . '&tab=' . urlencode((string)($_POST['tab'] ?? 'students'));
        if ($name === '') { header('Location: ' . $back . '&xerr=' . urlencode('課程名稱不可空白')); exit; }
        $old = $theCourse['name'];
        if ($name !== $old) {
            school_update(function (&$s) use ($cid, $name) {
                $c = &school_find_course($s, $cid);
                if ($c !== null) $c['name'] = $name;
                return null;
            });
            audit($actor, 'course.rename', $name, ['course' => $cid, 'from' => $old, 'to' => $name]);
        }
        header('Location: ' . $back . '&xok=' . urlencode('課程名稱已改為「' . $name . '」')); exit;
    } elseif ($act === 'course_extend') {
        /* 延長保留期限:任何一位授課教師都可以(權限平等);上限與天數見 course_extend() */
        $r = course_extend($cid, $actor);
        $back = 'teacher.php?course=' . urlencode($cid) . '&tab=' . urlencode((string)($_POST['tab'] ?? 'students'));
        header('Location: ' . $back . (empty($r['error'])
            ? '&xok=' . urlencode('已延長保留期限：' . date('Y/m/d', $r['from']) . ' → ' . date('Y/m/d', $r['to']))
            : '&xerr=' . urlencode($r['error']))); exit;
    } elseif ($act === 'code_regen') {
        school_update(function (&$s) use ($cid) {
            $c = &school_find_course($s, $cid);
            if ($c !== null) $c['code'] = school_gen_code();
            return null;
        });
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=invite'); exit;
    } elseif ($act === 'students_import') {
        $lines = preg_split('/\r\n|\r|\n/', (string)($_POST['bulk'] ?? ''));
        $added = 0;
        school_update(function (&$s) use ($cid, $lines, &$added) {
            $c = &school_find_course($s, $cid);
            if ($c === null) return null;
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '') continue;
                $parts = preg_split('/[,\t,、;;]+|\s{2,}|\s/u', $ln, 2);
                $sid = u_cut(trim($parts[0] ?? ''), 30);
                $nm  = u_cut(trim($parts[1] ?? ''), 30);
                if ($sid === '' || $sid === '學號') continue;
                if (!isset($c['students'][$sid])) {
                    $c['students'][$sid] = ['name' => ($nm !== '' ? $nm : '（未填姓名）'), 'self' => false,
                        'calls' => 0, 'turns' => 0, 'in' => 0, 'out' => 0, 'last' => 0, 'texts' => []];
                    $added++;
                } elseif ($nm !== '') {
                    $c['students'][$sid]['name'] = $nm;
                    $c['students'][$sid]['self'] = false;
                }
            }
            return null;
        });
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=students&ok=' . $added); exit;
    } elseif ($act === 'student_add') {
        $sid = u_cut(trim($_POST['sid'] ?? ''), 30);
        $nm  = u_cut(trim($_POST['name'] ?? ''), 30);
        if ($sid !== '') {
            school_update(function (&$s) use ($cid, $sid, $nm) {
                $c = &school_find_course($s, $cid);
                if ($c === null) return null;
                if (!isset($c['students'][$sid])) {
                    $c['students'][$sid] = ['name' => ($nm !== '' ? $nm : '（未填姓名）'), 'self' => false,
                        'calls' => 0, 'turns' => 0, 'in' => 0, 'out' => 0, 'last' => 0, 'texts' => []];
                } else if ($nm !== '') { $c['students'][$sid]['name'] = $nm; $c['students'][$sid]['self'] = false; }
                return null;
            });
        }
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=students'); exit;
    } elseif ($act === 'student_del') {
        $sid = $_POST['sid'] ?? '';
        $acct = school_update(function (&$s) use ($cid, $sid) {
            $c = &school_find_course($s, $cid);
            $a = $c['students'][$sid]['acct'] ?? '';
            if ($c !== null) unset($c['students'][$sid]);
            return $a;
        });
        stu_unlink($acct, $cid);   // 學生帳號不再列出這門課
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=students'); exit;
    } elseif ($act === 'student_pwreset') {
        /* 學生忘記密碼:重設成隨機臨時密碼,只顯示這一次;學生登入後必須自己改 */
        $sid = (string)($_POST['sid'] ?? '');
        $acctId = (string)($theCourse['students'][$sid]['acct'] ?? '');
        $sa = $acctId !== '' ? stu_get($acctId) : null;
        $back = 'teacher.php?course=' . urlencode($cid) . '&tab=students';
        if (!$sa) { header('Location: ' . $back . '&terr=' . urlencode('這位學生還沒有建立帳號,不需要重設密碼。')); exit; }
        $tmp = acc_gen_password(10);
        stu_set_password($acctId, $tmp, true);
        audit($actor, 'student.pwreset', $sa['email'], ['course' => $cid, 'sid' => $sid]);
        $_SESSION['stu_once'] = ['sid' => $sid, 'email' => $sa['email'], 'pwd' => $tmp];
        header('Location: ' . $back); exit;
    } elseif ($act === 'text_add') {
        $ti = u_cut(trim($_POST['title'] ?? ''), 60);
        $tb = trim((string)($_POST['body'] ?? ''));
        if (course_text_load($tb) > COURSE_MAX_LOAD) {   // 中文每篇上限 10 萬字;英文以讀取量換算(school_lib)
            header('Location: teacher.php?course=' . urlencode($cid) . '&tab=texts&tlong=' . mb_strlen($tb)); exit;
        }
        $eid = trim((string)($_POST['text_id'] ?? ''));
        if ($ti !== '') {
            $by = $me['name'];
            school_update(function (&$s) use ($cid, $ti, $tb, $eid, $by) {
                $c = &school_find_course($s, $cid);
                if ($c === null) return null;
                $list = school_norm_texts($c['texts'] ?? []);
                $found = false;
                foreach ($list as &$it) {
                    if (($eid !== '' && $it['id'] === $eid) || ($eid === '' && $it['title'] === $ti)) {
                        $it['title'] = $ti;
                        if ($tb !== '') $it['text'] = $tb;
                        $it['ts'] = time();
                        $it['by'] = $by;
                        $found = true;
                        break;
                    }
                }
                unset($it);
                if (!$found) {
                    $list[] = ['id' => 't_' . substr(md5(uniqid('', true)), 0, 8),
                               'title' => $ti, 'text' => $tb, 'ts' => time(), 'by' => $by];
                }
                $c['texts'] = $list;
                return null;
            });
            // 有內容就排入自動解析(教師已編修過的解析不會被覆蓋,只標示內容已變更)
            $savedId = '';
            foreach (school_read()['courses'] as $cc) {
                if ($cc['id'] !== $cid) continue;
                foreach (school_norm_texts($cc['texts'] ?? []) as $it) {
                    if (($eid !== '' && $it['id'] === $eid) || ($eid === '' && $it['title'] === $ti)) $savedId = $it['id'];
                    if ($it['title'] === $ti && trim((string)$it['text']) !== '') {
                        ta_mark_pending($cid, $it['id'], md5($it['text']));
                    }
                }
            }
            // 一併上傳的 pdf/docx 原檔:保存起來讓學生「看原檔」(txt/md 或沒選檔案就不動)
            if ($savedId !== '' && !empty($_FILES['orig'])) {
                $ext = school_save_orig($cid, $savedId, $_FILES['orig']);
                if ($ext !== '') school_update(function (&$s) use ($cid, $savedId, $ext) {
                    $c = &school_find_course($s, $cid);
                    if ($c === null) return null;
                    $list = school_norm_texts($c['texts'] ?? []);
                    foreach ($list as &$it) if ($it['id'] === $savedId) $it['orig'] = $ext;
                    unset($it);
                    $c['texts'] = $list;
                    return null;
                });
            }
        }
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=texts&tsaved=1'); exit;
    } elseif ($act === 'text_del') {
        $did = trim((string)($_POST['text_id'] ?? ''));
        ta_delete($cid, $did);
        bf_delete($cid, $did);
        school_remove_text_dir($cid, $did);
        school_update(function (&$s) use ($cid, $did) {
            $c = &school_find_course($s, $cid);
            if ($c !== null) {
                $c['texts'] = array_values(array_filter(school_norm_texts($c['texts'] ?? []),
                    fn($x) => $x['id'] !== $did));
            }
            return null;
        });
        header('Location: teacher.php?course=' . urlencode($cid) . '&tab=texts'); exit;
    }
}

/* ================= 讀取資料 ================= */
$expired = $authed ? course_cleanup() : [];
$school = $authed ? school_read() : ['courses' => []];
/* 側欄與所有 GET 檢視只看得到自己的課(開發者看得到全部) */
$school['courses'] = array_values(array_filter($school['courses'], fn($c) => can_course($c, $me)));
$curId  = $_GET['course'] ?? '';
$cur    = null;
foreach ($school['courses'] as $c) if ($c['id'] === $curId) { $cur = $c; break; }
$tab = $_GET['tab'] ?? ($cur ? 'students' : 'usage');
if (!$cur && ($_GET['tab'] ?? '') !== 'account') $tab = 'home';
if ($tab === 'invite') $tab = 'students';       // 邀請碼已併入「名單邀請」
if ($tab === 'ai') $tab = 'texts';              // AI 解析已併入指定文本

/* 解析 .md 匯出(每篇課程文本各自一份) */
if ($authed && $cur && ($_GET['dl_analysis'] ?? '') !== '') {
    $dtid = ta_safe($_GET['dl_analysis']);
    $rec = ta_get($cur['id'], $dtid);
    $txt = ta_text($cur['id'], $dtid);
    if ($rec && ($rec['status'] ?? '') === 'done' && $txt) {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($txt['title']) . '-AI解析.md"');
        echo "# 《{$txt['title']}》AI 文本解析(階段一)\n\n> "
           . (!empty($rec['edited']) ? '教師審定版' : 'AI 生成版') . ' ・ ' . date('Y-m-d H:i', $rec['ts']) . "\n\n" . $rec['md'];
        exit;
    }
}

/* CSV 匯出 */
if ($authed && $cur && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($cur['name']) . '-名單.csv"');
    echo "\xEF\xBB\xBF學號,姓名,帳號(Email),學生回應,分支,筆記泡泡,輸入tokens,輸出tokens,最後活動,加入方式\n";
    $TC = tr_summary($cur['id'])['bySid'];
    $stuAcc = stu_read()['accounts'];
    foreach ($cur['students'] as $sid => $st) {
        $c = $TC[$sid] ?? [];
        echo implode(',', [$sid, '"' . str_replace('"', '""', $st['name']) . '"', $stuAcc[$st['acct'] ?? '']['email'] ?? '',
            (int)($c['resp'] ?? 0), (int)($c['branches'] ?? 0), (int)($c['notes'] ?? 0), (int)($st['in'] ?? 0), (int)($st['out'] ?? 0),
            ($st['last'] ?? 0) ? date('Y-m-d H:i', $st['last']) : '—',
            !empty($st['self']) ? '自行加入' : '名單匯入']) . "\n";
    }
    exit;
}

$stats = $authed ? stats_read() : null;
$modeNames = [
    'chat' => '讀伴對話', 'briefing' => '閱讀導讀',
    'branch_summary' => '分支收束', 'doc_summary' => '閱讀回顧 / Word 摘要',
];
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? '') . preg_replace('#/admin\.php.*$#', '/', $_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>讀伴 ・ 教師後台</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&family=Noto+Serif+TC:wght@400;600;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=20261020d">
<script src="assets/pwd-eye.js?v=20261107b" defer></script>
</head>
<body>

<?= ui_topbar('teacher', $me, $authed, ['meUrl' => 'teacher.php?tab=account', 'logoutUrl' => 'teacher.php?logout=1', 'roleNote' => $isDev ? '以教師後台檢視' : '']) ?>

<?php if (!$authed): ?>
<div class="login-box">
  <?= ui_logo('brand-logo login-logo') ?>
  <h2>教師後台</h2>
  <p class="sub">課程管理</p>
  <?= auth_box_html($AUTH, '教師帳號') ?>
  <p style="margin-top:14px;font-size:12px;">帳號由系統管理者建立;忘記密碼請洽管理者重設。</p>
  <p style="margin-top:6px;font-size:12px;"><a href="index.html" style="color:var(--text-muted);">‹ 回讀伴前台</a></p>
</div>

<?php else: ?>
<div class="layout">

  <!-- ============ 側欄：我的課程 ============ -->
  <aside class="sidebar">
    <div class="sb-head">
      <h3>我的課程</h3>
      <a class="btn small" href="teacher.php">＋ 建立課程</a>
    </div>
    <?php if (empty($school['courses'])): ?>
      <div class="sb-empty">還沒有課程。按「建立課程」建立第一門課，拿到邀請碼後發給學生。</div>
    <?php endif; ?>
    <?php
      /* 教師只看到自己的課,不必分組;開發者看到全部,依擁有者分組才分得出是誰的課 */
      $groups = [];
      if ($isDev) {
          $names = array_map(fn($a) => $a['name'], $ACC);
          $names[$me['id']] = $me['name'] . '(我)';
          foreach ($school['courses'] as $c) $groups[$names[$c['owner'] ?? ''] ?? '未歸屬(舊資料)'][] = $c;
          /* 自己的課排最前面,其餘依教師名稱 */
          uksort($groups, fn($x, $y) => [!str_ends_with($x, '(我)'), $x] <=> [!str_ends_with($y, '(我)'), $y]);
      } else {
          $groups[''] = $school['courses'];
      }
      $nCourses = count($school['courses']);
      $manyGroups = count($groups) > 3;
    ?>
    <?php if ($nCourses > 5): ?>
      <input type="search" class="sb-search" id="sbSearch" placeholder="搜尋課程或教師" aria-label="搜尋課程或教師">
    <?php endif; ?>
    <nav class="sb-courses" aria-label="課程清單">
    <?php foreach ($groups as $gname => $list):
        /* 同一位教師的課:新建的在上;建立年月一併顯示,同名課程才分得出來 */
        usort($list, fn($x, $y) => ($y['createdAt'] ?? 0) <=> ($x['createdAt'] ?? 0));
        $hasCur = $cur && in_array($cur['id'], array_column($list, 'id'), true);
        $open = !$manyGroups || $hasCur || str_ends_with($gname, '(我)');
    ?>
      <?php if ($gname !== ''): ?><details class="sb-grp"<?= $open ? ' open' : '' ?>><summary><span class="g-name"><?= h($gname) ?></span><span class="g-cnt"><?= count($list) ?> 門</span></summary><?php endif; ?>
      <?php foreach ($list as $c): $on = $cur && $c['id'] === $cur['id']; ?>
      <a class="sb-course two<?= $on ? ' on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?> href="teacher.php?course=<?= h($c['id']) ?>&tab=students"
         data-q="<?= h(mb_strtolower($c['name'] . ' ' . $gname)) ?>">
        <span class="nm-wrap"><span class="nm"><?= h($c['name']) ?></span>
          <span class="meta"><?= !empty($c['createdAt']) ? date('Y/m', $c['createdAt']) . ' 建立' : '建立日期不明' ?><?= count(course_teacher_ids($c)) > 1 ? '・多位教師' : '' ?></span></span>
        <span class="cnt"><?= count($c['students']) ?> 人</span>
      </a>
      <?php endforeach; ?>
      <?php if ($gname !== ''): ?></details><?php endif; ?>
    <?php endforeach; ?>
    </nav>
    <div class="sb-empty" id="sbNoMatch" hidden>沒有符合的課程</div>
    <?php if ($nCourses > 5): ?>
    <script>
    (function () {
      var box = document.getElementById('sbSearch'), groups = document.querySelectorAll('.sb-grp'), none = document.getElementById('sbNoMatch');
      var initOpen = Array.prototype.map.call(groups, function (g) { return g.open; });
      box.oninput = function () {
        var q = box.value.trim().toLowerCase(), shown = 0;
        document.querySelectorAll('.sb-courses .sb-course').forEach(function (a) {
          var ok = !q || a.dataset.q.indexOf(q) !== -1; a.hidden = !ok; if (ok) shown++;
        });
        groups.forEach(function (g, i) {
          var any = g.querySelector('.sb-course:not([hidden])');
          g.hidden = !any; g.open = q ? !!any : initOpen[i];
        });
        none.hidden = shown > 0;
      };
    })();
    </script>
    <?php endif; ?>
    <?php if ($isDev && !$school['courses']): ?><p style="font-size:12px;color:var(--ink-faint);padding:4px 10px">目前還沒有任何教師建立課程。</p><?php endif; ?>
  </aside>

  <!-- ============ 主內容 ============ -->
  <main class="main">
  <?php if ($AUTH['msg']): ?><div class="flash"><?= h($AUTH['msg']) ?></div><?php endif; ?>
  <?php if ($AUTH['err']): ?><div class="flash err"><?= h($AUTH['err']) ?></div><?php endif; ?>
  <?php if ($expired): ?><div class="flash">已自動刪除超過保留期限(<?= COURSE_KEEP_DAYS ?> 天)的課程:<?= h(implode('、', $expired)) ?></div><?php endif; ?>
  <?php if (isset($_GET['ok'])): ?><div class="flash">已匯入 / 更新 <?= (int)$_GET['ok'] ?> 位學生。</div><?php endif; ?>

  <?php if ($tab === 'account'): ?>
    <div class="page-title">我的帳號</div>
    <div class="page-sub"><?= h($me['name']) ?>・<?= h($me['login']) ?>・<?= h(ACC_ROLES[$me['role']] ?? '') ?></div>
    <div class="panel" style="max-width:480px">
      <h4>更改密碼</h4>
      <?= auth_change_form_html() ?>
    </div>

  <?php elseif ($tab === 'home' || !$cur): ?>
    <div class="page-title">教師後台</div>
    <div class="page-sub">從課程清單選擇課程，或在這裡建立新課程。</div>
    <?php if (isset($_GET['left'])): ?><div class="flash">你已退出「<?= h($_GET['left']) ?>」。</div><?php endif; ?>
    <div class="home-wrap">
      <div class="panel">
        <span class="eyebrow">New course</span><h4>建立新課程</h4>
        <form method="post" class="fld-row">
          <input type="hidden" name="act" value="course_add">
          <label class="fld wide"><span>課程名稱</span>
            <input type="text" name="name" placeholder="例如：中國現代文學導讀" required></label>
          <button class="btn primary">建立課程</button>
        </form>
        <p class="help">建立後會進入課程頁，並依步驟帶你完成設定。課程保留 <?= COURSE_KEEP_DAYS ?> 天，<b>到期會連同學生資料自動刪除</b>；到期前一個月會在課程頁提醒，可延長。</p>
      </div>

    </div>
  <?php else:
    /* ================= 課程頁 ================= */
    $students = $cur['students'];
    uasort($students, fn($a, $b) => ($b['last'] ?? 0) - ($a['last'] ?? 0));
    $active = count(array_filter($students, fn($st) => ($st['last'] ?? 0) > 0));
    /* 計數單位全站統一為「學生回應」(不含讀伴),另計最後的分支與筆記;來源是學生同步上來的逐字稿 */
    $TC = tr_summary($cur['id']);
    $respTotal = array_sum(array_column($TC['bySid'], 'resp'));
  ?>
    <?php $curTeachers = course_teacher_ids($cur);
          $othersCourse = $isDev && !in_array($me['id'], $curTeachers, true);
          $tNames = implode('、', array_map(fn($id) => ($ACC[$id]['name'] ?? '(帳號已不存在)'), $curTeachers)); ?>
    <div class="crumb-line"><?= $othersCourse ? '所有課程' : '我的課程' ?> ›</div>
    <?php if ($othersCourse): ?>
      <div class="dev-note">你正以<b>開發者</b>身分檢視
        <b><?= h($tNames ?: '(沒有授課教師)') ?></b> 的課程。
        你看到的畫面與授課教師相同;在這裡做的修改會直接影響這門課,並會記入稽核紀錄。</div>
    <?php endif; ?>
    <div class="page-title" style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap"><?= h($cur['name']) ?>
      <?php /* 修改課名:任何一位授課教師與管理者都可以,會記入稽核紀錄 */ ?>
      <details style="font-size:13px;font-weight:400;font-family:var(--sans)">
        <summary style="cursor:pointer;color:var(--ink-soft);list-style:none" title="修改課程名稱">✎ 改名</summary>
        <form method="post" class="form-row" style="margin-top:6px">
          <input type="hidden" name="act" value="course_rename"><input type="hidden" name="course" value="<?= h($cur['id']) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
          <input type="text" name="name" value="<?= h($cur['name']) ?>" maxlength="40" required style="min-width:240px">
          <button class="btn small primary">儲存</button>
        </form>
      </details></div>
    <?php $expAt = course_expires_at($cur); $expDays = $expAt === null ? null : (int)ceil(($expAt - time()) / 86400); ?>
    <div class="course-meta">
      <span>學生 <b><?= count($students) ?></b> 人</span><span>曾使用 <b><?= $active ?></b> 人</span>
      <span>學生回應 <b><?= n($respTotal) ?></b> 則</span><span>指定文本 <b><?= count($cur['texts']) ?></b> 篇</span>
      <span class="keep"><?= $expAt === null ? '建立日期不明（不會自動刪除）' : '保留至 ' . date('Y/m/d', $expAt) ?>
      <?php /* 延長保留期限:每次 +COURSE_EXTEND_DAYS 天,最遠排到一年後(見 course_extend) */
            $extendForm = function ($cls) use ($cur, $tab) { ?>
        <form method="post" style="display:inline" onsubmit="return confirm(<?= js('把這門課的保留期限延長 ' . COURSE_EXTEND_DAYS . ' 天?(最遠可排到一年後)') ?>)">
          <input type="hidden" name="act" value="course_extend"><input type="hidden" name="course" value="<?= h($cur['id']) ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
          <button class="<?= $cls === 'primary' ? 'btn small primary' : 'btn-text' ?>" title="課程要用更久(例如學年課)時使用">延長 <?= COURSE_EXTEND_DAYS ?> 天</button></form>
      <?php }; if ($expAt !== null) $extendForm('ghost'); ?></span>
    </div>
    <?php if (isset($_GET['xok'])): ?><div class="flash">✓ <?= h($_GET['xok']) ?></div><?php endif; ?>
    <?php if (isset($_GET['xerr'])): ?><div class="flash err"><?= h($_GET['xerr']) ?></div><?php endif; ?>
    <?php if ($expDays !== null && $expDays <= COURSE_WARN_DAYS): ?>
      <div class="flash err">這門課將在 <?= max(0, $expDays) ?> 天後(<?= date('Y/m/d', $expAt) ?>)自動刪除,連同學生對話與解析。還要繼續使用請按「延長」;不再使用的話,需要保留的資料請先匯出。
        <?php $extendForm('primary'); ?></div>
    <?php endif; ?>

    <?php
      /* 使用流程:依這門課目前的狀態標出哪幾步已完成、下一步是什麼;前四步都完成後收合成一行 */
      $fTexts = array_values(array_filter(school_norm_texts($cur['texts'] ?? []), fn($t) => trim((string)$t['text']) !== ''));
      $fApproved = $fTexts && !array_filter($fTexts, function ($t) use ($cur) { $r = ta_get($cur['id'], $t['id']); return !(($r['status'] ?? '') === 'done' && !empty($r['edited'])); });
      $flow = [
        ['建立課程', '輸入課名，取得六碼邀請碼', '', true],
        ['邀請學生', '發邀請碼給學生或匯入名單', 'students', count($students) > 0],
        ['指定文本', '上傳文本，系統自動解析', 'texts', count($fTexts) > 0],
        ['審定解析', '確認內容後按「審定儲存」', 'texts', $fApproved],
        ['查看學生', '查看每位學生的對話與筆記', 'detail', $respTotal > 0],
      ];
      $nextI = null; foreach ($flow as $i => $f) if (!$f[3]) { $nextI = $i; break; }
      $setupDone = $flow[1][3] && $flow[2][3] && $flow[3][3];
      $tabName = ['students' => '名單邀請', 'texts' => '文本解析', 'detail' => '學習紀錄'];
    ?>
    <details class="panel flow-card"<?= $setupDone ? '' : ' open' ?>>
      <summary><span class="eyebrow">Workflow</span><span class="s-title">使用流程</span>
        <span class="aside"><?= $setupDone ? '設定都完成了，學生可以開始閱讀' : '下一步：' . $flow[$nextI][0] ?></span></summary>
      <ol class="flow">
        <?php foreach ($flow as $i => [$st, $sd, $tk, $ok]): $isNext = $i === $nextI; ?>
        <li class="<?= $ok ? 'done' : ($isNext ? 'next' : '') ?>">
          <span class="flow-n" aria-hidden="true"><?= $ok ? '✓' : $i + 1 ?></span>
          <b><?= $st ?><?= $ok ? '<span class="sr-note">（已完成）</span>' : '' ?></b><p><?= $sd ?></p>
          <?php if ($tk): ?><a class="flow-go" href="teacher.php?course=<?= h($cur['id']) ?>&tab=<?= $tk ?>"><?= $tabName[$tk] ?></a><?php else: ?><small>已完成</small><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </details>

    <div class="tabs">
      <?php foreach (['students' => '名單邀請', 'texts' => '文本解析', 'detail' => '學習紀錄'] as $tk => $lbl): ?>
        <a class="tab<?= $tab === $tk ? ' on' : '' ?>" href="teacher.php?course=<?= h($cur['id']) ?>&tab=<?= $tk ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($tab === 'texts'):
      $texts = school_norm_texts($cur['texts'] ?? []);
      $editId = $_GET['edit'] ?? '';
      $editing = null;
      foreach ($texts as $tx) if ($tx['id'] === $editId) { $editing = $tx; break; }
      /* 一次只展開一篇的解析:清單上按「修改解析」才帶 an=<textId> */
      $anId = (string)($_GET['an'] ?? '');
      $anTx = null;
      foreach ($texts as $tx) if ($tx['id'] === $anId && trim((string)$tx['text']) !== '') { $anTx = $tx; break; }
      /* 導讀:清單上按「導讀」才帶 bf=<textId> */
      $bfId = (string)($_GET['bf'] ?? ''); $bfTx = null;
      foreach ($texts as $tx) if ($tx['id'] === $bfId && trim((string)$tx['text']) !== '') { $bfTx = $tx; break; }
      /* 每篇有回應的學生數(至少回應一則才算已開始)——也決定全班綜覽能不能跑 */
      $trHas = array_map(fn($t) => $t['students'], $TC['byTitle']);

      /* 全班綜覽:cls=<textId> */
      $clsId = (string)($_GET['cls'] ?? '');
      $clsTx = null;
      foreach ($texts as $tx) if ($tx['id'] === $clsId) { $clsTx = $tx; break; }
    ?>
      <?php if (isset($_GET['tsaved'])): ?><div class="flash">文本已儲存，學生端立即可見。</div><?php endif; ?>
      <?php if (isset($_GET['tlong'])): ?><div class="flash err">未儲存：這篇共 <?= n($_GET['tlong']) ?> 字，超過每篇上限（中文 100,000 字；英文約 400,000 字元）。請刪減，或拆成多篇上傳。</div><?php endif; ?>
      <?php if (isset($_GET['asaved'])):
        $savedTitle = '';
        foreach ($texts as $tx) if ($tx['id'] === $_GET['asaved']) { $savedTitle = $tx['title']; break; }
      ?><div class="flash">✓ <?= $savedTitle === '' ? '解析' : '《' . h($savedTitle) . '》的解析' ?>已審定儲存,學生與讀伴對話時將以此作為閱讀地圖。清單中該篇已標示為「已審定」。</div><?php endif; ?>
      <?php if (isset($_GET['aerr'])): ?><div class="flash err">未儲存:解析內容不可留空。</div><?php endif; ?>
      <?php /* 新增／編輯文本:平常收合成一顆按鈕;還沒有文本或正在編輯時才直接展開 */
            $addOpen = $editing || empty($texts) || isset($_GET['tlong']); ?>
      <details class="panel add-text" id="addText"<?= $addOpen ? ' open' : '' ?>>
        <summary><span class="eyebrow">Text</span><span class="s-title"><?= $editing ? '編輯文本：' . h($editing['title']) : '新增指定文本' ?></span>
          <span class="aside">學生加入課程後就會在清單中看到</span></summary>
        <form method="post" class="add-text-body" enctype="multipart/form-data">
          <input type="hidden" name="act" value="text_add"><input type="hidden" name="course" value="<?= h($cur['id']) ?>">
          <input type="hidden" name="text_id" value="<?= h($editing['id'] ?? '') ?>">
          <div class="fld-row">
            <label class="fld wide"><span>文本標題</span>
              <input type="text" name="title" id="txTitle" placeholder="例如：荷塘月色（節選）" value="<?= h($editing['title'] ?? '') ?>" required></label>
            <input type="file" id="txFile" name="orig" accept=".txt,.md,.docx,.pdf" style="display:none">
            <button type="button" class="btn" id="btnTxUpload">上傳檔案</button>
            <select id="txOcrLang" class="ocr-lang" title="只用在掃描檔的文字辨識；自動判斷不準時再手動指定" aria-label="掃描檔的辨識語言">
              <option value="auto">掃描檔：自動判斷語言</option>
              <option value="chi_tra">掃描檔：繁體中文</option>
              <option value="chi_sim">掃描檔：簡體中文</option>
              <option value="eng">掃描檔：英文</option>
            </select>
          </div>
          <span id="txFileStatus" style="font-size:12px;color:var(--text-muted)"></span>
          <?php if ($editing && !empty($editing['orig'])): ?>
            <span style="font-size:12px;color:var(--ink-soft)">目前保存的原檔：<?= strtoupper(h($editing['orig'])) ?>（<a href="orig.php?view=1&c=<?= h($cur['id']) ?>&t=<?= h($editing['id']) ?>" target="_blank">看原檔</a>；重新上傳檔案會取代它）</span>
          <?php endif; ?>
          <label class="fld" style="margin-top:10px"><span>文本全文<?= $editing ? '<em>留空代表保留原內容</em>' : '' ?></span>
            <textarea name="body" id="txBody" placeholder="貼上全文，或用「上傳檔案」讀入 .txt／.md／.docx／.pdf" style="min-height:160px"><?= h($editing['text'] ?? '') ?></textarea></label>
          <div id="txLimit" class="tx-limit">
            <span>每篇中文上限 100,000 字；檔案 10 MB 內，掃描 PDF 會自動文字辨識。<br>以「# 」開頭的一行是章節標題（docx 自動辨識；PDF 依字級推測），可自行增刪。上傳的 PDF／docx 原檔會一併保存，學生可按「看原檔」對照版面與圖表</span><span id="txCount"></span>
          </div>
          <div class="form-row">
            <button class="btn primary"><?= $editing ? '儲存變更' : '新增文本' ?></button>
            <?php if ($editing): ?><a class="btn ghost" href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts">取消編輯</a><?php endif; ?>
          </div>
        </form>
      </details>

      <section class="card tbl-card tbl-compact text-list">
        <div class="card-h"><span class="eyebrow">Texts</span><h2>指定文本</h2><span class="aside">共 <?= count($texts) ?> 篇</span>
          <span class="grow"></span>
          <?php if (!$addOpen): ?><button type="button" class="btn small primary" onclick="var d=document.getElementById('addText');d.open=true;d.scrollIntoView({behavior:'smooth',block:'start'});document.getElementById('txTitle').focus()">＋ 新增文本</button><?php endif; ?>
        </div>
        <div class="tbl-wrap">
        <table>
          <tr><th>文本</th><th>解析</th><th>學生</th><th>操作</th></tr>
          <?php foreach ($texts as $i => $tx):
            $tc = $TC['byTitle'][$tx['title']] ?? ['students' => 0, 'resp' => 0, 'branches' => 0, 'notes' => 0];
            $len = $tx['text'] === '' ? 0 : (function_exists('mb_strlen') ? mb_strlen($tx['text']) : preg_match_all('/./us', $tx['text']));
            $ar = ta_get($cur['id'], $tx['id']); $as = $ar['status'] ?? 'none';
            if ($ar && ta_is_stuck($ar)) $as = 'stuck';
            $approved = $as === 'done' && !empty($ar['edited']);
          ?>
          <tr<?= $tx['id'] === $anId ? ' class="row-on"' : '' ?>>
            <td class="tx-title">
              <b><?= $i + 1 ?>. <?= h($tx['title']) ?></b>
              <span><?= $len ? n($len) . ' 字' : '尚無內容' ?><?php if (!empty($tx['by'])): ?>・<?= h($tx['by']) ?> <?= date('m/d', $tx['ts']) ?> 修改<?php endif; ?></span>
            </td>
            <td><?php
              if ($tx['text'] === '') echo '<span class="tag">尚無內容</span>';
              elseif ($as === 'done') echo $approved ? '<span class="tag ok">已審定</span>' : '<span class="tag warn">待審閱</span>';
              elseif ($as === 'pending' || $as === 'running') echo '<span class="tag info an-live" data-tid="' . h($tx['id']) . '">解析中⋯</span>';
              elseif ($as === 'error' || $as === 'stuck') echo '<span class="tag brand">解析失敗</span>';
              else echo '<span class="tag">未解析</span>';
              if ($as === 'done' && !empty($ar['stale'])) echo ' <span class="tag warn">內容已變更</span>';
            ?></td>
            <td class="tx-stu">
              <b><?= $tc['students'] ?> / <?= count($students) ?></b> 人已開始
              <span>回應 <?= n($tc['resp']) ?>・分支 <?= n($tc['branches']) ?>・筆記 <?= n($tc['notes']) ?></span>
            </td>
            <td class="tx-act">
              <?php if (trim((string)$tx['text']) !== ''): ?>
                <?php /* 還沒審定的解析是下一步要做的事,用主要按鈕;已審定的退為一般按鈕 */ ?>
                <a class="btn small<?= $approved ? '' : ' primary' ?>"
                   href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts&an=<?= h($tx['id']) ?>#an-<?= h($tx['id']) ?>"><?php
                   if ($as !== 'done') echo '解析';
                   else echo $approved ? '修改解析' : '審閱解析';
                   ?></a>
                <?php $bs = bf_state($cur['id'], $tx['id']); $bLive = $bs['regen'] || $bs['state'] === 'running' || (!empty($bs['rec']['gen']) && time() - (int)$bs['rec']['gen'] < BF_GEN_TIMEOUT); ?>
                <a class="btn small<?= $bLive ? ' bf-live' : '' ?>" data-tid="<?= h($tx['id']) ?>"
                   href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts&bf=<?= h($tx['id']) ?>#bf-<?= h($tx['id']) ?>">導讀<?=
                   $bLive ? '（生成中）' : ($bs['state'] === 'waiting' ? '（等待解析）' : ($bs['state'] === 'error' ? '（失敗）'
                   : (!empty($bs['rec']['provisional']) && empty($bs['rec']['edited']) ? '（暫用）' : ''))) ?></a>
                <?php if (($trHas[$tx['title']] ?? 0) > 0): ?>
                  <a class="btn small" href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts&cls=<?= h($tx['id']) ?>#cls">全班綜覽</a>
                <?php endif; ?>
              <?php endif; ?>
              <span class="tx-minor">
                <a class="btn-text" href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts&edit=<?= h($tx['id']) ?>" title="修改這篇文本的標題或內容">編輯</a>
                <?php if (!empty($tx['orig'])): ?><a class="btn-text" href="orig.php?view=1&c=<?= h($cur['id']) ?>&t=<?= h($tx['id']) ?>" target="_blank" rel="noopener" title="上傳時保存的 <?= strtoupper(h($tx['orig'])) ?> 原檔">原檔</a><?php endif; ?>
                <form method="post" style="display:inline" onsubmit="return confirm('移除這篇文本?學生將看不到它。')"><input type="hidden" name="act" value="text_del"><input type="hidden" name="course" value="<?= h($cur['id']) ?>"><input type="hidden" name="text_id" value="<?= h($tx['id']) ?>"><button class="btn-text danger">移除</button></form>
              </span>
            </td>
          </tr>
          <?php endforeach; if (empty($texts)): ?>
          <tr><td colspan="4" class="empty-row">還沒有指定文本。請用上方表單新增至少一篇，學生才有東西可讀。</td></tr>
          <?php endif; ?>
        </table>
        </div>
        <?php if ($texts): ?><p class="list-foot">新增文本後系統會自動解析（約數分鐘，可先離開）。按「解析」審閱並審定，讀伴才會依此引導討論。</p><?php endif; ?>
      </section>

      <?php if ($clsId !== '' && !$clsTx && isset($_GET['rerr'])): ?><div class="flash err">分析未完成:<?= h($_GET['rerr']) ?></div><?php endif; ?>
      <?php if ($clsTx): $clsRec = sr_get(sr_class_path($cur['id'], $clsTx['id'])); ?>
      <div class="section-title" id="cls"><span class="eyebrow">Stage 3</span>全班學習綜覽</div>
      <?php if (isset($_GET['rerr'])): ?><div class="flash err">分析未完成:<?= h($_GET['rerr']) ?></div><?php endif; ?>
      <div class="panel">
        <h4><?= h($clsTx['title']) ?>
          <?php if ($clsRec): ?><span style="font-weight:400;color:var(--ink-faint);font-size:12px"><?= date('m/d H:i', $clsRec['ts']) ?><?= !empty($clsRec['by']) ? ' ' . h($clsRec['by']) : '' ?> 產生・納入 <?= (int)$clsRec['students'] ?> 位學生</span><?php endif; ?>
        </h4>
        <?php if (!$clsRec): ?>
          <p style="font-size:13px;color:var(--ink-soft);line-height:1.9">
            以審定的閱讀地圖為參照,看全班在這篇的整體樣態：層次分布、多數人漏掉的重點、
            兩位以上學生共同的誤讀或卡點,以及下一堂課可以怎麼補。<b>不點名、不排名、不評分。</b>
          </p>
        <?php else: ?>
          <?= sr_class_tables($clsRec) ?>
          <div class="sr-box"><?= sr_md_html($clsRec['md']) ?></div>
        <?php endif; ?>
        <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='分析中,可能需要一分鐘‧‧'">
          <input type="hidden" name="act" value="review_class">
          <input type="hidden" name="course" value="<?= h($cur['id']) ?>">
          <input type="hidden" name="text_id" value="<?= h($clsTx['id']) ?>">
          <div class="form-row">
            <button class="btn <?= $clsRec ? 'small ghost' : 'primary small' ?>"><?= $clsRec ? '↻ 重新產生' : '產生全班綜覽' ?></button>
            <a class="btn small ghost" href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts">← 收合</a>
            <span class="hint" style="margin-top:0">會納入這篇有對話紀錄的學生(最多 12 位)。即時執行,請稍候不要離開此頁。</span>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if ($bfTx): $tx = $bfTx;
        $bs = bf_state($cur['id'], $tx['id']); $br = $bs['rec'] ?: [];
        $bHas = trim((string)($br['summary'] ?? '')) !== '';
        $bGen = !empty($br['gen']) && time() - (int)$br['gen'] < BF_GEN_TIMEOUT;
      ?>
      <div class="section-title" id="bf-<?= h($tx['id']) ?>"><span class="eyebrow">Briefing</span>導讀(全班共用)</div>
      <?php if (isset($_GET['bsaved'])): ?><div class="flash">✓ 導讀已儲存。學生下次打開導讀就會看到這個版本。</div><?php endif; ?>
      <?php if (isset($_GET['berr'])): ?><div class="flash err">未儲存:重點摘要與相關背景都不可留空。</div><?php endif; ?>
      <div class="panel">
        <h4><?= h($tx['title']) ?>
          <?php if ($bHas): ?>
            <span class="tag<?= empty($br['edited']) ? ' self' : '' ?>"><?= !empty($br['edited']) ? '教師修改版・學生使用中'
                : (!empty($br['provisional']) ? '暫用版(依尚未審定的 AI 解析)・學生使用中' : 'AI 依審定解析生成・學生使用中') ?></span>
            <span style="font-weight:400;color:var(--ink-faint);font-size:12px"><?= date('m/d H:i', (int)($br['ts'] ?? time())) ?><?= !empty($br['edited']) && !empty($br['editedBy']) ? '・' . h($br['editedBy']) . ' 修改' : '' ?></span>
          <?php endif; ?>
        </h4>
        <?php if ($bs['state'] === 'waiting'): ?>
          <p style="font-size:13px;color:var(--ink-soft);line-height:1.9">這篇的 AI 解析還沒完成。解析完成後會先依 AI 解析生成<b>暫用導讀</b>;您審定解析後,會自動換成<b>依審定解析生成的正式版</b>,全班共用同一份。
            在那之前,學生打開這篇會看到「老師還在準備導讀」,仍可直接閱讀全文、讀完後開始討論。</p>
        <?php elseif (!$bHas && ($bs['state'] === 'running' || $bGen)): ?>
          <p class="bf-live" data-tid="<?= h($tx['id']) ?>" style="font-size:13px;color:var(--ink-soft)">⏳ 導讀生成中,約需 20–40 秒⋯ 完成後此頁會自動更新。</p>
        <?php elseif (!$bHas): ?>
          <p style="font-size:13px;color:var(--danger)">導讀生成失敗:<?= h($br['genError'] ?? '原因不明') ?></p>
          <button type="button" class="btn verm small bf-run" data-tid="<?= h($tx['id']) ?>">↻ 重新生成</button>
        <?php else: ?>
          <?php if ($bGen): ?>
            <div class="flash bf-live" data-tid="<?= h($tx['id']) ?>">⏳ 正在依最新的文本與解析重新生成,完成後此頁會自動更新;學生目前看到的是舊版。</div>
          <?php elseif (!empty($br['provisional']) && empty($br['edited'])): ?>
            <div class="flash">這是依<b>尚未審定</b>的 AI 解析生成的暫用導讀,避免學生沒有導讀可看。您審定這篇的解析後,會自動換成依審定解析生成的正式版。</div>
          <?php elseif ($bs['outdated'] && !empty($br['edited'])): ?>
            <div class="flash err">文本或審定的解析在您修改導讀之後有更新。學生目前仍看到您修改的版本;需要時可按「依解析重新生成」(會覆蓋您的修改)。</div>
          <?php elseif ($bs['outdated']): ?>
            <div class="flash">文本在導讀生成之後有修改,等解析重新審定後會自動更新導讀;學生目前看到的是舊版。</div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="act" value="briefing_edit">
            <input type="hidden" name="course" value="<?= h($cur['id']) ?>">
            <input type="hidden" name="text_id" value="<?= h($tx['id']) ?>">
            <label class="bf-label">重點摘要</label>
            <textarea name="summary" class="an-editor" style="min-height:110px"><?= h($br['summary'] ?? '') ?></textarea>
            <label class="bf-label">相關背景</label>
            <textarea name="background" class="an-editor" style="min-height:110px"><?= h($br['background'] ?? '') ?></textarea>
            <label class="bf-label">心智圖 <span style="font-weight:400;color:var(--ink-faint)">(第一行是中心;「- 」開頭是面向;前面空兩格的「- 」是該面向的細項)</span></label>
            <textarea name="mindmap" class="an-editor" style="min-height:200px;font-family:monospace"><?= h(bf_mindmap_to_text($br['mindmap'] ?? null)) ?></textarea>
            <div class="form-row">
              <button class="btn primary small">✓ 儲存修改</button>
              <button type="button" class="btn small ghost bf-run" data-tid="<?= h($tx['id']) ?>" data-confirm="<?= !empty($br['edited']) ? '1' : '' ?>">↻ 依解析重新生成</button>
              <span class="hint" style="margin-top:0">儲存後即為全班學生看到的導讀。</span>
            </div>
          </form>
        <?php endif; ?>
        <div class="form-row" style="margin-top:10px">
          <a class="btn small ghost" href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts">← 收合,回到文本清單</a>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($anTx): $tx = $anTx; ?>
      <div class="section-title"><span class="eyebrow">Stage 1</span>AI 文本解析（閱讀地圖）</div>
      <div class="notice"><?= ui_icon('warn') ?><div>解析以 Hayden White 四層框架 × 文史哲社四領域自動產生。請審閱、視需要編修後按「審定儲存」，它會成為讀伴引導討論的<b>閱讀地圖</b>，但不會直接告訴學生。</div></div>
      <?php
        $ar = ta_get($cur['id'], $tx['id']); $as = $ar['status'] ?? 'none';
        if ($ar && ta_is_stuck($ar)) $as = 'stuck';
      ?>
      <div class="panel" id="an-<?= h($tx['id']) ?>">
        <h4><?= h($tx['title']) ?>
          <?php if ($as === 'done'): ?>
            <span class="tag<?= empty($ar['edited']) ? ' self' : '' ?>"><?= !empty($ar['edited']) ? '已審定・學生對話使用中' : 'AI 生成・待審閱(學生對話已使用)' ?></span>
            <span style="font-weight:400;color:var(--ink-faint);font-size:12px"><?= date('m/d H:i', $ar['ts']) ?><?= !empty($ar['edited']) && !empty($ar['editedBy']) ? '・' . h($ar['editedBy']) . ' 審定' : '' ?></span>
          <?php endif; ?>
        </h4>

        <?php if ($as === 'pending' || $as === 'running'): ?>
          <p class="an-wait" data-tid="<?= h($tx['id']) ?>" style="font-size:13px;color:var(--ink-soft)">⏳ 解析中,約需 30–60 秒⋯ 完成後此頁會自動更新(可先離開,解析會在伺服器繼續進行)。</p>

        <?php elseif ($as === 'error' || $as === 'stuck'): ?>
          <p style="font-size:13px;color:var(--danger)">解析失敗:<?= h($ar['error'] ?? '執行中斷') ?></p>
          <button type="button" class="btn verm small an-run" data-tid="<?= h($tx['id']) ?>" data-force="1">↻ 重新解析</button>

        <?php elseif ($as === 'none'): ?>
          <p style="font-size:13px;color:var(--ink-soft)">這篇文本尚未解析(可能是在自動解析功能上線前上傳的)。</p>
          <button type="button" class="btn verm small an-run" data-tid="<?= h($tx['id']) ?>" data-force="1">▶ 開始解析</button>

        <?php else: /* done */ ?>
          <?php if (!empty($ar['stale'])): ?>
            <div class="flash err">文本內容在您審定之後有修改。目前仍使用您審定的版本;若要依新內容重新解析,請按「重新解析」(會覆蓋您的編修)。</div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="act" value="analysis_edit">
            <input type="hidden" name="course" value="<?= h($cur['id']) ?>">
            <input type="hidden" name="text_id" value="<?= h($tx['id']) ?>">
            <textarea name="analysis_md" class="an-editor"><?= h($ar['md']) ?></textarea>
            <div class="form-row">
              <button class="btn primary small">✓ 審定儲存</button>
              <a class="btn small" href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts&dl_analysis=<?= rawurlencode($tx['id']) ?>">匯出 .md</a>
              <button type="button" class="btn small ghost an-run" data-tid="<?= h($tx['id']) ?>" data-force="1"
                      data-confirm="<?= !empty($ar['edited']) ? '1' : '' ?>">↻ 重新解析</button>
              <span class="hint" style="margin-top:0">可直接修改內容(Markdown 格式),儲存後即為學生對話使用的版本。</span>
            </div>
          </form>
        <?php endif; ?>
        <div class="form-row" style="margin-top:10px">
          <a class="btn small ghost" href="teacher.php?course=<?= h($cur['id']) ?>&tab=texts">← 收合,回到文本清單</a>
        </div>
      </div>
      <?php endif; ?>

      <script>
      /* 導讀:解析審定後自動生成(全班共用一份),生成中輪詢直到完成 */
      (function () {
        var CID = <?= json_encode($cur['id']) ?>;
        function post(body) {
          return fetch('analyze.php', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
        }
        document.querySelectorAll('.bf-run').forEach(function (b) {
          b.onclick = function () {
            if (b.dataset.confirm && !confirm('重新生成會以新的 AI 結果覆蓋您修改過的導讀,確定嗎?')) return;
            b.disabled = true; b.textContent = '已開始生成⋯';
            post({ action: 'brief_run', courseId: CID, textId: b.dataset.tid, force: 1 })
              .then(function () { setTimeout(function () { location.reload(); }, 800); })
              .catch(function () { b.disabled = false; alert('無法開始生成,請稍後再試'); });
          };
        });
        var seen = {};
        document.querySelectorAll('.bf-live').forEach(function (el) { seen[el.dataset.tid] = 1; });
        var live = Object.keys(seen);
        if (!live.length) return;
        live.forEach(function (tid) { post({ action: 'brief_run', courseId: CID, textId: tid }).catch(function () {}); });
        var tries = 0;
        var timer = setInterval(function () {
          tries++;
          post({ action: 'brief_status', courseId: CID }).then(function (d) {
            var busy = live.filter(function (tid) { var s = (d.status || {})[tid] || {}; return s.gen || s.state === 'running'; });
            if (!busy.length || tries > 40) { clearInterval(timer); location.reload(); }
          }).catch(function () {});
        }, 5000);
      })();
      </script>

      <script>
      (function () {
        var CID = <?= json_encode($cur['id']) ?>;
        function post(body) {
          return fetch('analyze.php', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
        }
        // 手動觸發(重新解析 / 開始解析)
        document.querySelectorAll('.an-run').forEach(function (b) {
          b.onclick = function () {
            if (b.dataset.confirm && !confirm('重新解析會以新的 AI 結果覆蓋您已審定的編修,確定嗎?')) return;
            b.disabled = true; b.textContent = '已開始解析⋯';
            post({ action: 'run', courseId: CID, textId: b.dataset.tid, force: 1 })
              .then(function () { setTimeout(function () { location.reload(); }, 800); })
              .catch(function () { b.disabled = false; b.textContent = '↻ 重新解析'; alert('無法啟動解析,請稍後再試'); });
          };
        });
        // 待解析的文本:自動啟動,並輪詢直到完成。
        // 以清單的狀態標籤(.an-live)為準,解析面板(.an-wait)只在展開那一篇時才存在。
        var seen = {};
        document.querySelectorAll('.an-live, .an-wait').forEach(function (el) { seen[el.dataset.tid] = 1; });
        var waiting = Object.keys(seen);
        if (!waiting.length) return;
        waiting.forEach(function (tid) { post({ action: 'run', courseId: CID, textId: tid }).catch(function () {}); });
        var tries = 0;
        var timer = setInterval(function () {
          tries++;
          post({ action: 'status', courseId: CID }).then(function (d) {
            var pending = waiting.filter(function (tid) {
              var s = (d.status || {})[tid]; return s === 'pending' || s === 'running';
            });
            if (!pending.length || tries > 60) { clearInterval(timer); location.reload(); }
          }).catch(function () {});
        }, 5000);
      })();
      </script>

    <?php elseif ($tab === 'detail'):
      $viewSid = $_GET['sid'] ?? '';
      $viewDoc = $_GET['doc'] ?? '';
      $tr = ($viewSid !== '' && $viewDoc !== '') ? transcript_get($cur['id'], $viewSid, $viewDoc) : null;
    ?>
    <?php if (isset($_GET['rerr']) && !$tr): ?><div class="flash err">分析未完成:<?= h($_GET['rerr']) ?></div><?php endif; ?>
    <?php if ($tr): ?>
      <div class="crumb-line"><a href="teacher.php?course=<?= h($cur['id']) ?>&tab=detail">學習紀錄</a> ›</div>
      <div class="page-title"><?= h($tr['name'] ?: $tr['sid']) ?> ・《<?= h($tr['title']) ?>》</div>
      <div class="page-sub">學號 <?= h($tr['sid']) ?> ・ 最後更新 <?= date('Y/m/d H:i', $tr['updatedAt']) ?></div>

      <?php if (isset($_GET['rerr'])): ?><div class="flash err">分析未完成:<?= h($_GET['rerr']) ?></div><?php endif; ?>
      <?php
        $rev = sr_get(sr_path($cur['id'], $tr['docId'] ?? '', $tr['sid']));
        $stTurns = sr_student_turns($tr);
      ?>
      <div class="panel">
        <h4>學習分析
          <?php if ($rev): ?><span style="font-weight:400;color:var(--ink-faint);font-size:12px"><?= date('m/d H:i', $rev['ts']) ?><?= !empty($rev['by']) ? ' ' . h($rev['by']) : '' ?> 產生<?= empty($rev['hasMap']) ? '(當時沒有審定的解析可參照)' : '' ?></span><?php endif; ?>
        </h4>
        <?php if (!$rev): ?>
          <p style="font-size:13px;color:var(--ink-soft);line-height:1.9">
            以這篇文本<b>教師審定的閱讀地圖</b>為參照,分析這位學生實際抵達的理解層次、觸及與漏掉的重點、
            自己提出的洞見、卡住的地方,並給你具體的下一步引導建議。<b>這是形成性回饋,不做評分。</b>
          </p>
        <?php else: ?>
          <div class="sr-box"><?= sr_md_html($rev['md']) ?></div>
        <?php endif; ?>
        <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='分析中,約 20–40 秒⋯'">
          <input type="hidden" name="act" value="review_student">
          <input type="hidden" name="course" value="<?= h($cur['id']) ?>">
          <input type="hidden" name="sid" value="<?= h($tr['sid']) ?>">
          <input type="hidden" name="doc" value="<?= h($tr['docId'] ?? '') ?>">
          <div class="form-row">
            <button class="btn <?= $rev ? 'small ghost' : 'primary small' ?>"<?= $stTurns < 1 ? ' disabled' : '' ?>><?= $rev ? '↻ 重新分析' : '分析這段對話' ?></button>
            <span class="hint" style="margin-top:0"><?= $stTurns < 1 ? '這位學生在這篇還沒有回應,無法分析。' : '學生共回應 ' . n($stTurns) . ' 則。分析會即時執行,請稍候不要離開此頁。' ?></span>
          </div>
        </form>
      </div>
      <?php
        $nodes = $tr['nodes'] ?? [];
        // 分段共讀:每一段讀完的範圍是一條主幹,依段落順序列出,分支跟在所屬的段落後面
        $groups = array_values(array_filter(sr_trunk_groups($nodes), fn($g) => !$g[0] || $g[1]
            || !empty($g[0]['seg']) || !empty($g[0]['messages'])));
        // 主幹名稱和學生端一樣,章節與段落並列(章節取自這篇課程文本的「# 」標題)
        $trText = null;
        foreach (school_norm_texts($cur['texts'] ?? []) as $t2)
            if (!empty($tr['textId']) ? $t2['id'] === $tr['textId'] : $t2['title'] === ($tr['title'] ?? '')) { $trText = $t2; break; }
        $trHeads = sr_heads($trText['text'] ?? '');
        $personaNames = ['guide' => '引導者', 'critic' => '批判者', 'explorer' => '探索者'];
        $renderNode = function ($nd) use ($personaNames) {
            $html = '';
            foreach (($nd['messages'] ?? []) as $msg) {
                $role = $msg['role'] ?? '';
                if ($role === 'merge') {
                    $html .= '<div class="tx-merge">⤷ 分支收束「' . htmlspecialchars($msg['branchTitle'] ?? '') . '」'
                           . (!empty($msg['fromSeg']) ? '（來自' . htmlspecialchars($msg['fromSeg']) . '）' : '') . ':'
                           . nl2br(htmlspecialchars($msg['content'] ?? '')) . '</div>';
                    continue;
                }
                $who = $role === 'user' ? '學生' : ('讀伴' . (isset($msg['persona'], $personaNames[$msg['persona']]) ? '(' . $personaNames[$msg['persona']] . ')' : ''));
                $q = !empty($msg['quote']) ? '<div class="tx-quote">引用:「' . htmlspecialchars($msg['quote']) . '」</div>' : '';
                $html .= '<div class="tx-msg ' . ($role === 'user' ? 'tx-user' : 'tx-ai') . '">'
                       . '<div class="tx-who">' . $who . '</div>' . $q
                       . '<div class="tx-body">' . nl2br(htmlspecialchars($msg['content'] ?? '')) . '</div></div>';
            }
            return $html ?: '<p style="font-size:12.5px;color:var(--ink-faint)">(沒有對話)</p>';
        };
      ?>
      <?php if (!array_filter($groups, fn($g) => $g[0])): ?>
      <div class="panel"><h4>主幹</h4><p style="color:var(--ink-faint)">(無主幹紀錄)</p></div>
      <?php endif; foreach ($groups as [$tk, $brs]):
        $lb = $tk ? sr_seg_label($tk['seg'] ?? null) : ''; $sc = $tk ? sr_seg_secs($tk['seg'] ?? null, $trHeads) : ''; ?>
      <?php if ($tk): ?>
      <div class="panel">
        <h4><?= $lb === '' ? '主幹' : ($sc !== '' ? h($sc) . '（' . $lb . '）' : '段落：' . $lb) ?></h4>
        <?php if (!empty($tk['summary'])): ?><div class="tx-quote">這一段的摘要：<?= h($tk['summary']) ?></div><?php endif; ?>
        <?= $renderNode($tk) ?>
      </div>
      <?php endif; foreach ($brs as $nd):
        $st = sr_branch_status($nd); ?>
        <div class="panel" style="margin-left:22px">
          <h4>🌿 <?= h($nd['title'] ?? '分支') ?><?= $lb !== '' ? '（' . ($sc !== '' ? h($sc) . '，' : '') . $lb . '）' : '' ?> <span class="tag"><?= $st ?></span></h4>
          <?php if (!empty($nd['quote'])): ?><div class="tx-quote">起點:「<?= h($nd['quote']) ?>」</div><?php endif; ?>
          <?= $renderNode($nd) ?>
        </div>
      <?php endforeach; endforeach; ?>
      <?php if (!empty($tr['notes'])): ?>
      <div class="panel">
        <h4>筆記(<?= count($tr['notes']) ?>)</h4>
        <ul style="font-size:13px;line-height:1.9;padding-left:18px">
          <?php foreach ($tr['notes'] as $nt): ?>
            <li><?= !empty($nt['title']) ? '<b>' . h($nt['title']) . '</b>:' : '' ?><?= nl2br(h($nt['content'] ?? '')) ?>
              <?php if (!empty($nt['source']['label'])): ?><span style="color:var(--ink-faint);font-size:11.5px">(來源:<?= h($nt['source']['label']) ?>)</span><?php endif; ?>
              <?php if (trim((string)($nt['comment'] ?? '')) !== ''): ?><div style="margin:2px 0 4px;padding:4px 10px;border-left:3px solid var(--vermilion);background:var(--verm-wash);border-radius:0 6px 6px 0"><b style="font-size:11.5px;color:var(--vermilion)">學生的個人註記</b><br><?= nl2br(h($nt['comment'])) ?></div><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <?php $hls = sr_hl_marks($tr); if ($hls): ?>
      <div class="panel">
        <h4>重點畫記(<?= count($hls) ?>)</h4>
        <ul style="font-size:13px;line-height:1.9;padding-left:18px">
          <?php foreach ($hls as $hl): ?>
            <li>第 <?= (int)($hl['para'] ?? 0) + 1 ?> 段:「<?= h($hl['text'] ?? '') ?>」</li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="section-title"><span class="eyebrow">Records</span>學習紀錄<span class="aside" style="font-family:var(--sans);font-size:13px;color:var(--text-muted);letter-spacing:0">每位學生讀了哪些文本、對話與筆記</span></div>
      <p class="page-sub">點開任一筆閱讀歷程,即可看到該生完整的主幹對話、各條分支與筆記。</p>
      <?php
        $allTr = transcripts_for($cur['id']);
        $byStudent = [];
        foreach ($allTr as $x) { $byStudent[$x['sid']][] = $x; }
      ?>
      <?php foreach ($students as $sid => $st): ?>
        <div class="panel">
          <h4><?= h($st['name']) ?>
            <?php $sc = $TC['bySid'][$sid] ?? ['resp' => 0, 'branches' => 0, 'notes' => 0]; ?>
            <span style="font-weight:400;color:var(--ink-faint);font-size:12px"><?= h($sid) ?> ・ 學生回應 <?= n($sc['resp']) ?> 則 ・ 分支 <?= n($sc['branches']) ?> ・ 筆記泡泡 <?= n($sc['notes']) ?> ・ 最後活動 <?= ($st['last'] ?? 0) ? date('m/d H:i', $st['last']) : '—' ?></span>
          </h4>
          <?php $mine = $byStudent[$sid] ?? []; if ($mine): ?>
            <div class="tbl-wrap"><table style="min-width:0">
              <tr><th>文本</th><th class="num">學生回應</th><th class="num">分支</th><th class="num">筆記泡泡</th><th>最後更新</th><th></th></tr>
              <?php foreach ($mine as $x): $xc = tr_count_one($x); ?>
              <tr>
                <td class="wrap" style="min-width:140px"><?= h($x['title']) ?></td>
                <td class="num"><?= $xc['resp'] ?></td>
                <td class="num"><?= $xc['branches'] ?></td>
                <td class="num"><?= $xc['notes'] ?></td>
                <td><?= date('m/d H:i', $x['updatedAt']) ?></td>
                <td><a class="btn small" href="teacher.php?course=<?= h($cur['id']) ?>&tab=detail&sid=<?= rawurlencode($sid) ?>&doc=<?= rawurlencode($x['docId']) ?>">查看完整對話</a></td>
              </tr>
              <?php endforeach; ?>
            </table></div>
          <?php else: ?>
            <p style="font-size:12.5px;color:var(--ink-faint)">
              <?= ($st['calls'] ?? 0) > 0
                  ? '這位學生有使用紀錄,但閱讀歷程尚未同步(可能是在此功能上線前使用,或只讀了自己上傳的文本)。'
                  : '尚未開始閱讀。' ?>
            </p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($students)): ?>
        <div class="panel"><p style="font-size:13px;color:var(--ink-faint)">還沒有學生加入這門課程。</p></div>
      <?php endif; ?>
    <?php endif; ?>

    <?php else: /* students */
      $tids = course_teacher_ids($cur);
      $canDel = $isDev || ($cur['owner'] ?? '') === $me['id'];
      $stuAcc = stu_read()['accounts'];
    ?>
      <?php if (!empty($_SESSION['stu_once'])): $so = $_SESSION['stu_once']; unset($_SESSION['stu_once']); ?>
      <div class="flash">已重設 <?= h($so['sid']) ?>（<?= h($so['email']) ?>）的密碼。臨時密碼：<b style="font-family:var(--mono);font-size:15px;letter-spacing:1px"><?= h($so['pwd']) ?></b>
        <br><span style="font-size:12px">這組密碼只顯示這一次，請轉告學生；學生用它登入後，系統會要求他自己設定新密碼。</span></div>
      <?php endif; ?>

      <?php /* 第一排:最常用的「讓學生加入」— 左邊邀請碼,右邊新增／匯入學生 */ ?>
      <div class="stu-top">
        <section class="panel invite-card">
          <span class="eyebrow">Invite</span><h4>邀請碼</h4>
          <div class="invite-row">
            <div class="code-mid" aria-label="邀請碼"><?= h($cur['code']) ?></div>
            <button type="button" class="btn primary" id="btnCopyInvite"
                    data-text="<?= h($baseUrl . ' 邀請碼：' . $cur['code']) ?>">複製連結與邀請碼</button>
          </div>
          <p class="help">學生打開前台，點右上角「加入課程」，輸入邀請碼與學號即可加入。</p>
          <form method="post" class="inline-link" onsubmit="return confirm('重新產生後，舊邀請碼即失效。確定？')">
            <input type="hidden" name="act" value="code_regen"><input type="hidden" name="course" value="<?= h($cur['id']) ?>">
            <button class="btn-text">重新產生邀請碼</button>
          </form>
        </section>

        <section class="panel">
          <span class="eyebrow">Add</span><h4>新增學生</h4>
          <form method="post" class="fld-row">
            <input type="hidden" name="act" value="student_add"><input type="hidden" name="course" value="<?= h($cur['id']) ?>">
            <label class="fld"><span>學號</span><input type="text" name="sid" required></label>
            <label class="fld"><span>姓名</span><input type="text" name="name"></label>
            <button class="btn">新增</button>
          </form>
          <details class="more">
            <summary>一次匯入整份名單</summary>
            <form method="post">
              <input type="hidden" name="act" value="students_import"><input type="hidden" name="course" value="<?= h($cur['id']) ?>">
              <label class="fld"><span>每行一位：學號 姓名（可直接從 Excel 框選兩欄複製貼上；逗號、Tab、空白分隔都可以）</span>
              <textarea name="bulk" rows="5" placeholder="111012345　張又懿&#10;111023456　楊以恩"></textarea></label>
              <div class="form-row"><button class="btn primary">匯入名單</button></div>
            </form>
          </details>
        </section>
      </div>

      <?php /* 第二排:學生名單(最常看的資料) */ ?>
      <section class="card tbl-card tbl-compact roster">
        <div class="card-h"><span class="eyebrow">Roster</span><h2>學生名單</h2><span class="aside">共 <?= count($students) ?> 人・曾使用 <?= $active ?> 人</span>
          <span class="grow"></span>
          <a class="btn small" href="teacher.php?course=<?= h($cur['id']) ?>&export=csv">匯出名單（CSV）</a></div>
        <div class="tbl-wrap">
        <table>
          <tr><th>姓名／學號</th><th>帳號（Email）</th><th class="num">學生回應</th><th class="num">分支</th><th class="num">筆記</th><th class="num">Tokens（入／出）</th><th>最後活動</th><th>加入方式</th><th>操作</th></tr>
          <?php foreach ($students as $sid => $st): $sc = $TC['bySid'][$sid] ?? ['resp' => 0, 'branches' => 0, 'notes' => 0];
                $em = $stuAcc[$st['acct'] ?? '']['email'] ?? ''; ?>
          <tr>
            <td class="cell-2"><b><?= h($st['name'] ?: '—') ?></b><span><?= h($sid) ?></span></td>
            <td><?= $em !== '' ? h($em) : '<span style="color:var(--text-faint);font-size:12px">尚未建立帳號</span>' ?></td>
            <td class="num"><?= n($sc['resp']) ?></td>
            <td class="num"><?= n($sc['branches']) ?></td>
            <td class="num"><?= n($sc['notes']) ?></td>
            <td class="num"><?= n($st['in'] ?? 0) ?> / <?= n($st['out'] ?? 0) ?></td>
            <td><?= ($st['last'] ?? 0) ? date('m/d H:i', $st['last']) : '—' ?></td>
            <td><span class="tag<?= !empty($st['self']) ? ' info' : '' ?>"><?= !empty($st['self']) ? '自行加入' : '名單匯入' ?></span></td>
            <td style="white-space:nowrap">
              <?php if ($em !== ''): ?>
              <form method="post" style="display:inline" onsubmit="return confirm(<?= js('把 ' . $sid . ' 的密碼重設成隨機臨時密碼?' . "\n" . '學生用臨時密碼登入後,必須自己設定新密碼。') ?>)"><input type="hidden" name="act" value="student_pwreset"><input type="hidden" name="course" value="<?= h($cur['id']) ?>"><input type="hidden" name="sid" value="<?= h($sid) ?>"><button class="btn-text" title="學生忘記密碼時使用">重設密碼</button></form>
              <?php endif; ?>
              <form method="post" style="display:inline" onsubmit="return confirm(<?= js('刪除學生 ' . $sid . '?') ?>)"><input type="hidden" name="act" value="student_del"><input type="hidden" name="course" value="<?= h($cur['id']) ?>"><input type="hidden" name="sid" value="<?= h($sid) ?>"><button class="btn-text danger">刪除</button></form>
            </td>
          </tr>
          <?php endforeach; if (empty($students)): ?>
          <tr><td colspan="9" class="empty-row">還沒有學生。把上方的邀請碼發給學生讓他們自行加入，或新增／匯入名單。</td></tr>
          <?php endif; ?>
        </table>
        </div>
      </section>

      <?php /* 第三排:不常用的課程設定,預設收合(加入／移除老師後自動展開) */ ?>
      <details class="panel settings" id="teachers"<?= isset($_GET['tok']) || isset($_GET['terr']) ? ' open' : '' ?>>
        <summary><span class="eyebrow">Settings</span><span class="s-title">課程設定</span><span class="aside">授課教師 <?= count($tids) ?> 位<?= $canDel ? '・刪除課程' : '' ?></span></summary>
        <?php /* 加入／移除老師的結果顯示在設定區內(頁面會捲到這裡) */ ?>
        <?php if (isset($_GET['tok'])): ?><div class="flash" style="margin:0 20px 12px"><?= h($_GET['tok']) ?></div><?php endif; ?>
        <?php if (isset($_GET['terr'])): ?><div class="flash err" style="margin:0 20px 12px"><?= h($_GET['terr']) ?></div><?php endif; ?>
        <div class="settings-body">
          <div>
            <h5>授課教師</h5>
            <p class="help" style="margin:0 0 8px">每位授課教師權限相同，都能管理學生、文本與解析；只有建立者可以刪除整門課。</p>
            <div class="t-list">
              <?php foreach ($tids as $tid): $ta = $ACC[$tid] ?? null; ?>
              <div class="t-item">
                <span><b><?= h($ta['name'] ?? '(帳號已不存在)') ?></b> <span class="t-login"><?= h($ta['login'] ?? '') ?></span>
                  <?= ($cur['owner'] ?? '') === $tid ? '<span class="tag">建立者</span>' : '' ?><?= $tid === $me['id'] ? '<span class="tag brand">我</span>' : '' ?><?= !empty($ta['disabled']) ? '<span class="tag">已停用</span>' : '' ?></span>
                <?php if (count($tids) > 1 && $tid !== ($cur['owner'] ?? '')): ?>
                <form method="post" style="display:inline" onsubmit="return confirm(<?= js($tid === $me['id'] ? '確定要退出這門課?退出後你將看不到這門課。' : '把 ' . ($ta['name'] ?? '這位老師') . ' 從這門課移除?') ?>)">
                  <input type="hidden" name="act" value="teacher_remove"><input type="hidden" name="course" value="<?= h($cur['id']) ?>">
                  <input type="hidden" name="tid" value="<?= h($tid) ?>">
                  <button class="btn-text danger"><?= $tid === $me['id'] ? '退出這門課' : '移除' ?></button></form>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <form method="post" class="fld-row" style="margin-top:10px">
              <input type="hidden" name="act" value="teacher_add"><input type="hidden" name="course" value="<?= h($cur['id']) ?>">
              <label class="fld wide"><span>加入授課教師（對方需已有教師帳號）</span><input type="text" name="login" placeholder="對方的教師帳號（Email）" required></label>
              <button class="btn">加入</button>
            </form>
          </div>
          <div class="danger-zone">
            <h5>刪除課程</h5>
            <?php if ($canDel): ?>
            <p class="help" style="margin:0 0 8px">連同所有學生資料一併刪除，無法復原。</p>
            <form method="post" onsubmit="return confirm(<?= js('刪除課程「' . $cur['name'] . '」與其所有學生資料？' . (count($tids) > 1 ? '這門課的其他授課教師也會一併失去這門課。' : '') . '此動作無法復原。') ?>)">
              <input type="hidden" name="act" value="course_del"><input type="hidden" name="course" value="<?= h($cur['id']) ?>">
              <button class="btn danger">刪除這門課</button>
            </form>
            <?php else: ?>
            <p class="help" style="margin:0">只有建立者（<?= h($ACC[$cur['owner'] ?? '']['name'] ?? '—') ?>）可以刪除課程；要離開這門課，請在上方授課教師清單按「退出這門課」。</p>
            <?php endif; ?>
          </div>
        </div>
      </details>
      <script>
      (function () {
        var b = document.getElementById('btnCopyInvite');
        if (b) b.onclick = function () {
          navigator.clipboard.writeText(b.dataset.text).then(function () {
            b.textContent = '已複製'; setTimeout(function () { b.textContent = '複製連結與邀請碼'; }, 1800);
          });
        };
      })();
      </script>
    <?php endif; ?>

  <?php endif; ?>
  </main>
</div>
<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>
<script>
(function () {
  var btn = document.getElementById('btnAUpload') || document.getElementById('btnTxUpload');
  if (!btn) return;
  var isTx = !document.getElementById('btnAUpload');
  var file   = document.getElementById(isTx ? 'txFile' : 'aFile');
  var status = document.getElementById(isTx ? 'txFileStatus' : 'aFileStatus');
  var area   = document.getElementById(isTx ? 'txBody' : 'aText');
  var title  = document.getElementById(isTx ? 'txTitle' : 'aTitle');
  btn.onclick = function () { file.click(); };
  title.addEventListener('input', function () { title.dataset.auto = ''; });

  /* docx(mammoth 的 HTML)→ 讀伴的文本格式:段落以空行分隔;標題 → 「# 」(h1) 「## 」(h2) 「### 」(h3 以下);
     清單每項一行;表格每列一行、欄位以「 | 」隔開;圖片略過(讀伴目前不存圖) */
  function docxHtmlToText(html) {
    var d = new DOMParser().parseFromString(html, 'text/html'), out = [], seenTitle = false;
    var txt = function (el) { return el.textContent.replace(/\s+/g, ' ').trim(); };
    /* 沒套標題樣式、用「整行粗體」手動做的標題:短、整段粗體、句尾不是逗號句號(問號可以),表/圖的標題除外。
       文件裡第一個當大標(#),「5.1」這類編號當小標(###),其餘 ## */
    var boldHead = function (el, t) {
      if (t.length > 40 || /[。，、；：,;]$/.test(t) || /^[表圖]\s*[一-十\d]+[：:]/.test(t)) return '';
      var plain = el.textContent.replace(/\s+/g, '');
      var bold = Array.prototype.map.call(el.querySelectorAll('strong,b'), function (x) { return x.textContent.replace(/\s+/g, ''); }).join('');
      if (!plain || bold.length < plain.length) return '';
      if (!seenTitle) { seenTitle = true; return '#'; }
      return /^\d+\.\d+/.test(t) ? '###' : '##';
    };
    Array.prototype.forEach.call(d.body.children, function (el) {
      var tag = el.tagName.toLowerCase(), m = tag.match(/^h([1-6])$/);
      if (m) { var lv = Math.min(parseInt(m[1], 10), 3), t = txt(el); if (t) { out.push('#'.repeat(lv) + ' ' + t); seenTitle = true; } return; }
      if (tag === 'ul' || tag === 'ol') {
        var items = Array.prototype.map.call(el.querySelectorAll('li'), function (li) { return '- ' + txt(li); }).filter(function (s) { return s !== '- '; });
        if (items.length) out.push(items.join('\n')); return;
      }
      if (tag === 'table') {
        var rows = Array.prototype.map.call(el.querySelectorAll('tr'), function (tr) {
          return Array.prototype.map.call(tr.querySelectorAll('td,th'), txt).join(' | ');
        }).filter(Boolean);
        if (rows.length) out.push(rows.join('\n')); return;
      }
      var t2 = txt(el);
      if (!t2) return;
      var bh = boldHead(el, t2);
      out.push(bh ? bh + ' ' + t2 : t2);
    });
    return out.join('\n\n');
  }
  function readText(f, enc) {
    return new Promise(function (res, rej) {
      var r = new FileReader();
      r.onload = function () { res(r.result); };
      r.onerror = function () { rej(new Error('讀取失敗')); };
      r.readAsText(f, enc || 'utf-8');
    });
  }
  function readBuf(f) {
    return new Promise(function (res, rej) {
      var r = new FileReader();
      r.onload = function () { res(r.result); };
      r.onerror = function () { rej(new Error('讀取失敗')); };
      r.readAsArrayBuffer(f);
    });
  }
  function reflow(text) {
    var TERMINAL = /[\u3002\uff01\uff1f!?\u2026\u300d\u300f\uff09\u3015\u3011\uff1b;\uff1a:]\s*$/;
    var out = [], buf = '', rows = [];
    var push = function () { if (buf.trim()) out.push(buf.trim()); buf = ''; if (rows.length) out.push(rows.join('\n')); rows = []; };
    text.split('\n').forEach(function (raw) {
      var line = raw.trim();
      if (!line) { push(); return; }
      if (/^#{1,3} /.test(line)) { push(); out.push(line); return; }   // 標題自成一段
      if (line.indexOf(' | ') >= 0) { if (buf.trim()) out.push(buf.trim()); buf = ''; rows.push(line); return; }   // 表格列:連續幾列合成一段、一列一行
      if (rows.length) push();
      // 接行時,兩邊都不是中文才補空白(英文行尾的逗號、引號後面也要空一格)
      if (buf && /[^㐀-鿿　-〿＀-￯]$/.test(buf) && /^[^㐀-鿿　-〿＀-￯]/.test(line)) buf += ' ' + line;
      else buf += line;
      if (TERMINAL.test(line)) { out.push(buf.trim()); buf = ''; }
    });
    push();
    return out.join('\n\n');
  }
  /* 掃描檔 OCR:在教師自己的瀏覽器裡用 Tesseract.js 辨識(不經伺服器、不花 API 費用)。
     每頁以約 300dpi 畫到 canvas,兩個 worker 並行;先試辨識一頁判斷繁體、簡體或英文(也可由教師指定),再整份辨識。
     辨識後去掉每頁重複出現的頁首頁尾與頁碼、接回英文斷字、去掉中文字間的空白。 */
  var TESS_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/7.0.0/tesseract.min.js';
  function loadScript(src) {
    return new Promise(function (ok, no) {
      if (window.Tesseract) return ok();
      var s = document.createElement('script');
      s.src = src; s.onload = ok; s.onerror = function () { no(new Error('文字辨識程式載入失敗，請檢查網路後再試')); };
      document.head.appendChild(s);
    });
  }
  /* 一頁 PDF 畫成要辨識的圖,回傳陣列:一般是一張;橫向的書本跨頁(左右兩頁掃在一起)找到中縫就切成左右兩張。
     跨頁若整張只畫 2500px 寬,每頁的字太小,實測正確率只有 52%;切開並讓每頁約 1600px 寬可到 96%。 */
  async function pageCanvas(pdf, n) {
    var page = await pdf.getPage(n), v1 = page.getViewport({ scale: 1 });
    var wide = v1.width > v1.height * 1.15;
    var vp = page.getViewport({ scale: Math.min(4, wide ? 3200 / v1.width : 2500 / Math.max(v1.width, v1.height)) });
    var cv = document.createElement('canvas'), ctx = cv.getContext('2d');
    cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
    // intent:'print' 不靠 requestAnimationFrame,教師切到別的分頁時(背景分頁會暫停 rAF)仍會繼續轉圖
    await page.render({ canvasContext: ctx, viewport: vp, intent: 'print' }).promise;
    var cut = wide ? findGutter(cv) : 0;
    if (!cut) return [cv];
    var halves = [[0, cut], [cut, cv.width]].map(function (r) {
      var h = document.createElement('canvas');
      h.width = r[1] - r[0]; h.height = cv.height;
      h.getContext('2d').drawImage(cv, r[0], 0, h.width, cv.height, 0, 0, h.width, cv.height);
      return h;
    });
    cv.width = cv.height = 0;
    return halves;
  }
  /* 找跨頁中縫(縮小成 800px 寬分析),回傳原圖的 x;找不到回傳 0(單頁橫式,不切)。
     1. 書本中縫多半有一道陰影:中間 20% 範圍內最暗的一欄比全頁中位亮度暗 30 以上,而且由上到下
        至少 70% 的高度都偏暗(排除頁面中間的圖片),就是中縫
        (實測 Andler 掃描 16 頁都暗 60–83、位置 0.49;文字欄平均只暗十幾)
     2. 沒有陰影時找空白縫:逐欄數明顯的上下明暗變化,不到中位數 15% 的欄算空白;中間 30% 內
        最寬的一段空白至少有頁寬 2%,就是中縫 */
  function findGutter(cv) {
    var W = 800, H = Math.round(cv.height * W / cv.width);
    var s = document.createElement('canvas'); s.width = W; s.height = H;
    var sx = s.getContext('2d'); sx.drawImage(cv, 0, 0, W, H);
    var px = sx.getImageData(0, 0, W, H).data, g = new Float32Array(W * H);
    for (var i = 0; i < W * H; i++) g[i] = px[i * 4] * 0.3 + px[i * 4 + 1] * 0.59 + px[i * 4 + 2] * 0.11;
    var median = function (a) { a = a.slice(W * 0.1, W * 0.9).sort(function (p, q) { return p - q; }); return a[Math.floor(a.length / 2)]; };
    var y0 = Math.round(H * 0.1), y1 = Math.ceil(H * 0.9), rows = y1 - y0;   // 只看中間 80% 高度(避開頁首頁尾)
    var mean = [], edges = [], x, y;
    for (x = 0; x < W; x++) {
      var t = 0, e = 0;
      for (y = y0; y < y1; y++) {
        t += g[y * W + x];
        if (Math.abs(g[y * W + x] - g[(y - 1) * W + x]) > 70) e++;
      }
      mean.push(t);
      edges.push(e);
    }
    var smooth = mean.map(function (v, k) { var a = Math.max(0, k - 3), b = Math.min(W, k + 4), s2 = 0; for (var j = a; j < b; j++) s2 += mean[j]; return s2 / (b - a); });
    var dark = -1;
    for (x = Math.round(W * 0.4); x <= W * 0.6; x++) if (dark < 0 || smooth[x] < smooth[dark]) dark = x;
    var pageGray = median(smooth) / rows;
    if (pageGray - smooth[dark] / rows > 30) {
      var tall = 0;                                      // 陰影要從上貫穿到下,圖片不會
      for (y = y0; y < y1; y++) if (g[y * W + dark] < pageGray - 20) tall++;
      if (tall >= rows * 0.7) return Math.round(dark * cv.width / W);
    }
    var em = median(edges), run = 0, best = 0, end = 0;
    if (em < 20) return 0;                               // 整頁幾乎沒字,不切
    // 中間 30% 內找最寬的一段空白;字與字之間也有單欄的細縫,所以要比寬度,不能只取最低的一欄
    for (x = Math.round(W * 0.35); x <= W * 0.65; x++) {
      run = edges[x] <= em * 0.15 ? run + 1 : 0;
      if (run > best) { best = run; end = x; }
    }
    if (best < W * 0.02) return 0;                       // 太窄:不是中縫
    return Math.round((end - (best - 1) / 2) * cv.width / W);
  }
  var CJK = '\u3400-\u9fff\uf900-\ufaff\u3000-\u303f\uff00-\uffef';
  var FULL = { ',': '，', ';': '；', ':': '：', '?': '？', '!': '！', '(': '（', ')': '）' };
  var C = '[' + CJK + ']', RE_CJK = new RegExp(C, 'g'), RE_GOOD = new RegExp('[A-Za-z0-9' + CJK + ']', 'g');
  var RE_PUNCT = new RegExp('(' + C + ')[ \\t]*([,;:?!()])[ \\t]*(?=' + C + '|$)', 'gm');
  var RE_DOT = new RegExp('(' + C + ')[ \\t]*\\.[ \\t]*(?=' + C + ')', 'g');
  var RE_GAP = new RegExp('(' + C + ')[ \\t]+(?=' + C + ')', 'g');
  function cleanOcr(t, zh) {
    if (zh) t = t.replace(RE_PUNCT, function (m, c, p) { return c + FULL[p]; })   // 中文旁的半形標點改全形
                 .replace(RE_DOT, '$1，');                                         // 中文中間的句點多半是逗號誤認
    return t.replace(RE_GAP, '$1')                                                // 中文字之間的空白
            .replace(/([a-z])[-\u2010\u00ad\u00ac][ \t]*\n[ \t]*([a-z])/g, '$1$2');                                                     // 英文行尾斷字
  }
  /* 頁首頁尾:出現在多頁開頭或結尾兩行、去掉數字後相同的行;單獨的頁碼行 */
  function stripRunning(pages) {
    // 只比字母與中文,並忽略 OCR 常互認錯的 i / l / 1 / |(如 WHAT IS ⇄ WHAT |S)
    var norm = function (l) { return l.toLowerCase().replace(/[^a-z\u3400-\u9fff]/g, '').replace(/[il]/g, ''); };
    var edge = function (ls) { ls = ls.filter(Boolean); return ls.slice(0, 2).concat(ls.slice(-2)); };
    var cnt = {};
    pages.forEach(function (ls) {
      var seen = {};
      edge(ls).forEach(function (l) { var k = norm(l); if (k && !seen[k]) { seen[k] = 1; cnt[k] = (cnt[k] || 0) + 1; } });
    });
    var min = Math.max(3, Math.ceil(pages.length * 0.25));
    return pages.map(function (ls) {
      var ends = edge(ls);
      return ls.filter(function (l) {
        if (/^\s*[\divxlcIVXLC]{1,4}\s*$/.test(l)) return false;
        // 照片、插圖被誤認出的雜訊:字母數字與中文不到一半
        var vis = l.replace(/\s/g, ''), good = (vis.match(RE_GOOD) || []).length;
        if (vis && good < vis.length * 0.5) return false;
        return !(ends.indexOf(l) >= 0 && (cnt[norm(l)] || 0) >= min);
      });
    });
  }
  var LANG_NAME = { chi_tra: '繁體中文', chi_sim: '簡體中文', eng: '英文' };
  // 預設是「單一文字區塊」,雙欄會左右混行;改成自動版面分析(PSM 3)才會分欄
  async function newWorker(lang) {
    var w = await Tesseract.createWorker([lang]);
    await w.setParameters({ tessedit_pageseg_mode: Tesseract.PSM.AUTO });
    return w;
  }
  /* 中文文件只用單一中文模型(混用英文模型會把部分中文行誤認成英文亂碼;中文模型本身也認得夾在中文裡的英文)。
     自動判斷:用繁、簡兩個模型各辨識一頁(取第 2 頁,避開封面),中文字少就用英文模型;
     繁體文件用繁體模型的信心分數會高出簡體模型 8 分以上,簡體文件兩者相差不到 2 分(繁體模型有時反而略高),
     所以繁體要高出 5 分才用繁體,否則用簡體。 */
  async function pickLang(pdf, forced) {
    if (forced && forced !== 'auto') return { lang: forced, keep: null };
    var cv = (await pageCanvas(pdf, Math.min(2, pdf.numPages)))[0];
    var ws = await Promise.all(['chi_tra', 'chi_sim'].map(newWorker));
    var rs = await Promise.all(ws.map(function (w) { return w.recognize(cv); }));
    var tra = rs[0].data, sim = rs[1].data;
    var zh = (tra.text.match(RE_CJK) || []).length > tra.text.replace(/\s/g, '').length * 0.3;
    var lang = !zh ? 'eng' : (tra.confidence - sim.confidence >= 5 ? 'chi_tra' : 'chi_sim');
    var keep = lang === 'eng' ? null : ws[lang === 'chi_tra' ? 0 : 1];   // 選中的那個留著接著用
    ws.forEach(function (w) { if (w !== keep) w.terminate(); });
    return { lang: lang, keep: keep };
  }
  async function ocrPdf(pdf) {
    var N = pdf.numPages, done = 0;
    status.textContent = '正在載入文字辨識程式（第一次使用需下載約 10–20 MB，之後會快很多）…';
    await loadScript(TESS_SRC);
    var sel = document.getElementById('txOcrLang');
    if (!sel || sel.value === 'auto') status.textContent = '正在判斷文件語言…';
    var pick = await pickLang(pdf, sel ? sel.value : 'auto'), lang = pick.lang, zh = lang !== 'eng';
    var workers = await Promise.all([0, 1].map(function (k) { return k === 0 && pick.keep ? pick.keep : newWorker(lang); }));
    var res = new Array(N), next = 0;
    var show = function () { status.textContent = '文字辨識中（' + LANG_NAME[lang] + '）：已完成 ' + done + ' / ' + N + ' 頁…'; };
    show();
    try {
      await Promise.all(workers.map(async function (w) {
        while (next < N) {
          var i = next++, cvs = await pageCanvas(pdf, i + 1);
          res[i] = [];
          for (var c = 0; c < cvs.length; c++) {          // 跨頁切開的左右兩頁各算一頁(頁首頁碼才去得掉)
            res[i].push(cleanOcr((await w.recognize(cvs[c])).data.text, zh).split('\n').map(function (l) { return l.trim(); }));
            cvs[c].width = cvs[c].height = 0;
          }
          done++; show();
        }
      }));
    } finally {
      workers.forEach(function (w) { w.terminate(); });
    }
    ocrLangUsed = LANG_NAME[lang];
    var flat = [].concat.apply([], res);
    return reflow(stripRunning(flat).map(function (ls) { return ls.join('\n'); }).join('\n\n'));
  }
  var ocrLangUsed = '';
  /* PDF 內建的文字層是否為亂碼:掃描檔有時已被別的軟體用錯誤語言做過 OCR(例如把中文當英文辨識),
     留下一層看不見的亂碼文字(這種情況已由 countInk 判為掃描檔);這裡處理看得到、但擷取出來是亂碼的文字。
     正常文字(含希臘文、數學符號、※●①★ 等)這類字元占 95% 以上,
     實測錯誤語言的 OCR 亂碼每頁只有 63–80%(夾雜大量 ~¥£»*\ 等),以 90% 為界。 */
  var RE_TEXTISH = new RegExp('[A-Za-z0-9' + CJK + '\u00c0-\u024f\u0370-\u03ff\u00a7\u00b0\u203b\u2190-\u21ff\u2200-\u22ff\u2460-\u27bf' +
    '.,;:?!()\\[\\]\'"%&/#+=<>@\u2018\u2019\u201c\u201d\u2013\u2014\u2026\u00b7\\-]', 'g');
  var OCR_HOW = '要在您的電腦上做文字辨識（OCR）轉成文字嗎？每頁約 5–15 秒，請保持這個頁面開著。辨識結果可能有錯字，完成後請先檢查再儲存。';
  /* 數一頁裡「畫出來」與「看不見」(文字繪製模式 3、7)的文字指令數 */
  async function countInk(page, ink) {
    var ol = await page.getOperatorList(), O = pdfjsLib.OPS, mode = 0, stack = [];
    ol.fnArray.forEach(function (fn, k) {
      if (fn === O.save) stack.push(mode);
      else if (fn === O.restore) mode = stack.length ? stack.pop() : 0;
      else if (fn === O.setTextRenderingMode) mode = ol.argsArray[k][0];
      else if (fn === O.showText || fn === O.showSpacedText || fn === O.nextLineShowText || fn === O.nextLineSetSpacingShowText) {
        if (mode === 3 || mode === 7) ink.hidden++; else ink.shown++;
      }
    });
  }
  function layerLooksBroken(t) {
    var vis = t.replace(/\s/g, '');
    if (vis.length < 200) return false;
    return (vis.match(RE_TEXTISH) || []).length < vis.length * 0.9;
  }
  file.onchange = async function () {
    var f = file.files[0];
    if (!f) return;
    status.textContent = '\u89e3\u6790\u4e2d\uff1a' + f.name;
    var ocrDone = false, keepFile = false;   // pdf/docx 解析成功就把檔案留在輸入框,隨表單送出保存為原檔
    try {
      if (f.size > 10 * 1024 * 1024) throw new Error('\u6a94\u6848\u904e\u5927\uff08\u4e0a\u9650 10MB\uff09');
      var name = f.name.toLowerCase(), text = '';
      if (name.endsWith('.txt') || name.endsWith('.md')) {
        text = await readText(f, 'utf-8');
        var bad = (text.match(/\uFFFD/g) || []).length;
        if (bad > Math.max(3, text.length * 0.01)) { try { text = await readText(f, 'big5'); } catch (e) {} }
      } else if (name.endsWith('.docx')) {
        if (typeof mammoth === 'undefined') throw new Error('docx \u51fd\u5f0f\u5eab\u672a\u8f09\u5165');
        /* 保留結構:Word 的標題樣式 → 「# 」「## 」「### 」開頭的一段(圖片略過) */
        var r1 = await mammoth.convertToHtml({ arrayBuffer: await readBuf(f) },
          { styleMap: ['p[style-name="Title"] => h1:fresh', 'p[style-name="Subtitle"] => h2:fresh'],
            convertImage: mammoth.images.imgElement(function () { return Promise.resolve({ src: '' }); }) });
        text = docxHtmlToText(r1.value);
      } else if (name.endsWith('.pdf')) {
        if (typeof pdfjsLib === 'undefined') throw new Error('PDF \u51fd\u5f0f\u5eab\u672a\u8f09\u5165');
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        var pdf = await pdfjsLib.getDocument({ data: await readBuf(f) }).promise;
        var pages = [], ink = { shown: 0, hidden: 0 }, heights = [];
        for (var i = 1; i <= pdf.numPages; i++) {
          var page = await pdf.getPage(i), content = await page.getTextContent();
          if (i <= 3) await countInk(page, ink);
          // 依 y 座標分行;同一行裡相隔很遠的兩塊(表格的欄)以「 | 」隔開(與前台 app.js 的 pdfPageLines 相同)
          var lastY = null, lt = '', lineH = 0, lastEnd = null, lines2 = [];
          var flush = function () { if (lt.trim()) lines2.push({ t: lt.trim(), h: lineH }); lt = ''; lineH = 0; lastEnd = null; };
          content.items.forEach(function (it) {
            var y = Math.round(it.transform[5]), x = it.transform[4], ih = it.height || 0;
            if (lastY !== null && Math.abs(y - lastY) > 4) flush();
            lastY = y;
            if (!it.str.trim()) { if (it.str && (it.width || 0) < 2 * (lineH || 12)) lt += ' '; return; }
            if (lastEnd !== null && x - lastEnd > Math.max(18, 2 * (ih || 12))) lt = lt.replace(/\s+$/, '') + ' | ';
            lt += it.str;
            lastEnd = x + (it.width || 0);
            lineH = Math.max(lineH, ih);
            for (var q = 0; q < it.str.length; q += 4) heights.push(ih);
          });
          flush();
          pages.push(lines2);
        }
        /* 標題推測:字級明顯大於全文中位數、不長、句尾沒有標點 → 「# 」開頭(教師可在文字框裡增刪) */
        heights.sort(function (a, b) { return a - b; });
        var medH = heights.length ? heights[Math.floor(heights.length / 2)] : 0;
        text = reflow(pages.map(function (ls) {
          return ls.map(function (l) {
            var t = l.t.trim();
            var ok = medH > 0 && t.indexOf(' | ') < 0 && t.length >= 2 && !/[\u3002\uff0c,\uff1b;\uff1a:\u3001]$/.test(t) && !/^[\d\s.\-\u2013\u2014]+$/.test(t);
            if (ok && l.h >= medH * 1.3 && t.length <= 60) return '# ' + t;
            if (ok && l.h >= medH * 1.07 && t.length <= 30) return '## ' + t;   // 略大的字:小標
            return l.t;
          }).join('\n');
        }).join('\n\n'));
        /* 掃描檔:幾乎沒有文字,或文字都是「看不見的」(其他軟體 OCR 後疊在掃描圖下的隱藏文字層,
           品質不一、常是用錯語言的亂碼)。一律以畫面上看得到的掃描圖為準,經教師同意後自己辨識。 */
        var noLayer = text.replace(/\s/g, '').length < pdf.numPages * 30;
        var hiddenLayer = !noLayer && ink.hidden > ink.shown;
        if (noLayer || hiddenLayer) {
          var why = noLayer ? '沒有可直接擷取的文字' : '內附的文字是其他軟體辨識的結果，讀伴不採用，會依掃描的畫面重新辨識';
          if (!confirm('這份 PDF 是掃描檔（共 ' + pdf.numPages + ' 頁），' + why + '。\n\n' + OCR_HOW)) { status.textContent = ''; file.value = ''; return; }
          text = await ocrPdf(pdf);
          ocrDone = true;
        } else if (layerLooksBroken(text)) {
          // 文字看得到但擷取出來是亂碼(多半是 PDF 字型缺少編碼對照表)
          if (confirm('這份 PDF 的文字擷取出來是亂碼（可能是 PDF 的字型編碼問題）。\n\n' + OCR_HOW + '\n\n按「取消」則保留擷取到的文字。')) {
            text = await ocrPdf(pdf);
            ocrDone = true;
          }
        }
      } else {
        throw new Error('\u4e0d\u652f\u63f4\u7684\u683c\u5f0f');
      }
      text = text.replace(/\r\n/g, '\n').trim();
      if (!text) throw new Error('\u6a94\u6848\u4e2d\u6c92\u6709\u8b80\u5230\u6587\u5b57');
      area.value = text;
      keepFile = isTx && /\.(pdf|docx)$/i.test(name);
      if (isTx) txCount();
      // 標題:空白或先前自動帶入的檔名,就換成這個檔的檔名;教師自己打的標題不動
      if (!title.value.trim() || title.dataset.auto === '1') { title.value = f.name.replace(/\.[^.]+$/, ''); title.dataset.auto = '1'; }
      status.textContent = '\u5df2\u8f09\u5165\uff1a' + f.name + '\uff08' + text.length.toLocaleString() + ' \u5b57\uff09';
      if (ocrDone) {
        status.textContent += '——以' + ocrLangUsed + '辨識，請檢查後再儲存';
        alert('辨識完成（' + ocrLangUsed + '），共 ' + text.length.toLocaleString() + ' 字。\n\n文字辨識可能有錯字、漏字或多餘的頁首頁尾，請先瀏覽文本框內容，必要時修正，再按「' + (area.form.text_id.value ? '儲存變更' : '新增文本') + '」。\n\n如果整篇錯得很多，可能是語言判斷錯了：請在「上傳檔案」旁的選單指定繁體或簡體，再上傳一次。');
      }
    } catch (e) {
      status.textContent = '';
      alert('\u4e0a\u50b3\u5931\u6557\uff1a' + e.message);
    }
    if (!keepFile) file.value = '';
  };
  /* 教師版上限:中文每篇 10 萬字、英文不限字數——以「AI 讀取量」計(中文字算 1、其他字元 4 個算 1),
     與 school_lib 的 course_text_load 同算法;即時字數,超過時轉紅並在送出前擋下 */
  var RE_ZH = /[　-〿㐀-鿿豈-﫿＀-￯]/g;
  function courseLoad(t) { var zh = (t.match(RE_ZH) || []).length; return Math.ceil(zh + (t.length - zh) / 4); }
  function txOver() { return courseLoad(area.value.trim()) > 100000; }
  function txCount() {
    var box = document.getElementById('txLimit'), out = document.getElementById('txCount');
    if (!box) return;
    var n = area.value.trim().length, over = txOver();
    box.style.color = over ? 'var(--vermilion)' : 'var(--ink-soft)';
    box.style.fontWeight = over ? '600' : '400';
    out.textContent = n ? '\u76ee\u524d ' + n.toLocaleString() + ' \u5b57' + (over ? '\uff0c\u5df2\u8d85\u904e\u4e0a\u9650' : '') : '';
  }
  if (isTx) {
    area.addEventListener('input', txCount);
    txCount();
    area.form.addEventListener('submit', function (e) {
      if (txOver()) {
        e.preventDefault();
        alert('\u9019\u7bc7\u8d85\u904e\u6bcf\u7bc7\u4e0a\u9650\uff08\u4e2d\u6587 100,000 \u5b57\uff1b\u82f1\u6587\u7d04 400,000 \u5b57\u5143\uff09\uff0c\u8acb\u522a\u6e1b\u6216\u62c6\u6210\u591a\u7bc7\u4e0a\u50b3\u3002');
      }
    });
  }
})();
</script>
</body>
</html>
