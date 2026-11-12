<?php
/**
 * 由環境變數產生 config.php（容器啟動時執行，見 deploy/railway/entrypoint.sh）。
 * 值直接寫成字面量而非 getenv()，避免 Apache 是否把環境變數傳給 PHP 的不確定性。
 * 用法：php make_config.php <輸出路徑>
 */
$out = $argv[1] ?? '/var/www/html/config.php';

$key = getenv('ANTHROPIC_API_KEY');
if (!is_string($key) || $key === '') {
    fwrite(STDERR, "!! 缺少環境變數 ANTHROPIC_API_KEY，無法產生 config.php\n");
    exit(1);
}

/** 字串常數；$def 為環境變數沒給時的預設值 */
$str = [
    'ANTHROPIC_API_KEY' => null,
    'ANTHROPIC_MODEL'   => 'claude-sonnet-4-6',
    'TEACHER_PASSWORD'  => 'teacher-changeme',
    'DEV_PASSWORD'      => 'dev-changeme',
    'ADMIN_PASSWORD'    => 'changeme',
    'SITE_MODE'         => 'both',
];
$num = [
    'MAX_TOKENS'       => 1024,
    'PRICE_IN_PER_M'   => 3.0,
    'PRICE_OUT_PER_M'  => 15.0,
];

$lines = ["<?php", "// 由 deploy/railway/make_config.php 於容器啟動時產生，請勿手動編輯"];
foreach ($str as $name => $def) {
    $v = getenv($name);
    $v = (is_string($v) && $v !== '') ? $v : $def;
    $lines[] = sprintf("define('%s', %s);", $name, var_export((string)$v, true));
}
foreach ($num as $name => $def) {
    $v = getenv($name);
    $v = (is_string($v) && $v !== '') ? $v + 0 : $def;
    $lines[] = sprintf("define('%s', %s);", $name, var_export($v, true));
}

if (file_put_contents($out, implode("\n", $lines) . "\n") === false) {
    fwrite(STDERR, "!! 無法寫入 $out\n");
    exit(1);
}
echo "config.php 已產生：$out\n";
