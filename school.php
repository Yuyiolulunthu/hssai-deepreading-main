<?php
/**
 * 讀伴 ・ 學校後台(預留)
 * 唯讀:檢視各教師開設的課程,與教師/學生的使用統計。
 * 不可調整任何 Prompt,不可管理課程。
 * 目前關閉(2026-09-24 起正式站不設 SCHOOL_PASSWORD)。日後開放多校時,改為帳號制的 school 角色:
 * 每校一位管理者,管理該校自己的教師與學生,分擔開發者的工作。不要再回到共用密碼。
 */
require_once __DIR__ . '/account_lib.php';
auth_session_start();                       // 與教師/開發者後台同一套 cookie 參數(HttpOnly、SameSite、Secure)
require_once __DIR__ . '/stats_lib.php';
require_once __DIR__ . '/school_lib.php';

$configured = file_exists(__DIR__ . '/config.php');
if ($configured) require_once __DIR__ . '/config.php';
$SCHOOL_PWD = defined('SCHOOL_PASSWORD') ? SCHOOL_PASSWORD : '';
$hasPwd = $SCHOOL_PWD !== '';

$priceIn  = defined('PRICE_IN_PER_M')  ? PRICE_IN_PER_M  : 3.0;
$priceOut = defined('PRICE_OUT_PER_M') ? PRICE_OUT_PER_M : 15.0;

if (isset($_GET['logout'])) { unset($_SESSION['dupan_school']); header('Location: school.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pwd'])) {
    if ($hasPwd && hash_equals($SCHOOL_PWD, (string)$_POST['pwd'])) {
        $_SESSION['dupan_school'] = true;
        header('Location: school.php'); exit;
    }
    $err = '密碼不正確';
}
$authed = !empty($_SESSION['dupan_school']);
function h($x) { return htmlspecialchars((string)$x, ENT_QUOTES); }
function n($x) { return number_format((int)$x); }

$school = $authed ? school_read() : ['courses' => []];
$teachers = [];
foreach ($school['courses'] as $c) {
    $tn = $c['teacher'] ?? '';
    if ($tn !== '' && !in_array($tn, $teachers, true)) $teachers[] = $tn;
}
$filter = $_GET['teacher'] ?? '';
$courses = array_values(array_filter($school['courses'],
    fn($c) => $filter === '' || ($c['teacher'] ?? '') === $filter));
