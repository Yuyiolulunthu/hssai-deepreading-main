<?php
/**
 * 讀伴 — 課程與學生資料層
 * 儲存於 data/school.json(flock 檔案鎖，免資料庫）。
 */
require_once __DIR__ . '/stats_lib.php';   // 共用 data/ 目錄保護

/* 教師上傳的課程文本長度上限:中文每篇 10 萬字;英文不限字數(只看上傳檔案 10MB)。
   以「AI 讀取量」計:中文字(含全形標點)每字算 1,其他字元每 4 個算 1(約略等於 token 數),
   所以純中文 = 10 萬字,純英文約 40 萬字元(一般章節遠低於此,只擋整本書這種會超出模型可讀長度的)。
   teacher.php 前端有同樣的算法(courseLoad) */
const COURSE_MAX_LOAD = 100000;
const COURSE_MAX_CHARS = 400000;   // api.php 收 docText 的截斷長度(= 純英文的上限)
function course_text_load($t) {
    $all = mb_strlen($t);
    $zh = preg_match_all('/[\x{3000}-\x{303f}\x{3400}-\x{9fff}\x{f900}-\x{faff}\x{ff00}-\x{ffef}]/u', $t);
    return (int)ceil($zh + ($all - $zh) / 4);
}

function school_path() { return __DIR__ . '/data/school.json'; }

function school_default() { return ['courses' => []]; }

function school_read() {
    stats_ensure_dir();
    if (!file_exists(school_path())) return school_default();
    $fp = @fopen(school_path(), 'r');
    if (!$fp) return school_default();
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $s = json_decode($raw, true);
    return (is_array($s) && isset($s['courses'])) ? $s : school_default();
}

function school_update(callable $fn) {
    stats_ensure_dir();
    $fp = @fopen(school_path(), 'c+');
    if (!$fp) return null;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return null; }
    $raw = stream_get_contents($fp);
    $s = json_decode($raw, true);
    if (!is_array($s) || !isset($s['courses'])) $s = school_default();
    $ret = $fn($s);
    json_rewrite($fp, $s, JSON_UNESCAPED_UNICODE);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ret;
}

function school_gen_code() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // 去除易混淆字元
    $c = '';
    for ($i = 0; $i < 6; $i++) $c .= $chars[random_int(0, strlen($chars) - 1)];
    return $c;
}

function &school_find_course(&$s, $id) {
    foreach ($s['courses'] as &$c) if ($c['id'] === $id) return $c;
    $null = null; return $null;
}

function school_find_by_code($s, $code) {
    foreach ($s['courses'] as $c) if (strcasecmp($c['code'], $code) === 0) return $c;
    return null;
}

/** 記錄學生活動（由 api.php 呼叫；失敗靜默） */
function school_record_activity($courseId, $sid, $mode, $docTitle, $in, $out) {
    $sid = trim((string)$sid);
    if ($courseId === '' || $sid === '') return;
    school_update(function (&$s) use ($courseId, $sid, $in, $out) {
        $c = &school_find_course($s, $courseId);
        if ($c === null) return null;
        if (!isset($c['students'][$sid])) {
            $c['students'][$sid] = ['name' => '（未登記）', 'self' => true,
                'calls' => 0, 'in' => 0, 'out' => 0, 'last' => 0];
        }
        $st = &$c['students'][$sid];
        $st['calls'] = ($st['calls'] ?? 0) + 1;
        $st['in']  = ($st['in']  ?? 0) + (int)$in;
        $st['out'] = ($st['out'] ?? 0) + (int)$out;
        // 對話的計數改由逐字稿計算「學生回應」(見 tr_summary),這裡只記用量與最後活動
        $st['last'] = time();
        return null;
    });
}

/* ================= 指定文本(教師端上傳,學生端閱讀) ================= */

