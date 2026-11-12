<?php
/** 讀伴 — 頁面瀏覽計數（由前端於載入時呼叫一次） */
require_once __DIR__ . '/stats_lib.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stats_record_view();
}
header('Content-Type: application/json');
echo '{"ok":true}';
