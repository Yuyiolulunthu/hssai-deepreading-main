<?php
/**
 * 在主機上用命令列建立後台帳號(主要用來建第一位開發者;之後都在 dev.php 裡管理)
 *
 *   php tools/create_account.php dev  someone@example.com "顯示名稱"
 *   php tools/create_account.php teacher t001 "某某老師"
 *   php tools/create_account.php dev someone@example.com --reset   ← 帳號已存在時重設密碼(唯一的開發者忘記密碼時用)
 *
 * 會產生一組初始密碼並印出來,第一次登入時強制修改。
 * 只能從命令列執行;tools/ 目錄另有 .htaccess 擋掉網頁存取。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../account_lib.php';

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--reset'));
$reset = in_array('--reset', $argv, true);
[$role, $login, $name] = array_pad($args, 3, '');
if (!isset(ACC_ROLES[$role]) || $login === '') {
    fwrite(STDERR, "用法: php tools/create_account.php <dev|teacher> <帳號> [顯示名稱]\n");
    fwrite(STDERR, "      php tools/create_account.php <dev|teacher> <帳號> --reset   (帳號已存在時重設密碼,例如唯一的開發者忘記密碼)\n");
    exit(1);
}
$pwd = acc_gen_password();
$existing = acc_find($login);
if ($existing && $reset) {
    if ($existing['role'] !== $role) { fwrite(STDERR, "失敗:這個帳號的角色是「" . ACC_ROLES[$existing['role']] . "」,與指定的不符\n"); exit(1); }
    $r = acc_set_password($existing['id'], $pwd, true, 'cli');
    if (!empty($r['error'])) { fwrite(STDERR, '失敗:' . $r['error'] . "\n"); exit(1); }
    if (!empty($existing['disabled'])) { acc_set_disabled($existing['id'], false, 'cli'); echo "(帳號原本是停用狀態,已一併啟用)\n"; }
    echo "已重設" . ACC_ROLES[$role] . "帳號的密碼\n";
} elseif ($existing) {
    fwrite(STDERR, "失敗:這個帳號已經存在。要重設密碼請加 --reset\n"); exit(1);
} else {
    $r = acc_create($login, $name, $role, $pwd, 'cli', true);
    if (empty($r['ok'])) { fwrite(STDERR, '失敗:' . $r['error'] . "\n"); exit(1); }
    echo "已建立" . ACC_ROLES[$role] . "帳號\n";
}
echo "  帳號:" . acc_norm_login($login) . "\n";
echo "  初始密碼:{$pwd}\n";
echo "  (第一次登入時會要求修改)\n";
