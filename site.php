<?php
/**
 * 讀伴 — 站台模式設定(供前端啟動時讀取)
 *   open   = 公開測試版:任何人可自行上傳文本,不綁課程
 *   course = 課程正式版:需以邀請碼登入,只能閱讀教師指定的文本
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/guest_lib.php';

$mode = 'both';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    if (defined('SITE_MODE') && in_array(SITE_MODE, ['both', 'open', 'course'], true)) $mode = SITE_MODE;
}
echo json_encode([
    'mode' => $mode,
    'siteName' => defined('SITE_NAME') ? SITE_NAME : '讀伴・樹狀閱讀',
    'guest' => ['maxChars' => GUEST_MAX_CHARS, 'maxBytes' => GUEST_MAX_BYTES, 'maxTurns' => GUEST_MAX_TURNS],
], JSON_UNESCAPED_UNICODE);
