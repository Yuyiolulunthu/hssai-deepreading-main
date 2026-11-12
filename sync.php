<?php
/**
 * 讀伴 — 課程學生的閱讀歷程同步
 * 課程模式下,學生端會把該文本的對話樹(主幹、分支、筆記、畫線)快照上傳,
 * 讓教師能在後台看見學生實際如何使用,而不只是輪數。
 * 僅限已加入該課程的學號;儲存於 data/transcripts/<courseId>/<sid>__<docId>.json
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/stu_lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$raw = file_get_contents('php://input');
if (strlen($raw) > 2 * 1024 * 1024) { http_response_code(413); echo '{"ok":false,"error":"內容過長"}'; exit; }
$req = json_decode($raw, true) ?: [];

$courseId = u_cut(trim($req['courseId'] ?? ''), 20);
$sid      = u_cut(trim($req['sid'] ?? ''), 30);
$snap     = $req['snapshot'] ?? null;
if ($courseId === '' || $sid === '' || !is_array($snap)) { http_response_code(400); echo '{"ok":false}'; exit; }

/* 僅接受登入者本人、且仍在該課程名單中的學號 */
if (!stu_owns($courseId, $sid)) { http_response_code(403); echo '{"ok":false}'; exit; }
$course = null;
foreach (school_read()['courses'] as $c) if ($c['id'] === $courseId) { $course = $c; break; }
if (!$course || !isset($course['students'][$sid])) { http_response_code(403); echo '{"ok":false}'; exit; }

$docId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($snap['docId'] ?? ''));
if ($docId === '') { http_response_code(400); echo '{"ok":false}'; exit; }

stats_ensure_dir();
$dir = __DIR__ . '/data/transcripts/' . preg_replace('/[^A-Za-z0-9_-]/', '', $courseId);
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$safeSid = preg_replace('/[^A-Za-z0-9_\-]/u', '_', $sid);
$path = $dir . '/' . $safeSid . '__' . $docId . '.json';

$record = [
    'sid'       => $sid,
    'name'      => $course['students'][$sid]['name'] ?? '',
    'docId'     => $docId,
    'title'     => u_cut((string)($snap['title'] ?? ''), 80),
    'updatedAt' => time(),
    'nodes'     => $snap['nodes'] ?? [],
    'notes'     => $snap['notes'] ?? [],
    'highlights'=> $snap['highlights'] ?? [],
    // 接續閱讀用:哪一篇課程文本、書籤範圍、目前所在的段落(主幹)、導讀是否看過
    'textId'    => u_cut((string)($snap['textId'] ?? ''), 30),
    'state'     => is_array($snap['state'] ?? null) ? array_intersect_key($snap['state'], array_flip(['rootId', 'bm', 'briefingDone', 'persona', 'createdAt'])) : [],
];
json_save($path, $record, JSON_UNESCAPED_UNICODE);
echo '{"ok":true}';