$viewId = $_GET['view'] ?? '';
$vc = null;
foreach ($school['courses'] as $c) if ($c['id'] === $viewId) { $vc = $c; break; }
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>讀伴 ・ 學校後台</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&family=Noto+Serif+TC:wght@400;600;700;900&display=swap" rel="stylesheet">
<style>
:root{--bg:#EDEFE9;--paper:#FCFCFA;--paper-2:#F6F6F1;--ink:#24313B;--ink-soft:#64737D;--ink-faint:#9AA6AD;--line:#DBDED3;--vermilion:#A63A2B;--verm-wash:#F5E6E1;--verm-line:#DCB9B0;--jade-wash:#E9F0EC;--shadow:0 2px 10px rgba(36,49,59,.07);--serif:"Noto Serif TC",serif;--sans:"Noto Sans TC",sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);font-size:15px;line-height:1.7;min-height:100vh}
a{color:inherit}
.topbar{height:60px;display:flex;align-items:center;gap:14px;padding:0 22px;background:var(--paper);border-bottom:1px solid var(--line)}
.seal{width:38px;height:38px;background:var(--vermilion);color:#fff;font-family:var(--serif);font-size:13px;font-weight:700;line-height:1.15;display:flex;align-items:center;justify-content:center;text-align:center;border-radius:6px;transform:rotate(-3deg);box-shadow:inset 0 0 0 2px rgba(255,255,255,.35),0 1px 3px rgba(166,58,43,.35);letter-spacing:1px}
.topbar h1{font-family:var(--serif);font-size:19px;font-weight:700;letter-spacing:3px}
.topbar h1 em{font-style:normal;font-size:12px;font-weight:400;color:var(--ink-soft);letter-spacing:4px;margin-left:8px}
.beta-tag{display:inline-block;font-size:10.5px;color:var(--vermilion);border:1px solid var(--verm-line);background:var(--verm-wash);border-radius:20px;padding:1px 8px;letter-spacing:1px;font-weight:500}
.topbar .spacer{flex:1}
/* 讀伴標誌(與前台相同:書本圖示＋「讀伴」＋金色細線＋副標) */
.brand-logo{width:36px;height:34px;flex-shrink:0;display:block}
.brand-logo .bl-cover{fill:#7A2331}.brand-logo .bl-page{fill:#fff}
.brand-logo .bl-ink{fill:none;stroke:#B9822F;stroke-width:22;stroke-linecap:round;stroke-linejoin:round}
.topbar .brand-name{font-family:var(--serif);font-weight:900;font-size:23px;color:#7A2331;letter-spacing:2px;line-height:1;margin-left:-4px}
.brand-rule{width:1px;height:18px;background:#B9822F;flex:none}
.brand-tag{font-family:var(--serif);font-size:12px;color:#5E4A4A;letter-spacing:2px;white-space:nowrap}
.login-logo{width:56px;height:54px;margin:0 auto 14px}
.btn{border:1px solid var(--line);background:var(--paper);color:var(--ink);border-radius:8px;padding:6px 13px;font-size:13px;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block}
.btn:hover{border-color:var(--ink-soft);background:var(--paper-2)}
.btn.ghost{border-color:transparent;background:transparent;color:var(--ink-soft)}
.wrap{max-width:1020px;margin:0 auto;padding:24px 20px 60px}
.page-title{font-family:var(--serif);font-size:20px;font-weight:700;letter-spacing:2px;margin-bottom:2px}
.page-sub{font-size:12.5px;color:var(--ink-soft);margin-bottom:18px}
.crumb-line{font-size:12.5px;color:var(--ink-faint);margin-bottom:4px}
.filter-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.chip{padding:5px 14px;border-radius:20px;border:1px solid var(--line);background:var(--paper);font-size:12.5px;color:var(--ink-soft);text-decoration:none}
.chip:hover{border-color:var(--ink-soft);color:var(--ink)}
.chip.on{background:var(--ink);border-color:var(--ink);color:#fff}
.table-card{background:var(--paper);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);overflow:auto;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:640px}
th{font-family:var(--serif);font-weight:600;text-align:left;padding:10px 14px;border-bottom:1px solid var(--line);background:var(--paper-2);font-size:12.5px;letter-spacing:1px;white-space:nowrap}
td{padding:9px 14px;border-bottom:1px solid var(--paper-2);white-space:nowrap}
tr:last-child td{border-bottom:none}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
.tag{display:inline-block;font-size:11px;padding:1px 8px;border-radius:20px;background:var(--jade-wash);color:var(--ink-soft)}
.tag.self{background:var(--verm-wash);color:var(--vermilion)}
.empty-row{color:var(--ink-faint);text-align:center;padding:24px !important;white-space:normal}
.foot-note{font-size:11.5px;color:var(--ink-faint);margin-top:12px;line-height:1.9}
.login-box{max-width:380px;margin:9vh auto;background:var(--paper);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:34px 36px;text-align:center}
.login-box .seal{width:56px;height:56px;font-size:19px;margin:0 auto 14px}
.login-box h2{font-family:var(--serif);font-size:20px;letter-spacing:3px;margin-bottom:4px}
.login-box .sub{font-size:12.5px;color:var(--ink-soft);margin-bottom:22px}
.login-box input{width:100%;border:1px solid var(--line);border-radius:9px;background:var(--paper-2);padding:10px 12px;font-family:inherit;font-size:14px;outline:none;margin-bottom:12px;text-align:center}
.login-box button{width:100%;border:1px solid var(--ink);background:var(--ink);color:#fff;border-radius:9px;padding:10px;font-size:14px;cursor:pointer;font-family:inherit}
.login-err{color:var(--vermilion);font-size:12.5px;margin-bottom:10px}
.setup-note{background:#F1EEE2;border:1px solid var(--line);border-radius:10px;padding:12px 14px;font-size:12.5px;color:var(--ink-soft);text-align:left;margin-top:14px}
.setup-note code{background:var(--paper);border:1px solid var(--line);border-radius:4px;padding:1px 5px;font-size:12px}
</style>
</head>
<body>

<header class="topbar">
  <!-- 與前台相同的讀伴標誌 -->
  <svg class="brand-logo" viewBox="0 0 640 620" aria-hidden="true"><path class="bl-cover" d="M0,108 L32,92 L32,80 C120,20 250,0 338,42 C420,-5 520,0 597,30 L597,50 L630,62 L630,528 C540,520 420,535 338,605 C250,545 120,530 10,595 L0,592 Z"/><path class="bl-page" d="M52,78 C130,30 250,20 328,58 L328,520 C250,480 140,475 52,528 Z"/><path class="bl-page" d="M348,58 C420,15 520,15 580,40 L580,470 C500,450 410,460 348,515 Z"/><g class="bl-ink"><path d="M80,180 C150,140 240,135 310,165"/><path d="M80,255 C150,215 240,210 310,240"/><path d="M80,330 C150,290 240,285 310,315"/><path d="M160,385 C210,365 260,370 310,400"/><path d="M362,165 C395,138 420,140 440,165 C456,186 466,225 484,240"/><path d="M362,245 C398,220 445,215 484,240"/><path d="M362,325 C398,302 445,285 484,240"/><path d="M362,400 C392,374 412,368 430,345 C452,318 470,265 484,240"/><path d="M484,240 C512,246 535,246 556,252"/></g></svg>
  <h1 class="brand-name">讀伴</h1>
  <span class="brand-rule" aria-hidden="true"></span>
  <span class="brand-tag">學校後台</span>
  <span class="beta-tag">預留</span>
  <span class="spacer"></span>
  <?php if ($authed): ?>
    <a class="btn ghost" href="index.html">← 回前台</a>
    <a class="btn" href="school.php?logout=1">登出</a>
  <?php endif; ?>
</header>

<?php if (!$authed): ?>
<div class="login-box">
  <svg class="brand-logo login-logo" viewBox="0 0 640 620" aria-hidden="true"><path class="bl-cover" d="M0,108 L32,92 L32,80 C120,20 250,0 338,42 C420,-5 520,0 597,30 L597,50 L630,62 L630,528 C540,520 420,535 338,605 C250,545 120,530 10,595 L0,592 Z"/><path class="bl-page" d="M52,78 C130,30 250,20 328,58 L328,520 C250,480 140,475 52,528 Z"/><path class="bl-page" d="M348,58 C420,15 520,15 580,40 L580,470 C500,450 410,460 348,515 Z"/><g class="bl-ink"><path d="M80,180 C150,140 240,135 310,165"/><path d="M80,255 C150,215 240,210 310,240"/><path d="M80,330 C150,290 240,285 310,315"/><path d="M160,385 C210,365 260,370 310,400"/><path d="M362,165 C395,138 420,140 440,165 C456,186 466,225 484,240"/><path d="M362,245 C398,220 445,215 484,240"/><path d="M362,325 C398,302 445,285 484,240"/><path d="M362,400 C392,374 412,368 430,345 C452,318 470,265 484,240"/><path d="M484,240 C512,246 535,246 556,252"/></g></svg>
  <h2>學校後台</h2>
  <p class="sub">唯讀 ・ 課程與使用統計檢視</p>
  <?php if (!$hasPwd): ?>
    <div class="setup-note">學校後台目前關閉。日後開放其他學校申請時,會改為每校各自的管理者帳號,由該校管理自己的教師與學生。</div>
  <?php else: ?>
    <?php if ($err): ?><div class="login-err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <input type="password" name="pwd" placeholder="學校密碼" autofocus>
      <button type="submit">進入後台</button>
    </form>
  <?php endif; ?>
</div>

<?php elseif ($vc):
  $sts = $vc['students'];
  uasort($sts, fn($a, $b) => ($b['last'] ?? 0) - ($a['last'] ?? 0));
?>
<div class="wrap">
  <div class="crumb-line"><a href="school.php">課程列表</a> ›</div>
  <div class="page-title"><?= h($vc['name']) ?></div>
  <div class="page-sub">教師:<?= h($vc['teacher'] ?? '—') ?> ・ 學生 <?= count($sts) ?> 人 ・ 指定文本 <?= count($vc['texts']) ?> 篇(唯讀)</div>
  <div class="table-card">
    <table>
      <tr><th>學號</th><th>姓名</th><th class="num">對話輪數</th><th class="num">API 次數</th><th class="num">Tokens(入/出)</th><th>最後活動</th><th>加入方式</th></tr>
      <?php foreach ($sts as $sid => $st): ?>
      <tr>
        <td><?= h($sid) ?></td><td><?= h($st['name']) ?></td>
        <td class="num"><?= n($st['turns'] ?? 0) ?></td>
        <td class="num"><?= n($st['calls'] ?? 0) ?></td>
        <td class="num"><?= n($st['in'] ?? 0) ?> / <?= n($st['out'] ?? 0) ?></td>
        <td><?= ($st['last'] ?? 0) ? date('m/d H:i', $st['last']) : '—' ?></td>
        <td><span class="tag<?= !empty($st['self']) ? ' self' : '' ?>"><?= !empty($st['self']) ? '自行加入' : '名單匯入' ?></span></td>
      </tr>
      <?php endforeach; if (empty($sts)): ?>
      <tr><td colspan="7" class="empty-row">這門課還沒有學生。</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php else: ?>
<div class="wrap">
  <div class="page-title">課程與使用統計</div>
  <div class="page-sub">依教師檢視各課程規模與用量;本後台為唯讀,無法調整 Prompt 或管理課程。</div>
  <div class="filter-row">
    <a class="chip<?= $filter === '' ? ' on' : '' ?>" href="school.php">全部教師</a>
    <?php foreach ($teachers as $tn): ?>
      <a class="chip<?= $filter === $tn ? ' on' : '' ?>" href="school.php?teacher=<?= rawurlencode($tn) ?>"><?= h($tn) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="table-card">
    <table>
      <tr><th>課程</th><th>教師</th><th class="num">學生數</th><th class="num">曾使用</th><th class="num">對話輪數</th><th class="num">Tokens(入/出)</th><th class="num">估計花費</th><th>建立時間</th></tr>
      <?php
      $tIn = $tOut = $tTurns = $tStu = 0;
      foreach ($courses as $c):
        $sts = $c['students'];
        $act = count(array_filter($sts, fn($s2) => ($s2['last'] ?? 0) > 0));
        $turns = array_sum(array_map(fn($s2) => $s2['turns'] ?? 0, $sts));
        $cin = array_sum(array_map(fn($s2) => $s2['in'] ?? 0, $sts));
        $cout = array_sum(array_map(fn($s2) => $s2['out'] ?? 0, $sts));
        $tIn += $cin; $tOut += $cout; $tTurns += $turns; $tStu += count($sts);
      ?>
      <tr>
        <td><a href="school.php?view=<?= h($c['id']) ?>" style="text-decoration:underline"><?= h($c['name']) ?></a></td>
        <td><?= h($c['teacher'] ?? '—') ?></td>
        <td class="num"><?= count($sts) ?></td>
        <td class="num"><?= $act ?></td>
        <td class="num"><?= n($turns) ?></td>
        <td class="num"><?= n($cin) ?> / <?= n($cout) ?></td>
        <td class="num">$<?= number_format($cin / 1e6 * $priceIn + $cout / 1e6 * $priceOut, 3) ?></td>
        <td><?= date('Y/m/d', $c['createdAt'] ?? time()) ?></td>
      </tr>
      <?php endforeach; if (empty($courses)): ?>
      <tr><td colspan="8" class="empty-row">目前沒有符合的課程。</td></tr>
      <?php endif; ?>
      <?php if ($courses): ?>
      <tr style="font-weight:600;background:var(--paper-2)">
        <td>合計 <?= count($courses) ?> 門</td><td></td>
        <td class="num"><?= $tStu ?></td><td class="num">—</td>
        <td class="num"><?= n($tTurns) ?></td>
        <td class="num"><?= n($tIn) ?> / <?= n($tOut) ?></td>
        <td class="num">$<?= number_format($tIn / 1e6 * $priceIn + $tOut / 1e6 * $priceOut, 3) ?></td><td></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>
  <p class="foot-note">・課程 tokens 僅計入已加入課程的學生;點課程名稱可檢視學生明細(唯讀)。</p>
</div>
<?php endif; ?>
</body>
</html>
