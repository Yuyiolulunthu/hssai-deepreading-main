<?php
/**
 * 讀伴・樹狀閱讀 — 設定檔
 *
 * 部署步驟：
 *   1. 將本檔案複製並改名為 config.php
 *   2. 填入你的 Anthropic API Key(https://console.anthropic.com 取得）
 *   3. 確認主機 PHP 已啟用 curl 擴充（cPanel 預設皆有）
 */

// 你的 Anthropic API Key（以 sk-ant- 開頭）
define('ANTHROPIC_API_KEY', 'sk-ant-xxxxxxxxxxxxxxxxxxxx');

// 使用的模型(與正式站一致;Sonnet 5.5 會自動帶 thinking 設定,見 prompt_lib 的 dupan_model_extra)
define('ANTHROPIC_MODEL', 'claude-sonnet-5-5');

// 每次回覆的最大 token 數
define('MAX_TOKENS', 1600);

// 教師後台 teacher.php 與管理者後台 dev.php 已改用個人帳號(data/accounts.json),
// 第一位開發者用 php tools/create_account.php 建立;TEACHER_PASSWORD / DEV_PASSWORD 不再被讀取。
// define('SCHOOL_PASSWORD', 'school-changeme');  // 學校後台 school.php(預留,取消註解即啟用)
define('ADMIN_PASSWORD', 'changeme');             // 僅供尚未改用帳號制的頁面回退使用
// 沒有反向代理(nginx)時設為 false,登入節流與稽核的 IP 才不會被客戶端自己塞的 X-Real-IP 騙過
// define('TRUST_X_REAL_IP', false);

// 花費估算用定價（美元/每百萬 tokens);Claude Sonnet 5.5 定價
define('PRICE_IN_PER_M', 2.0);
define('PRICE_OUT_PER_M', 10.0);

// 簡要查詢(lookup.php)供應商:設定 Perplexity 金鑰即優先使用(sonar 模型,便宜且自帶搜尋);
// 未設定則回退 Anthropic(可用 LOOKUP_MODEL 指定較便宜的模型)。
// define('PERPLEXITY_API_KEY', 'pplx-xxxx');
// define('PERPLEXITY_MODEL', 'sonar');
// define('LOOKUP_MODEL', 'claude-haiku-4-5-20251001');

// 站台模式:
//   'both'   同站兩種入口——首頁先選「課程學生」或「自由試用」(建議)
//   'open'   僅公開版——直接上傳文本,不出現課程入口
//   'course' 僅課程版——只能以邀請碼登入
define('SITE_MODE', 'both');