/** 舊資料相容:texts 曾為字串陣列,統一正規化為物件陣列 */
/** 刪除一門課在 data/ 底下的所有檔案:解析、逐字稿、學習分析(school.json 的項目另外處理) */
function course_purge_files($courseId) {
    $cid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$courseId);
    if ($cid === '') return;
    foreach (['analysis', 'maps', 'transcripts', 'review', 'briefings'] as $sub) {
        $dir = __DIR__ . '/data/' . $sub . '/' . $cid;
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($dir);
    }
}

/** 課程的授課教師帳號 id;舊資料只有 owner(建立者) */
function course_teacher_ids($c) {
    $t = $c['teachers'] ?? null;
    if (is_array($t) && $t) return array_values($t);
    return !empty($c['owner']) ? [$c['owner']] : [];
}

function school_norm_texts($texts) {
    $out = [];
    foreach ((array)$texts as $i => $t) {
        if (is_string($t)) {
            $out[] = ['id' => 't_' . substr(md5($t . $i), 0, 8), 'title' => $t, 'text' => '', 'ts' => 0];
        } elseif (is_array($t) && isset($t['title'])) {
            $out[] = [
                'id'    => $t['id'] ?? ('t_' . substr(md5($t['title'] . $i), 0, 8)),
                'title' => $t['title'],
                'text'  => $t['text'] ?? '',
                'ts'    => $t['ts'] ?? 0,
                'by'    => $t['by'] ?? '',      // 最後修改的教師(顯示用)
                'orig'  => $t['orig'] ?? '',    // 原始檔案的副檔名(pdf/docx;存在 data/texts/<課程>/<文本>/orig.<ext>),沒有就空字串
            ];
        }
    }
    return $out;
}

/** 以邀請碼 + 學號驗證學生身分,回傳課程(找不到回 null) */
function school_auth_student($code, $sid) {
    $s = school_read();
    $c = school_find_by_code($s, $code);
    if (!$c) return null;
    $sid = trim((string)$sid);
    if ($sid === '') return null;
    return $c;
}

/* ---------- 原始檔案(教師上傳 pdf/docx 時一併保存,學生可按「看原檔」對照版面與圖表) ---------- */
function school_text_dir($courseId, $textId) {
    $safe = fn($s) => preg_replace('/[^A-Za-z0-9_-]/', '', (string)$s);
    return __DIR__ . '/data/texts/' . $safe($courseId) . '/' . $safe($textId);
}
/** 存入上傳的原檔(只收 pdf/docx、10MB 內),回傳副檔名;不合格回空字串(舊檔不動) */
function school_save_orig($courseId, $textId, array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return '';
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'docx'], true) || (int)$file['size'] > 10 * 1024 * 1024) return '';
    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 4);
    if (($ext === 'pdf' && strncmp($head, '%PDF', 4) !== 0) || ($ext === 'docx' && strncmp($head, "PK\x03\x04", 4) !== 0)) return '';
    $dir = school_text_dir($courseId, $textId);
    stats_ensure_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    foreach (['pdf', 'docx'] as $e) @unlink("$dir/orig.$e");
    return move_uploaded_file($file['tmp_name'], "$dir/orig.$ext") ? $ext : '';
}
/** 刪除文本時連原檔一起移除 */
function school_remove_text_dir($courseId, $textId) {
    $dir = school_text_dir($courseId, $textId);
    foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
    @rmdir($dir);
    @rmdir(dirname($dir));
}
/** 這個人(學生 cookie 或後台 session)能不能看這門課的文本 */
function school_can_view($courseId) {
    $course = null;
    foreach (school_read()['courses'] as $c) if ($c['id'] === $courseId) { $course = $c; break; }
    if (!$course) return false;
    $stu = stu_user();
    if ($stu && isset($stu['courses'][$courseId]) && isset($course['students'][(string)$stu['courses'][$courseId]])) return true;
    auth_session_start();
    $u = auth_user();
    return $u && (auth_is_dev($u) || in_array($u['id'], course_teacher_ids($course), true));
}

/** 取課程中的單篇文本 */
function school_get_text($courseId, $textId) {
    $s = school_read();
    foreach ($s['courses'] as $c) {
        if ($c['id'] !== $courseId) continue;
        foreach (school_norm_texts($c['texts'] ?? []) as $t) {
            if ($t['id'] === $textId) return $t;
        }
    }
    return null;
}

