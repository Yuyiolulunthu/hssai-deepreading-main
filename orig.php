<?php
/**
 * 讀伴 — 看原檔
 * 教師上傳 pdf/docx 時原檔一併保存在 data/(Apache 層封鎖),由這裡經權限檢查後送出:
 *   orig.php?c=課程&t=文本          → 檔案本身(PDF 瀏覽器直接顯示;docx 給下方的檢視頁用)
 *   orig.php?c=課程&t=文本&view=1   → 檢視頁:PDF 轉到檔案本身;docx 在瀏覽器裡用 mammoth 轉成網頁顯示
 * 可看的人:該課程的學生、授課教師、開發者。
 */
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/school_lib.php';
require_once __DIR__ . '/stu_lib.php';

$cid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['c'] ?? ''));
$tid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['t'] ?? ''));
if ($cid === '' || $tid === '') { http_response_code(404); exit('找不到檔案'); }
if (!school_can_view($cid)) { http_response_code(403); exit('沒有權限：請先登入讀伴'); }
$tx = school_get_text($cid, $tid);
$ext = $tx['orig'] ?? '';
$path = $ext ? school_text_dir($cid, $tid) . "/orig.$ext" : '';
if ($path === '' || !is_file($path)) { http_response_code(404); exit('這篇文本沒有原始檔'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$q = 'c=' . rawurlencode($cid) . '&t=' . rawurlencode($tid);

if (empty($_GET['view'])) {
    $mime = $ext === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $fname = preg_replace('/[\\\\\/:*?"<>|\r\n]+/', '_', (string)$tx['title']) . '.' . $ext;
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode($fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
if ($ext === 'pdf') { header('Location: orig.php?' . $q); exit; }
?><!doctype html>
<html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>原檔：<?= $h($tx['title']) ?></title>
<style>
  body { margin: 0; background: #F3F0EB; font-family: "Noto Sans TC", system-ui, sans-serif; color: #1F1B1A; }
  .bar { position: sticky; top: 0; display: flex; align-items: center; gap: 12px; padding: 8px 16px; background: #fff; border-bottom: 1px solid #E7E1DA; font-size: 13px; }
  .bar b { font-weight: 600; }
  .bar a { margin-left: auto; color: #7A2331; text-decoration: none; }
  article { max-width: 820px; margin: 20px auto 60px; padding: 36px 40px; background: #fff; line-height: 1.9; font-size: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
  article img { max-width: 100%; height: auto; }
  article table { border-collapse: collapse; margin: 12px 0; font-size: 14px; }
  article td, article th { border: 1px solid #CFC8C0; padding: 4px 10px; vertical-align: top; }
  article h1 { font-size: 1.5em; } article h2 { font-size: 1.25em; } article h3 { font-size: 1.1em; }
  #msg { text-align: center; padding: 40px; color: #6b625e; }
  @media (max-width: 600px) { article { margin: 0; padding: 20px 16px; } }
</style></head><body>
<div class="bar"><b><?= $h($tx['title']) ?></b><span>原始檔案（Word 轉成網頁，版面與原檔略有差異）</span><a href="orig.php?<?= $q ?>" download>下載 .docx</a></div>
<div id="msg">載入中⋯</div>
<article id="doc"></article>
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>
<script>
fetch('orig.php?<?= $q ?>').then(r => { if (!r.ok) throw new Error('讀取失敗（' + r.status + '）'); return r.arrayBuffer(); })
  .then(b => mammoth.convertToHtml({ arrayBuffer: b }))
  .then(r => { document.getElementById('doc').innerHTML = r.value; document.getElementById('msg').remove(); })
  .catch(e => { document.getElementById('msg').textContent = '無法顯示：' + e.message; });
</script>
</body></html>