/* ================= 學生閱讀歷程(逐字稿) ================= */

/** 取某課程的所有逐字稿檔案摘要,可選指定學號 */
function transcripts_for($courseId, $sid = null) {
    $dir = __DIR__ . '/data/transcripts/' . preg_replace('/[^A-Za-z0-9_-]/', '', $courseId);
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (glob($dir . '/*.json') as $f) {
        $d = json_decode(@file_get_contents($f), true);
        if (!is_array($d)) continue;
        if ($sid !== null && ($d['sid'] ?? '') !== $sid) continue;
        $out[] = $d;
    }
    usort($out, fn($a, $b) => ($b['updatedAt'] ?? 0) - ($a['updatedAt'] ?? 0));
    return $out;
}

/**
 * 全站統一的計數單位:學生回應(學生送出的訊息),不含讀伴的開場與回覆。
 * 另計最後的分支數與筆記數(不論筆記來源)。
 */
function tr_count_one(array $tr) {
    $resp = 0; $branches = 0;
    foreach (($tr['nodes'] ?? []) as $nd) {
        if (!is_array($nd)) continue;
        if (($nd['type'] ?? '') === 'branch') $branches++;
        foreach (($nd['messages'] ?? []) as $m) if (is_array($m) && ($m['role'] ?? '') === 'user') $resp++;
    }
    return ['resp' => $resp, 'branches' => $branches, 'notes' => is_array($tr['notes'] ?? null) ? count($tr['notes']) : 0];
}

/**
 * 一門課所有逐字稿的計數。快取在 data/transcripts/<cid>/_counts.cache(不是 .json,以免被 transcripts_for 讀到),
 * 只重算有更新(mtime 改變)的逐字稿,教師後台不必每次讀完所有對話全文。
 * 回傳 ['bySid' => [sid => 計數＋texts], 'byTitle' => [標題 => 計數＋students(有回應的學生數)]]
 */
function tr_summary($courseId) {
    $dir = __DIR__ . '/data/transcripts/' . preg_replace('/[^A-Za-z0-9_-]/', '', $courseId);
    $sum = ['bySid' => [], 'byTitle' => []];
    if (!is_dir($dir)) return $sum;
    $cf = $dir . '/_counts.cache';
    $cache = json_decode((string)@file_get_contents($cf), true) ?: [];
    $rows = []; $dirty = false;
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        $b = basename($f); $mt = filemtime($f);
        if (isset($cache[$b]) && ($cache[$b]['mt'] ?? 0) === $mt) { $rows[$b] = $cache[$b]; continue; }
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) continue;
        $rows[$b] = ['mt' => $mt, 'sid' => (string)($d['sid'] ?? ''), 'title' => (string)($d['title'] ?? '')] + tr_count_one($d);
        $dirty = true;
    }
    if ($dirty || count($rows) !== count($cache)) json_save($cf, $rows, JSON_UNESCAPED_UNICODE);
    $z = ['resp' => 0, 'branches' => 0, 'notes' => 0];
    foreach ($rows as $r) {
        $s = &$sum['bySid'][$r['sid']];   $s = ($s ?? $z + ['texts' => 0]);
        $t = &$sum['byTitle'][$r['title']]; $t = ($t ?? $z + ['students' => []]);
        foreach ($z as $k => $_) { $s[$k] += $r[$k]; $t[$k] += $r[$k]; }
        if ($r['resp'] > 0) { $s['texts']++; $t['students'][$r['sid']] = 1; }
        unset($s, $t);
    }
    foreach ($sum['byTitle'] as &$t) $t['students'] = count($t['students']);
    unset($t);
    return $sum;
}

/** 取單份逐字稿 */
function transcript_get($courseId, $sid, $docId) {
    foreach (transcripts_for($courseId, $sid) as $t) {
        if (($t['docId'] ?? '') === $docId) return $t;
    }
    return null;
}
