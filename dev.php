<?php
/**
 * 讀伴 ・ 管理者後台
 * — System Prompt 直改(整站覆寫/主提示/人格/導讀/解析)
 * — 全站使用統計(瀏覽/API/tokens/花費/趨勢)
 * — 課程總覽(所有教師課程的規模與用量)
 * — 帳號管理(開發者與教師)、稽核紀錄
 * 登入:開發者帳號(email)。第一位開發者在主機上用 tools/create_account.php 建立。
 */
require_once __DIR__ . '/account_lib.php';
require_once __DIR__ . '/stats_lib.php';
require_once __DIR__ . '/school_lib.php';
require_once __DIR__ . '/prompt_lib.php';
require_once __DIR__ . '/guest_lib.php';
require_once __DIR__ . '/analysis_lib.php';
require_once __DIR__ . '/admin_ui.php';

$configured = file_exists(__DIR__ . '/config.php');
if ($configured) require_once __DIR__ . '/config.php';

$priceIn  = defined('PRICE_IN_PER_M')  ? PRICE_IN_PER_M  : 3.0;
$priceOut = defined('PRICE_OUT_PER_M') ? PRICE_OUT_PER_M : 15.0;
$model    = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : '(未設定)';

$AUTH   = auth_handle(['dev'], 'dev.php');
$me     = $AUTH['user'];
$authed = $AUTH['authed'];
$actor  = auth_actor($me);
$expired = $authed ? course_cleanup() : [];
function h($x) { return htmlspecialchars((string)$x, ENT_QUOTES); }
function n($x) { return number_format((int)$x); }

if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = $_POST['act'];
    /* ---------- 帳號管理 ---------- */
    if ($act === 'acc_create') {
        $role = ($_POST['role'] ?? '') === 'dev' ? 'dev' : 'teacher';
        $pwd  = acc_gen_password();
        $r = acc_create($_POST['login'] ?? '', $_POST['name'] ?? '', $role, $pwd, $actor, true);
        if (!empty($r['ok'])) {
            /* 初始密碼只顯示這一次(存在 session,重新整理就消失) */
            $_SESSION['acc_once'] = ['login' => acc_norm_login($_POST['login']), 'pwd' => $pwd, 'what' => '建立'];
            header('Location: dev.php?tab=accounts'); exit;
        }
        $_SESSION['acc_err'] = $r['error'];
        header('Location: dev.php?tab=accounts'); exit;
    }
    if ($act === 'acc_reset') {
        $a = acc_get($_POST['id'] ?? '');
        if ($a && $a['id'] === $me['id']) { $_SESSION['acc_err'] = '不能重設自己的密碼,請到「我的帳號」修改。'; header('Location: dev.php?tab=accounts'); exit; }
        if ($a) {
            $pwd = acc_gen_password();
            $r = acc_set_password($a['id'], $pwd, true, $actor);
            if (!empty($r['ok'])) $_SESSION['acc_once'] = ['login' => $a['login'], 'pwd' => $pwd, 'what' => '重設'];
            else $_SESSION['acc_err'] = $r['error'];
        }
        header('Location: dev.php?tab=accounts'); exit;
    }
    if ($act === 'acc_toggle') {
        $a = acc_get($_POST['id'] ?? '');
        if ($a) {
            if ($a['id'] === $me['id']) $_SESSION['acc_err'] = '不能停用自己的帳號';
            else { $r = acc_set_disabled($a['id'], empty($a['disabled']), $actor); if (!empty($r['error'])) $_SESSION['acc_err'] = $r['error']; }
        }
        header('Location: dev.php?tab=accounts'); exit;
    }
    /* ---------- Prompt:一次只存/還原一項,其他項(含整站覆寫 full)原樣保留 ---------- */
    if ($act === 'prompt_save_one' || $act === 'prompt_reset_one') {
        $key = (string)($_POST['key'] ?? '');
        $bi  = prompts_builtin_map();
        if (!isset($bi[$key])) { header('Location: dev.php?tab=prompts'); exit; }
        $norm = fn($x) => trim(str_replace("\r\n", "\n", (string)$x));
        $val  = $act === 'prompt_reset_one' ? '' : $norm($_POST['value'] ?? '');
        if ($val === $norm($bi[$key])) $val = '';          // 與內建相同 = 不覆寫
        $all = prompts_overrides();
        $all[$key] = $val;
        $ok = prompts_save($all);
        if ($ok) audit($actor, 'prompts.save', $key, ['result' => $val === '' ? 'builtin' : 'override']);
        header('Location: dev.php?tab=prompts&p=' . urlencode($key) . '&' . ($ok ? ($val === '' ? 'reset=1' : 'saved=1') : 'savefail=1')); exit;
    }
}

$stats  = $authed ? stats_read() : null;
$school = $authed ? school_read() : ['courses' => []];
$tab = $_GET['tab'] ?? 'usage';
if (!in_array($tab, ['usage', 'prompts', 'courses', 'guests', 'accounts', 'audit', 'me'], true)) $tab = 'usage';
$modeNames = [
    'chat' => '讀伴對話', 'briefing' => '閱讀導讀',
    'branch_summary' => '分支收束', 'doc_summary' => '閱讀回顧 / Word 摘要',
    'analysis' => '教師端文本解析',
    'lookup' => '簡要查詢',
];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>讀伴 ・ 管理者後台</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;700&family=Noto+Serif+TC:wght@400;600;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=20261020d">
<script src="assets/pwd-eye.js?v=20261107b" defer></script>
</head>
<body>

<?= ui_topbar('dev', $me, $authed, ['meUrl' => 'dev.php?tab=me', 'logoutUrl' => 'dev.php?logout=1']) ?>

<?php if (!$authed): ?>
<div class="login-box">
  <?= ui_logo('brand-logo login-logo') ?>
  <h2>管理者後台</h2>
  <p class="sub">System Prompt ・ 全站數據 ・ 課程總覽 ・ 帳號管理</p>
  <?php if (!acc_count_devs()): ?>
    <div class="setup-note">還沒有任何開發者帳號。請在主機上執行:<br><code>php tools/create_account.php dev 你的email "顯示名稱"</code></div>
  <?php else: ?>
    <?= auth_box_html($AUTH, '開發者帳號(email)') ?>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="layout">
  <aside class="sidebar">
    <nav aria-label="管理者後台">
    <?php
      /* 依常用程度排列;項目名稱統一四個字 */
      $SB = ['常用' => ['usage' => '使用統計', 'courses' => '課程總覽', 'accounts' => '帳號管理'],
             '紀錄' => ['guests' => '試用紀錄', 'audit' => '稽核紀錄'],
             '設定' => ['prompts' => '提示設定']];
      foreach ($SB as $grp => $items): ?>
      <div class="sb-group-title"><?= $grp ?></div>
      <?php foreach ($items as $k => $lbl): ?>
      <a class="sb-link<?= $tab === $k ? ' on' : '' ?>" href="dev.php?tab=<?= $k ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= ui_icon($k) ?><?= $lbl ?></a>
      <?php endforeach; endforeach; ?>
    </nav>
  </aside>

  <main class="main">
  <?php if ($AUTH['msg']): ?><div class="flash"><?= h($AUTH['msg']) ?></div><?php endif; ?>
  <?php if ($AUTH['err']): ?><div class="flash err"><?= h($AUTH['err']) ?></div><?php endif; ?>
  <?php if ($expired): ?><div class="flash">已自動刪除超過保留期限(<?= COURSE_KEEP_DAYS ?> 天)的課程:<?= h(implode('、', $expired)) ?></div><?php endif; ?>
  <?php if ($tab === 'accounts'):
      $once = $_SESSION['acc_once'] ?? null; unset($_SESSION['acc_once']);
      $aerr = $_SESSION['acc_err'] ?? '';   unset($_SESSION['acc_err']);
      $accs = acc_list();
      $ownCnt = [];
      foreach (school_read()['courses'] as $c) foreach (course_teacher_ids($c) as $tid) $ownCnt[$tid] = ($ownCnt[$tid] ?? 0) + 1;
  ?>
    <div class="page-title">帳號管理</div>
    <div class="page-sub">開發者與教師帳號。建立或重設後會產生一組初始密碼，對方第一次登入時必須修改。</div>
    <?php if ($aerr): ?><div class="flash err"><?= h($aerr) ?></div><?php endif; ?>
    <?php if ($once): ?>
      <div class="flash once-pwd">
        已<?= h($once['what']) ?>帳號 <b><?= h($once['login']) ?></b>。初始密碼：<code class="pwd"><?= h($once['pwd']) ?></code><br>
        <span style="font-size:12px">這組密碼<b>只顯示這一次</b>，離開或重新整理就看不到了。請私下交給對方，他第一次登入時會被要求改掉。</span>
      </div>
    <?php endif; ?>

    <div class="panel" style="max-width:820px">
      <span class="eyebrow">New account</span><h4>建立帳號</h4>
      <form method="post" class="fld-row">
        <input type="hidden" name="act" value="acc_create">
        <label class="fld" style="flex:0 1 130px"><span>角色</span>
          <select name="role"><option value="teacher">教師</option><option value="dev">開發者</option></select></label>
        <label class="fld wide"><span>帳號（Email）</span>
          <input type="text" name="login" placeholder="name@example.com" required></label>
        <label class="fld"><span>顯示名稱</span>
          <input type="text" name="name" placeholder="例如：王老師"></label>
        <button class="btn primary">建立並產生初始密碼</button>
      </form>
    </div>

    <section class="card tbl-card">
      <div class="tbl-wrap">
      <table>
        <tr><th>名稱／帳號</th><th>角色</th><th class="num">課程</th><th>狀態</th><th>最後登入</th><th>操作</th></tr>
        <?php foreach ($accs as $a): $self = $a['id'] === $me['id']; $off = !empty($a['disabled']); ?>
        <tr<?= $off ? ' class="dim"' : '' ?>>
          <td class="cell-2"><b><?= h($a['name'] ?: '—') ?><?= $self ? ' <span class="tag brand">我</span>' : '' ?></b><span><?= h($a['login']) ?></span></td>
          <td><?= h(ACC_ROLES[$a['role']] ?? $a['role']) ?></td>
          <td class="num"><?= (int)($ownCnt[$a['id']] ?? 0) ?></td>
          <td><?= $off ? '<span class="tag">已停用</span>' : (!empty($a['mustChange']) ? '<span class="tag warn">待首次登入</span>' : '<span class="tag ok">啟用中</span>') ?></td>
          <td><?= $a['lastLogin'] ? date('Y/m/d H:i', $a['lastLogin']) : '—' ?></td>
          <td style="white-space:nowrap">
            <?php if (!$self): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('重設這個帳號的密碼?對方要用新的初始密碼重新登入。')">
              <input type="hidden" name="act" value="acc_reset"><input type="hidden" name="id" value="<?= h($a['id']) ?>">
              <button class="btn-text">重設密碼</button></form>
            <form method="post" style="display:inline">
              <input type="hidden" name="act" value="acc_toggle"><input type="hidden" name="id" value="<?= h($a['id']) ?>">
              <button class="btn-text <?= $off ? 'ok' : 'danger' ?>"><?= $off ? '啟用' : '停用' ?></button></form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      </div>
    </section>

  <?php elseif ($tab === 'audit'): $logs = audit_tail(300);
      /* 顯示用對照:動作 => [中文名稱, 標籤顏色, 篩選分類]。只改顯示,不改紀錄內容;對照表沒有的動作原樣顯示、中性標籤 */
      $AL = [
        'login.ok' => ['登入', '', 'login'], 'login.fail' => ['登入失敗', 'warn', 'login'],
        'login.disabled' => ['停用帳號嘗試登入', 'warn', 'login'], 'logout' => ['登出', '', 'login'],
        'account.create' => ['建立帳號', 'ok', 'create'], 'student.create' => ['建立學生帳號', 'ok', 'create'],
        'account.reset_password' => ['重設密碼', '', 'account'], 'account.change_password' => ['更改密碼', '', 'account'],
        'account.disable' => ['停用帳號', '', 'account'], 'account.enable' => ['啟用帳號', '', 'account'],
        'student.password' => ['學生更改密碼', '', 'account'], 'student.pwreset' => ['重設學生密碼', '', 'account'],
        'course.create' => ['建立課程', 'info', 'course'], 'course.delete' => ['刪除課程', 'info', 'course'],
        'course.denied' => ['嘗試操作他人課程', 'warn', 'course'], 'course.teacher_add' => ['加入授課教師', 'info', 'course'],
        'course.teacher_remove' => ['移除授課教師', 'info', 'course'], 'course.expire' => ['課程到期自動刪除', 'info', 'course'],
        'course.extend' => ['延長課程期限', 'info', 'course'], 'course.rename' => ['修改課名', 'info', 'course'],
        'course.gone' => ['操作已不存在的課程（舊分頁）', 'info', 'course'],
        'prompts.save' => ['修改 Prompt', '', 'other'],
      ];
      /* 學生在前台自行註冊會各記一筆 student.create(操作者就是學生本人)。
         同一個 IP、彼此相隔 60 秒內、且距離該組第一筆不超過 10 分鐘的,合併成一列「N 位學生自行註冊」;
         只改顯示,紀錄檔本身不變。展開後每一筆仍列出時間與 IP。 */
      $rowsA = [];
      foreach ($logs as $l) {
          $last = $rowsA ? count($rowsA) - 1 : -1;
          if ($l['action'] === 'student.create' && $last >= 0 && $rowsA[$last]['action'] === 'student.create'
              && ($rowsA[$last]['items'][0]['ip'] ?? '') === ($l['ip'] ?? '')
              && abs($rowsA[$last]['tEnd'] - $l['t']) <= 60
              && abs($rowsA[$last]['items'][0]['t'] - $l['t']) <= 600) {
              $rowsA[$last]['items'][] = $l; $rowsA[$last]['tEnd'] = $l['t']; continue;
          }
          $rowsA[] = ['action' => $l['action'], 'tEnd' => $l['t'], 'items' => [$l]];
      }
  ?>
    <div class="page-title">稽核紀錄</div>
    <div class="page-sub">最近 <?= count($logs) ?> 筆紀錄<?= count($rowsA) !== count($logs) ? '（同來源連續註冊合併後為 ' . count($rowsA) . ' 列）' : '' ?>，最新在上。記錄登入、帳號管理、課程建立與刪除、Prompt 修改，以及開發者在他人課程中的修改。</div>
    <section class="card tbl-card">
      <div class="card-h" style="padding-bottom:12px"><span class="eyebrow">Audit</span><h2>操作紀錄</h2></div>
      <div class="filter-bar">
        <div class="seg" role="group" aria-label="依類型篩選" id="auFilter">
          <button type="button" class="on" data-f="">全部</button>
          <button type="button" data-f="login">登入</button>
          <button type="button" data-f="create">建立帳號</button>
          <button type="button" data-f="course">課程</button>
          <button type="button" data-f="dev">代為修改</button>
        </div>
        <input type="search" id="auSearch" placeholder="搜尋操作者或對象" aria-label="搜尋操作者或對象">
      </div>
      <div class="tbl-wrap">
      <table id="auTable">
        <tr><th>時間</th><th>操作者</th><th>動作</th><th>對象</th><th>IP</th></tr>
        <?php foreach ($rowsA as $g):
          $l = $g['items'][0]; $n = count($g['items']);
          if ($l['action'] === 'dev.edit') { $lbl = '代為' . ($l['detail']['label'] ?? '修改'); $tc = 'warn'; $cat = 'dev'; }
          else { [$lbl, $tc, $cat] = $AL[$l['action']] ?? [$l['action'], '', 'other'];
                 if (!empty($l['detail']['label'])) $lbl .= '：' . $l['detail']['label']; }
          $q = mb_strtolower(implode(' ', array_map(fn($x) => $x['actor'] . ' ' . $x['target'], $g['items'])));
          $ips = array_unique(array_column($g['items'], 'ip')); ?>
        <tr data-cat="<?= $cat ?>" data-q="<?= h($q) ?>">
          <td><?php
            /* 合併列顯示時間區間;跨日時後半段也帶日期 */
            if ($n > 1 && $g['tEnd'] !== $l['t'])
                echo date('m/d H:i:s', $g['tEnd']) . '–' . date(date('Ymd', $g['tEnd']) === date('Ymd', $l['t']) ? 'H:i:s' : 'm/d H:i:s', $l['t']);
            else echo date('m/d H:i:s', $l['t']); ?></td>
          <?php if ($n > 1): ?>
          <td>學生本人</td>
          <td><span class="tag ok"><?= $n ?> 位學生自行註冊</span></td>
          <td><details class="batch"><summary><?= $n ?> 個帳號</summary>
            <ul><?php foreach (array_reverse($g['items']) as $x): ?><li><span class="ip"><?= date('H:i:s', $x['t']) ?></span>　<?= h($x['target']) ?></li><?php endforeach; ?></ul></details></td>
          <td class="ip"><?= h(reset($ips)) ?></td>
          <?php else: ?>
          <td class="wrap" style="min-width:140px"><?= h($l['actor']) ?></td>
          <td><span class="tag <?= $tc ?>"><?= h($lbl) ?></span></td>
          <td class="wrap" style="min-width:160px"><?= h($l['target']) ?></td>
          <td class="ip"><?= h($l['ip']) ?></td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <tr id="auEmpty"<?= $logs ? ' hidden' : '' ?>><td colspan="5" class="empty-row"><?= $logs ? '沒有符合的紀錄' : '尚無紀錄' ?></td></tr>
      </table>
      </div>
    </section>
    <script>
    (function () {
      var f = '', q = '', rows = document.querySelectorAll('#auTable tr[data-cat]'), empty = document.getElementById('auEmpty');
      function apply() {
        var shown = 0;
        rows.forEach(function (r) {
          var ok = (!f || r.dataset.cat === f) && (!q || r.dataset.q.indexOf(q) !== -1);
          r.hidden = !ok; if (ok) shown++;
        });
        if (rows.length) empty.hidden = shown > 0;
      }
      document.querySelectorAll('#auFilter button').forEach(function (b) {
        b.onclick = function () {
          document.querySelectorAll('#auFilter button').forEach(function (x) { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); });
          f = b.dataset.f; apply();
        };
      });
      document.getElementById('auSearch').oninput = function () { q = this.value.trim().toLowerCase(); apply(); };
    })();
    </script>

  <?php elseif ($tab === 'me'): ?>
    <div class="page-title">我的帳號</div>
    <div class="page-sub"><?= h($me['name']) ?>・<?= h($me['login']) ?>・開發者</div>
    <div class="panel" style="max-width:480px"><span class="eyebrow">Password</span><h4>更改密碼</h4><?= auth_change_form_html() ?></div>

  <?php elseif ($tab === 'prompts'):
      $ovr   = prompts_overrides();
      $biMap = prompts_builtin_map();
      $items = [                                   // 顯示順序與名稱
        'guard'            => '主提示(防護層＋分析視角＋開場選項＋回應風格)',
        'persona_guide'    => '人格:引導者',
        'persona_critic'   => '人格:批判者',
        'persona_explorer' => '人格:探索者',
        'classics'         => '參考經典範例',
        'analysis'         => '文本解析(階段一,教師無法調整)',
        'briefing'         => '導讀生成',
        'note_gen'         => '閱讀筆記生成(閱讀回顧)',
        'summary_gen'      => 'AI 摘要生成(Word 匯出)',
      ];
      $pk  = isset($items[$_GET['p'] ?? '']) ? $_GET['p'] : 'guard';
      $isOvr = !empty($ovr[$pk]);
      $pjPath = __DIR__ . '/data/prompts.json';
      $pjOk = file_exists($pjPath) ? (is_file($pjPath) && is_writable($pjPath)) : is_writable(__DIR__ . '/data');
      $activeOvr = array_keys(array_filter($ovr));
  ?>
    <div class="page-title">提示設定<span class="aside">Prompt・全站生效，不分課程</span></div>
    <div class="page-sub">內建版本寫在程式 <code>prompts_design.php</code>（教授的設計版）。這裡的修改會存成「覆寫」，優先於程式，儲存後全站立即生效。</div>
    <?php if (isset($_GET['saved'])): ?><div class="flash">已儲存「<?= h($items[$pk]) ?>」的覆寫，全站立即生效（進行中的對話從下一則訊息起套用）。</div><?php endif; ?>
    <?php if (isset($_GET['reset'])): ?><div class="flash">「<?= h($items[$pk]) ?>」已還原為內建版本。</div><?php endif; ?>
    <?php if (isset($_GET['savefail'])): ?><div class="flash err"><b>儲存失敗</b>：伺服器無法寫入 <code>data/prompts.json</code>，設定並未生效。</div><?php endif; ?>

    <div class="status-line" role="status">
      <span><i class="dot <?= $pjOk ? 'ok' : 'bad' ?>" aria-hidden="true"></i>設定檔寫入權限：<?= $pjOk ? '正常' : '無法寫入' ?></span>
      <span><i class="dot <?= $activeOvr ? 'brand' : '' ?>" aria-hidden="true"></i>已覆寫的項目：<?= $activeOvr ? h(implode('、', array_map(fn($k) => $items[$k] ?? $k, $activeOvr))) : '無（全部使用內建）' ?></span>
    </div>

    <div class="panel">
      <span class="eyebrow">Editor</span><h4>檢視或修改</h4>
      <form method="get" class="fld-row" style="margin-bottom:12px;align-items:center">
        <input type="hidden" name="tab" value="prompts">
        <label for="pSel" style="font-size:13px;color:var(--text-muted)">項目</label>
        <select name="p" id="pSel" onchange="this.form.submit()" style="min-width:280px;flex:0 1 420px">
          <?php foreach ($items as $k => $label): ?>
            <option value="<?= h($k) ?>"<?= $k === $pk ? ' selected' : '' ?>><?= h($label) ?><?= !empty($ovr[$k]) ? '（已覆寫）' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?= $isOvr ? '<span class="tag brand">已覆寫</span>' : '<span class="tag">使用內建</span>' ?>
        <noscript><button class="btn small">切換</button></noscript>
      </form>

      <?php $designKeys = ['guard', 'persona_guide', 'persona_critic', 'persona_explorer', 'classics', 'analysis']; ?>
      <?php if (in_array($pk, $designKeys, true)): ?>
        <div class="notice"><?= ui_icon('warn') ?><div>
          這一項屬於<b>教授的設計版</b>（逐字寫在程式 <code>prompts_design.php</code>）。在這裡存的覆寫會蓋過程式，且<b>不會進入 git</b>，日後難以追溯與比對。
          除非是臨時試驗，建議改設計檔後重新產生 <code>prompts_design.php</code> 再部署。</div>
        </div>
      <?php endif; ?>

      <form method="post" id="pForm">
        <input type="hidden" name="act" value="prompt_save_one">
        <input type="hidden" name="key" value="<?= h($pk) ?>">
        <label class="fld"><span>內容</span>
        <textarea name="value" id="pText" class="p-editor" readonly><?= h($ovr[$pk] ?? $biMap[$pk]) ?></textarea></label>
      </form>
      <?php if ($isOvr): ?>
      <form method="post" id="pResetForm" onsubmit="return confirm('把「<?= h($items[$pk]) ?>」還原為程式內建的設計版?目前的覆寫內容會被捨棄。')">
        <input type="hidden" name="act" value="prompt_reset_one"><input type="hidden" name="key" value="<?= h($pk) ?>">
      </form>
      <?php endif; ?>
      <div class="p-actions">
        <span class="grow" id="pHint">目前為唯讀檢視；按「修改這一項」才能編輯，儲存只影響這一項。</span>
        <?php if ($isOvr): ?><button class="btn" form="pResetForm" id="pReset">還原為內建</button><?php endif; ?>
        <button type="button" class="btn" id="pEdit">修改這一項</button>
        <button type="button" class="btn" id="pCancel" style="display:none">取消修改</button>
        <button class="btn primary" form="pForm" id="pSave" style="display:none">儲存覆寫</button>
      </div>
      <script>
      (function () {
        var t = document.getElementById('pText'), orig = t.value;
        var edit = document.getElementById('pEdit'), save = document.getElementById('pSave'),
            cancel = document.getElementById('pCancel'), hint = document.getElementById('pHint');
        edit.onclick = function () {
          t.readOnly = false; t.focus();
          edit.style.display = 'none'; save.style.display = ''; cancel.style.display = '';
          hint.textContent = '編輯中。儲存後全站立即生效；內容若與內建完全相同，會自動視為「內建」。';
        };
        cancel.onclick = function () {
          t.value = orig; t.readOnly = true;
          edit.style.display = ''; save.style.display = 'none'; cancel.style.display = 'none';
          hint.textContent = '目前為唯讀檢視；按「修改這一項」才能編輯，儲存只影響這一項。';
        };
        /* 有改動但沒儲存就要離開(含切換選單)時提醒 */
        window.addEventListener('beforeunload', function (e) {
          if (!t.readOnly && t.value !== orig && !window.__pSubmitting) { e.preventDefault(); e.returnValue = ''; }
        });
        document.getElementById('pForm').addEventListener('submit', function () { window.__pSubmitting = true; });
      })();
      </script>
    </div>

    <?php
      /* 上方選的那一項,對應到哪一種實際送出的組合 */
      $pvMap = [
        'guard'            => ['chat', 'guide', '對話・引導者(主提示在三種人格都相同,這裡以引導者為例)'],
        'persona_guide'    => ['chat', 'guide', '對話・引導者'],
        'persona_critic'   => ['chat', 'critic', '對話・批判者'],
        'persona_explorer' => ['chat', 'explorer', '對話・探索者'],
        'classics'         => ['chat', 'guide', '對話・引導者(經典範例在三種人格都相同,這裡以引導者為例)'],
        'briefing'         => ['briefing', '', '導讀生成'],
        'note_gen'         => ['doc_summary', 'note', '閱讀筆記生成(閱讀回顧)'],
        'summary_gen'      => ['doc_summary', 'summary', 'AI 摘要生成(Word 匯出)'],
      ];
      $pv = $pvMap[$pk] ?? null;
    ?>
    <div class="panel">
      <h4>實際送出的組合 Prompt<?= $pv ? '<span style="font-weight:400;color:var(--text-muted);font-size:13px;margin-left:8px">' . h($pv[2]) . '</span>' : '' ?></h4>
      <?php if ($pv): ?>
        <p style="font-size:13px;color:var(--ink-soft)">送給模型的是上方各項的組合(對話為「主提示＋人格＋經典範例＋閱讀地圖＋文本」),以<b>目前已儲存</b>的版本、用假文本代入。正在修改但還沒儲存的內容不會出現在這裡。</p>
        <pre id="pvOut" data-mode="<?= h($pv[0]) ?>" data-sub="<?= h($pv[1]) ?>" style="white-space:pre-wrap;font-family:var(--sans);font-size:12px;line-height:1.85;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-top:6px;max-height:560px;overflow-y:auto">讀取中⋯</pre>
      <?php else: ?>
        <p style="font-size:13px;color:var(--ink-soft)">文本解析不經過組合:上方的全文就是送給模型的 system prompt,後面只會再接上「【文本】《標題》」與教師上傳的全文。</p>
      <?php endif; ?>
    </div>
    <script>
    (async function () {
      var out = document.getElementById('pvOut');
      if (!out) return;
      var mode = out.dataset.mode, sub = out.dataset.sub;
      var body = { dry_run: 1, mode: mode, docTitle: '(預覽用標題)', docText: '(預覽用文本內容)',
                   messages: [{ role: 'user', content: '預覽' }] };
      if (mode === 'chat') body.persona = sub;
      if (mode === 'doc_summary') body.kind = sub;
      try {
        var r = await fetch('api.php', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        var d = await r.json();
        if (d.error) { out.textContent = '錯誤:' + d.error; return; }
        out.textContent = '共 ' + d.chars + ' 字 ・ 已覆寫:' + (d.overrides.join('、') || '無') + '\n\n' + d.system;
      } catch (e) { out.textContent = '預覽失敗:' + e.message; }
    })();
    </script>

  <?php elseif ($tab === 'guests' && ($_GET['g'] ?? '') !== ''):
      /* ================= 免費試用:單一紀錄 ================= */
      require_once __DIR__ . '/review_lib.php';
      $g = guest_get((string)$_GET['g']);
      $pName = ['guide' => '引導者', 'critic' => '批判者', 'explorer' => '探索者'];
  ?>
    <div class="crumb-line"><a href="dev.php?tab=guests">免費試用紀錄</a> ›</div>
    <?php if (!$g): ?>
      <div class="page-title">找不到這筆紀錄</div><div class="page-sub">可能已超過保留期限(<?= intdiv(GUEST_KEEP_SECS, 86400) ?> 天)而刪除。</div>
    <?php else: $gc = stats_cost($g['tok'], $priceIn, $priceOut); $ga = !empty($g['textKey']) ? ga_get($g['textKey']) : null; ?>
    <div class="page-title">《<?= h($g['title'] ?? '(無標題)') ?>》</div>
    <div class="page-sub">
      訪客 <code><?= h($g['gid']) ?></code> ・ 來源 <a class="mono" href="dev.php?tab=guests&ip=<?= h($g['ip']) ?>"><?= h(substr($g['ip'], 0, 8)) ?></a>
      ・ <?= date('Y/m/d H:i', $g['created']) ?> – <?= date('m/d H:i', $g['updated']) ?>
      ・ <?= n($g['chars'] ?? 0) ?> 字 ・ 學生回應 <?= guest_chat_count($g) ?> 則 ・ 分支 <?= isset($g['branches']) ? n($g['branches']) : '—' ?> ・ 筆記泡泡 <?= isset($g['notes']) ? n($g['notes']) : '—' ?> ・ 其他呼叫 <?= guest_other_count($g) ?> 次
      ・ tokens 入 <?= n($g['tok']['in'] + $g['tok']['cw'] + $g['tok']['cr']) ?> / 出 <?= n($g['tok']['out']) ?> ・ 估計 $<?= number_format($gc, 3) ?>
      <?php if (!empty($g['dropped'])): ?> ・ <span style="color:var(--danger)">事件超過上限,另有 <?= n($g['dropped']) ?> 筆未保存內容</span><?php endif; ?>
      <br><span style="color:var(--text-faint);font-size:12px"><?= h($g['ua'] ?? '') ?></span>
    </div>

    <details class="builtin-view" style="margin-bottom:14px"><summary>上傳的文本(<?= n($g['chars'] ?? 0) ?> 字,點開檢視)</summary><pre><?= h($g['text'] ?? '') ?></pre></details>
    <details class="builtin-view" style="margin-bottom:18px"><summary>自動解析(閱讀地圖):<?= !$ga ? '沒有' : h(['done' => '已完成', 'running' => '解析中', 'error' => '失敗:' . ($ga['error'] ?? '')][$ga['status'] ?? ''] ?? ($ga['status'] ?? '')) ?><?= $ga && isset($ga['out']) ? '(tokens 入 ' . n($ga['in']) . ' / 出 ' . n($ga['out']) . ')' : '' ?></summary>
      <?php if ($ga && ($ga['status'] ?? '') === 'done'): ?><div class="analysis-body" style="margin-top:6px"><?= sr_md_html($ga['md'] ?? '') ?></div><?php endif; ?>
    </details>

    <div class="section-title"><span class="eyebrow">Log</span>使用過程（依時間）</div>
    <div class="table-card">
      <table>
        <tr><th>時間</th><th>類型</th><th>內容</th><th class="num">Tokens(入/出)</th><th class="num">費用</th></tr>
        <?php foreach ($g['events'] as $e):
          $ec = stats_cost($e, $priceIn, $priceOut);
          $isChat = in_array($e['mode'], ['chat', 'chat_open'], true);
          $label = ['analysis' => '文本解析', 'chat_open' => '讀伴開場'][$e['mode']] ?? ($modeNames[$e['mode']] ?? $e['mode']); ?>
        <tr style="vertical-align:top">
          <td><?= date('m/d H:i:s', $e['ts']) ?></td>
          <td><span class="tag<?= $isChat ? '' : ' info' ?>"><?= h($label) ?></span>
            <?php if (!empty($e['persona'])): ?><div style="font-size:11px;color:var(--ink-faint);margin-top:3px"><?= h($pName[$e['persona']] ?? $e['persona']) ?><?= !empty($e['branch']) ? '・分支' : '' ?></div><?php endif; ?></td>
          <td style="white-space:normal;max-width:620px;font-size:12.5px;line-height:1.8">
            <?php if ($e['mode'] === 'analysis'): ?>
              <?= h(['done' => '完成', 'error' => '失敗', 'limited' => '超過每日解析上限,被擋下'][$e['status'] ?? ''] ?? '') ?>
            <?php else: ?>
              <?php if (!empty($e['branch'])): ?><div style="color:var(--ink-faint)">分支起點:「<?= h($e['branch']) ?>」</div><?php endif; ?>
              <?php if (($e['q'] ?? '') !== ''): ?><div><b>讀者:</b><?= nl2br(h($e['q'])) ?></div><?php endif; ?>
              <?php if (($e['a'] ?? '') !== ''): ?>
                <?php if ($isChat): ?><div style="color:var(--ink-soft);margin-top:4px"><b>讀伴:</b><?= nl2br(h($e['a'])) ?></div>
                <?php else: ?><details class="builtin-view"><summary>檢視回覆</summary><pre><?= h($e['a']) ?></pre></details><?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="num"><?= n($e['in'] + $e['cw'] + $e['cr']) ?> / <?= n($e['out']) ?></td>
          <td class="num">$<?= number_format($ec, 4) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  <?php elseif ($tab === 'guests'):
      /* ================= 免費試用:列表 ================= */
      $all = guest_list();
      $ipF = preg_replace('/[^a-f0-9]/', '', (string)($_GET['ip'] ?? ''));
      $rows = $ipF !== '' ? array_filter($all, fn($r) => ($r['ip'] ?? '') === $ipF) : $all;
      $cost1 = fn($r) => stats_cost($r['tok'] ?? [], $priceIn, $priceOut);
      $sumOf = function ($since) use ($all, $cost1) {
          $a = ['n' => 0, 'chat' => 0, 'cost' => 0.0, 'ips' => []];
          foreach ($all as $r) if (($r['updated'] ?? 0) >= $since) {
              $a['n']++; $a['chat'] += guest_chat_count($r); $a['cost'] += $cost1($r); $a['ips'][$r['ip'] ?? ''] = 1;
          }
          return $a;
      };
      $t0 = strtotime('today');
      $sums = ['今日' => $sumOf($t0), '近 7 天' => $sumOf($t0 - 6 * 86400), '近 ' . intdiv(GUEST_KEEP_SECS, 86400) . ' 天' => $sumOf(0)];
      $byIp = [];
      foreach ($all as $r) {
          $k = $r['ip'] ?? '';
          $byIp[$k] = $byIp[$k] ?? ['n' => 0, 'chat' => 0, 'ana' => 0, 'cost' => 0.0, 'last' => 0];
          $byIp[$k]['n']++; $byIp[$k]['chat'] += guest_chat_count($r); $byIp[$k]['ana'] += (int)($r['counts']['analysis'] ?? 0);
          $byIp[$k]['cost'] += $cost1($r); $byIp[$k]['last'] = max($byIp[$k]['last'], $r['updated'] ?? 0);
      }
      uasort($byIp, fn($a, $b) => $b['cost'] <=> $a['cost']);
  ?>
    <div class="page-title">免費試用紀錄</div>
    <div class="page-sub">未綁課程、自行上傳文本的訪客，每人每篇一列，保留 <?= intdiv(GUEST_KEEP_SECS, 86400) ?> 天後自動刪除。<br>
      「來源」是 IP 的雜湊（不存原始 IP），點來源可篩選同一來源的紀錄。</div>
    <div class="cards stat-cards">
      <?php $ki = 0; foreach ($sums as $lbl => $a): $isKey = ++$ki === count($sums); ?>
      <div class="card<?= $isKey ? ' key' : '' ?>">
        <div class="k"><?= h($lbl) ?><?= $isKey ? '<span class="k-note">保留期內全部</span>' : '' ?></div>
        <div class="v">$<?= number_format($a['cost'], 2) ?><small>USD</small></div>
        <dl class="mini">
          <div><dt>篇數</dt><dd><?= n($a['n']) ?></dd></div>
          <div><dt>學生回應</dt><dd><?= n($a['chat']) ?></dd></div>
          <div><dt>來源</dt><dd><?= count($a['ips']) ?></dd></div>
        </dl>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($ipF === '' && $byIp):
      /* 花費最高的來源:排名、花費＋占全部試用花費的比例,一眼看出誰用得最多 */
      $allCost = array_sum(array_column($byIp, 'cost')); ?>
    <section class="card tbl-card tbl-compact">
      <div class="card-h"><span class="eyebrow">Sources</span><h2>花費最高的來源</h2><span class="aside">前 10 名・點來源可只看它的紀錄</span></div>
      <div class="tbl-wrap">
      <table class="g-src">
        <tr><th class="num">#</th><th>來源</th><th>花費</th><th class="num">篇數</th><th class="num">學生回應</th><th class="num">解析</th><th>最後活動</th></tr>
        <?php $rk = 0; foreach (array_slice($byIp, 0, 10, true) as $ip => $v): $sh = $allCost > 0 ? $v['cost'] / $allCost : 0; ?>
        <tr><td class="num rank"><?= ++$rk ?></td>
          <td><a class="mono" href="dev.php?tab=guests&ip=<?= h($ip) ?>"><?= h(substr($ip, 0, 8)) ?></a></td>
          <td><div class="meter cost-meter"><b>$<?= number_format($v['cost'], 3) ?></b><span class="track"><span class="fill" style="width:<?= round($sh * 100, 1) ?>%"></span></span><small><?= round($sh * 100) ?>%</small></div></td>
          <td class="num"><?= n($v['n']) ?></td><td class="num"><?= n($v['chat']) ?></td><td class="num"><?= n($v['ana']) ?></td>
          <td><?= date('m/d H:i', $v['last']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      </div>
    </section>
    <?php endif; ?>

    <section class="card tbl-card tbl-compact">
      <div class="card-h"><span class="eyebrow">Sessions</span><h2><?= $ipF !== '' ? '來源 <span class="mono">' . h(substr($ipF, 0, 8)) . '</span> 的紀錄' : '所有紀錄' ?></h2>
        <span class="aside">共 <?= count($rows) ?> 篇・最新活動在上・點文本看完整過程</span>
        <?php if ($ipF !== ''): ?><a class="aside" href="dev.php?tab=guests" style="color:var(--brand)">顯示全部</a><?php endif; ?></div>
      <div class="tbl-wrap">
      <?php /* 11 欄併成 5 欄:每格大字是重點,小字是補充細節 */ ?>
      <table class="g-sess">
        <tr><th>文本</th><th>時間</th><th class="num">對話</th><th class="num">花費</th><th>來源</th></tr>
        <?php foreach ($rows as $id => $r): $tk = $r['tok'] ?? []; ?>
        <tr>
          <td class="g-main"><a href="dev.php?tab=guests&g=<?= h(urlencode($id)) ?>">《<?= h($r['title'] ?? '(無標題)') ?>》</a>
            <small><?= n($r['chars'] ?? 0) ?> 字</small></td>
          <td class="g-sub"><b><?= date('m/d H:i', $r['updated']) ?></b>
            <small>開始 <?= date('m/d H:i', $r['created']) ?></small></td>
          <td class="num g-sub"><b>學生回應 <?= guest_chat_count($r) ?></b>
            <small>分支 <?= isset($r['branches']) ? n($r['branches']) : '—' ?>・筆記 <?= isset($r['notes']) ? n($r['notes']) : '—' ?>・其他呼叫 <?= guest_other_count($r) ?></small></td>
          <td class="num g-sub"><b>$<?= number_format($cost1($r), 3) ?></b>
            <small>Tokens <?= n(($tk['in'] ?? 0) + ($tk['cw'] ?? 0) + ($tk['cr'] ?? 0)) ?> / <?= n($tk['out'] ?? 0) ?></small></td>
          <td><a class="mono" href="dev.php?tab=guests&ip=<?= h($r['ip'] ?? '') ?>"><?= h(substr($r['ip'] ?? '', 0, 8)) ?></a></td>
        </tr>
        <?php endforeach; if (!$rows): ?>
        <tr><td colspan="5" class="empty-row">目前沒有免費試用紀錄。</td></tr>
        <?php endif; ?>
      </table>
      </div>
    </section>
    <details class="calc-note"><summary>計算方式與資料來源</summary>
      <ul class="foot-note">
        <li>「學生回應」是使用者送出的訊息數，不含讀伴的開場與回覆；「其他呼叫」包含讀伴開場、導讀、分支收束、閱讀回顧與文本解析。</li>
        <li>分支與筆記泡泡是使用者最後一次呼叫讀伴時，瀏覽器回報的數量（不論來源）；之後才加的筆記泡泡不會算進去。</li>
        <li>文本解析依全文指紋共用：多人上傳同一篇只解析一次，費用算在第一次觸發的那一列。</li>
        <li>花費以 <?= h($model) ?> 定價估計（快取寫入 1.25 倍、快取讀取 0.1 倍）。本功能上線前的試用沒有紀錄。</li>
      </ul>
    </details>
  <?php elseif ($tab === 'courses' && ($_GET['view'] ?? '') !== ''):
      $vc = null;
      foreach ($school['courses'] as $c0) if ($c0['id'] === $_GET['view']) { $vc = $c0; break; }
  ?>
    <div class="crumb-line"><a href="dev.php?tab=courses">課程總覽</a> ›</div>
    <div class="page-title"><?= h($vc['name'] ?? '找不到課程') ?></div>
    <?php if ($vc): $sts = $vc['students']; uasort($sts, fn($a2, $b2) => ($b2['last'] ?? 0) - ($a2['last'] ?? 0)); ?>
    <div class="page-sub">授課教師：<?= h(implode('、', array_map(function ($id) { $x = acc_get($id); return $x ? $x['name'] . (!empty($x['disabled']) ? '(已停用)' : '') : '(已移除)'; }, course_teacher_ids($vc))) ?: ($vc['teacher'] ?? '—')) ?>・邀請碼 <span class="code-chip"><?= h($vc['code']) ?></span>・學生 <?= count($sts) ?> 人・指定文本 <?= count($vc['texts']) ?> 篇（唯讀檢視，管理請至教師後台）</div>
    <div class="table-card">
      <table>
        <tr><th>學號</th><th>姓名</th><th class="num">學生回應</th><th class="num">分支</th><th class="num">筆記泡泡</th><th class="num">API 呼叫</th><th class="num">Tokens（入／出）</th><th>最後活動</th><th>加入方式</th></tr>
        <?php $TC = tr_summary($vc['id'])['bySid']; foreach ($sts as $sid => $st): $sc = $TC[$sid] ?? ['resp' => 0, 'branches' => 0, 'notes' => 0]; ?>
        <tr>
          <td><?= h($sid) ?></td><td><?= h($st['name']) ?></td>
          <td class="num"><?= n($sc['resp']) ?></td>
          <td class="num"><?= n($sc['branches']) ?></td>
          <td class="num"><?= n($sc['notes']) ?></td>
          <td class="num"><?= n($st['calls'] ?? 0) ?></td>
          <td class="num"><?= n($st['in'] ?? 0) ?> / <?= n($st['out'] ?? 0) ?></td>
          <td><?= ($st['last'] ?? 0) ? date('m/d H:i', $st['last']) : '—' ?></td>
          <td><span class="tag<?= !empty($st['self']) ? ' info' : '' ?>"><?= !empty($st['self']) ? '自行加入' : '名單匯入' ?></span></td>
        </tr>
        <?php endforeach; if (empty($sts)): ?>
        <tr><td colspan="9" class="empty-row">這門課還沒有學生。</td></tr>
        <?php endif; ?>
      </table>
    </div>
    <?php endif; ?>
  <?php elseif ($tab === 'courses'):
      $accNames = []; foreach (acc_list() as $a0) $accNames[$a0['id']] = $a0['name'] . (!empty($a0['disabled']) ? '(已停用)' : '');
      $allStu = array_sum(array_map(fn($c0) => count($c0['students']), $school['courses']));
  ?>
    <div class="page-title">課程總覽</div>
    <div class="page-sub">共 <?= count($school['courses']) ?> 門課程・<?= $allStu ?> 位學生。管理操作（建課、名單、邀請碼）在教師後台進行。</div>
    <section class="card tbl-card tbl-compact courses-tbl">
      <div class="tbl-wrap">
      <table>
        <tr><th>課程</th><th>授課教師</th><th>邀請碼</th><th class="num">學生</th><th>使用率</th><th class="num">學生回應</th><th class="num">分支／筆記</th><th class="num">Tokens（入／出）</th><th class="num">花費</th><th>建立</th><th>保留至</th></tr>
        <?php
        $tIn = $tOut = $tTurns = $tStu = $tBr = $tNt = $tAct = 0;
        foreach ($school['courses'] as $c):
          $sts = $c['students'];
          $act2 = count(array_filter($sts, fn($s2) => ($s2['last'] ?? 0) > 0));
          $cs = tr_summary($c['id'])['bySid'];
          $turns = array_sum(array_column($cs, 'resp'));
          $cBr = array_sum(array_column($cs, 'branches')); $cNt = array_sum(array_column($cs, 'notes'));
          $tBr += $cBr; $tNt += $cNt; $tAct += $act2;
          $cin = array_sum(array_map(fn($s2) => $s2['in'] ?? 0, $sts));
          $cout = array_sum(array_map(fn($s2) => $s2['out'] ?? 0, $sts));
          $cost2 = stats_cost(['in' => $cin, 'out' => $cout], $priceIn, $priceOut);   // 學生活動紀錄沒有分快取,以未快取價估(偏高)
          $tIn += $cin; $tOut += $cout; $tTurns += $turns; $tStu += count($sts);
          $rate = count($sts) ? $act2 / count($sts) : 0;
        ?>
        <tr>
          <td class="wrap" style="min-width:160px"><a href="dev.php?tab=courses&view=<?= h($c['id']) ?>" style="color:var(--brand)"><?= h($c['name']) ?></a></td>
          <td class="wrap" style="min-width:100px"><?= h(implode('、', array_map(fn($id) => $accNames[$id] ?? '(已移除)', course_teacher_ids($c))) ?: ($c['teacher'] ?? '—')) ?></td>
          <td><span class="code-chip"><?= h($c['code']) ?></span></td>
          <td class="num"><?= count($sts) ?></td>
          <td><div class="meter" title="曾使用 <?= $act2 ?> 人／學生 <?= count($sts) ?> 人"><span class="track"><span class="fill" style="width:<?= round($rate * 100) ?>%"></span></span><small><?= $act2 ?>/<?= count($sts) ?></small></div></td>
          <td class="num"><?= n($turns) ?></td>
          <td class="num"><?= n($cBr) ?> / <?= n($cNt) ?></td>
          <td class="num"><?= n($cin) ?> / <?= n($cout) ?></td>
          <td class="num">$<?= number_format($cost2, 3) ?></td>
          <?php $ex = course_expires_at($c); ?>
          <td><?= !empty($c['createdAt']) ? date('Y/m/d', $c['createdAt']) : '—' ?></td>
          <td<?= $ex !== null && $ex - time() < COURSE_WARN_DAYS * 86400 ? ' style="color:var(--danger)"' : '' ?>><?= $ex === null ? '—（不清理）' : date('Y/m/d', $ex) ?></td>
        </tr>
        <?php endforeach; if (empty($school['courses'])): ?>
        <tr><td colspan="11" class="empty-row">還沒有任何課程，教師可於教師後台建立。</td></tr>
        <?php endif; ?>
        <?php if ($school['courses']): ?>
        <tr class="total">
          <td>合計 <?= count($school['courses']) ?> 門</td><td></td><td></td>
          <td class="num"><?= $tStu ?></td>
          <td><div class="meter"><span class="track"><span class="fill" style="width:<?= $tStu ? round($tAct / $tStu * 100) : 0 ?>%"></span></span><small><?= $tAct ?>/<?= $tStu ?></small></div></td>
          <td class="num"><?= n($tTurns) ?></td>
          <td class="num"><?= n($tBr) ?> / <?= n($tNt) ?></td>
          <td class="num"><?= n($tIn) ?> / <?= n($tOut) ?></td>
          <td class="num">$<?= number_format($tIn / 1e6 * $priceIn + $tOut / 1e6 * $priceOut, 3) ?></td><td></td><td></td>
        </tr>
        <?php endif; ?>
      </table>
      </div>
    </section>
    <ul class="foot-note">
      <li>「使用率」是曾使用的學生占全班的比例（曾使用／學生數）。</li>
      <li>「學生回應」是學生送出的訊息數，不含讀伴的開場與回覆；分支與筆記是學生目前閱讀紀錄裡的數量（筆記不論來源）。</li>
      <li>Tokens 與花費僅計入「已加入課程」學生的用量；免費測試的用量請看「免費試用紀錄」。</li>
    </ul>
  <?php else:
    $cur = null; /* usage 區塊沿用共用變數名 */
    /* ================= 全站使用統計 ================= */
    $s = $stats;
    $cost = stats_cost($s['api'], $priceIn, $priceOut);
    $cachedShare = ($s['api']['in'] + ($s['api']['cw'] ?? 0) + ($s['api']['cr'] ?? 0)) > 0
        ? ($s['api']['cr'] ?? 0) / ($s['api']['in'] + ($s['api']['cw'] ?? 0) + ($s['api']['cr'] ?? 0)) : 0;
    $today = date('Y-m-d');
    $todayD = $s['daily'][$today] ?? ['calls' => 0, 'in' => 0, 'out' => 0, 'views' => 0];
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $days[$d] = $s['daily'][$d] ?? ['calls' => 0, 'in' => 0, 'out' => 0, 'views' => 0];
    }
    $maxBar = max(1, max(array_map(fn($x) => max($x['calls'], $x['views']), $days)));
  ?>
    <div class="page-title">使用統計</div>
    <div class="page-sub">全站彙總・自 <?= h($s['since'] ?? '—') ?> 起累計</div>

    <?php /* 由左到右:瀏覽 → 呼叫 → 用量 → 花費;每張卡同一結構:標題、大數字、今日 */ ?>
    <div class="cards stat-cards">
      <div class="card"><div class="k">網站瀏覽</div><div class="v"><?= n($s['views']) ?><small>次</small></div><div class="sub2">今日 <b><?= n($todayD['views']) ?></b> 次</div></div>
      <div class="card"><div class="k">API 呼叫</div><div class="v"><?= n($s['api']['calls']) ?><small>次</small></div><div class="sub2">今日 <b><?= n($todayD['calls']) ?></b> 次</div></div>
      <div class="card"><div class="k">Tokens 用量</div>
        <?php /* 輸入、輸出上下排列,數字再大也不會互相擠到 */ ?>
        <div class="v-rows">
          <div><small>輸入</small><span class="v"><?= n($s['api']['in']) ?></span></div>
          <div><small>輸出</small><span class="v"><?= n($s['api']['out']) ?></span></div>
        </div>
        <div class="sub2">今日 輸入 <b><?= n($todayD['in']) ?></b>・輸出 <b><?= n($todayD['out']) ?></b></div></div>
      <div class="card key"><div class="k">估計花費</div><div class="v">$<?= number_format($cost, 2) ?><small>USD</small></div><div class="sub2">約 <b>NT$<?= number_format($cost * 31.5) ?></b>（匯率 31.5）</div></div>
    </div>

    <?php
      /* 趨勢圖:y 軸取「好讀」的上限,只標 0、中間值、最大值三個刻度 */
      $top = $maxBar;
      if ($top > 5) { $mag = pow(10, floor(log10($top))); $step = $mag / 2; $top = ceil($top / $step) * $step; }
      $fmtTick = fn($v) => floor($v) == $v ? number_format($v) : number_format($v, 1);
      $colW = 48; $padL = 44; $plotT = 14; $plotB = 160; $plotH = $plotB - $plotT;
      $svgW = $padL + count($days) * $colW + 8;
      $bar = function ($x, $v) use ($top, $plotB, $plotH) {
          $h = $v > 0 ? max(2, $v / $top * $plotH) : 0;
          if ($h <= 0) return '';
          $y = $plotB - $h; $r = min(4, $h, 5); $w = 10;
          return "M$x,$plotB V" . ($y + $r) . " Q$x,$y " . ($x + $r) . ",$y H" . ($x + $w - $r) . " Q" . ($x + $w) . ",$y " . ($x + $w) . "," . ($y + $r) . " V$plotB Z";
      };
    ?>
    <section class="card chart-card">
      <div class="card-h"><span class="eyebrow">Trend</span><h2>近 14 天趨勢</h2><span class="grow"></span>
        <div class="legend" id="trendLegend"><span><i style="background:var(--chart-1)"></i>頁面瀏覽</span><span><i style="background:var(--chart-2)"></i>API 呼叫</span></div>
        <button type="button" class="btn small" id="trendToggle" aria-pressed="false">以表格檢視</button>
      </div>
      <div class="trend-body">
        <div class="trend-scroll" id="trendChart">
          <svg class="trend" width="<?= $svgW ?>" height="186" viewBox="0 0 <?= $svgW ?> 186" role="img" aria-label="近 14 天頁面瀏覽與 API 呼叫長條圖">
            <?php foreach ([0, $top / 2, $top] as $tv): $ty = $plotB - $tv / $top * $plotH; ?>
              <line class="grid" x1="<?= $padL ?>" x2="<?= $svgW - 4 ?>" y1="<?= $ty + .5 ?>" y2="<?= $ty + .5 ?>"></line>
              <text class="axis-t" x="<?= $padL - 8 ?>" y="<?= $ty + 4 ?>" text-anchor="end"><?= $fmtTick($tv) ?></text>
            <?php endforeach; ?>
            <?php $x = $padL; foreach ($days as $d => $v): $cx = $x + $colW / 2; ?>
              <g class="col" tabindex="0" data-d="<?= h(substr($d, 5)) ?>" data-v="<?= (int)$v['views'] ?>" data-c="<?= (int)$v['calls'] ?>"
                 aria-label="<?= h(substr($d, 5)) ?>：頁面瀏覽 <?= (int)$v['views'] ?>，API 呼叫 <?= (int)$v['calls'] ?>">
                <rect class="hit" x="<?= $x + 2 ?>" y="<?= $plotT - 6 ?>" width="<?= $colW - 4 ?>" height="<?= $plotH + 6 ?>" rx="6"></rect>
                <path d="<?= $bar($cx - 11, $v['views']) ?>" fill="var(--chart-1)"></path>
                <path d="<?= $bar($cx + 1, $v['calls']) ?>" fill="var(--chart-2)"></path>
                <text class="day-t" x="<?= $cx ?>" y="<?= $plotB + 18 ?>" text-anchor="middle"><?= substr($d, 5) ?></text>
              </g>
            <?php $x += $colW; endforeach; ?>
          </svg>
          <div class="trend-tip" id="trendTip" hidden></div>
        </div>
        <?php
          /* 表格檢視:日期橫排、兩列指標,每格依「該列最大值」上色(越深越多),0 留白;最後一欄是 14 天合計 */
          $wk = ['日', '一', '二', '三', '四', '五', '六'];
          $rowsT = [['views', '頁面瀏覽', [158, 59, 69]], ['calls', 'API 呼叫', [185, 130, 47]]];
          $heat = function ($val, $max, $rgb) {
              if ($val <= 0) return '<td class="hm-cell zero">0</td>';
              $a = round(0.10 + 0.75 * $val / max(1, $max), 2);
              return '<td class="hm-cell' . ($a > 0.5 ? ' dark' : '') . '" style="background:rgba(' . implode(',', $rgb) . ',' . $a . ')">' . number_format($val) . '</td>';
          };
        ?>
        <div class="tbl-wrap trend-table" id="trendTable" hidden>
          <table class="heatmap">
            <tr><th></th>
              <?php foreach ($days as $d => $v): $isToday = $d === $today; $w = (int)date('w', strtotime($d)); ?>
              <th class="hm-day<?= $isToday ? ' today' : '' ?><?= $w === 0 || $w === 6 ? ' weekend' : '' ?>"><?= date('m/d', strtotime($d)) ?><span><?= $isToday ? '今天' : $wk[$w] ?></span></th>
              <?php endforeach; ?>
              <th class="hm-sum">合計</th></tr>
            <?php foreach ($rowsT as [$key, $label, $rgb]): $colMax = max(array_column($days, $key)); ?>
            <tr><th class="hm-label"><i class="tt-dot" style="background:rgb(<?= implode(',', $rgb) ?>)"></i><?= $label ?></th>
              <?php foreach ($days as $d => $v) echo $heat((int)$v[$key], $colMax, $rgb); ?>
              <td class="hm-sum"><?= n(array_sum(array_column($days, $key))) ?></td></tr>
            <?php endforeach; ?>
          </table>
          <p class="hm-note">顏色越深代表當天越多（兩列各自比較）；0 表示當天沒有。</p>
        </div>
      </div>
    </section>
    <script>
    (function () {
      var box = document.getElementById('trendChart'), tip = document.getElementById('trendTip');
      function show(g) {
        tip.innerHTML = '<b>' + g.dataset.d + '</b><br>頁面瀏覽 ' + g.dataset.v + '<br>API 呼叫 ' + g.dataset.c;
        /* 提示框以視窗為基準定位(不會被圖表的捲動區裁掉),放在該欄右側、圖表區內;右邊放不下就放左側 */
        tip.hidden = false;
        var r = g.getBoundingClientRect(), tw = tip.offsetWidth;
        var x = r.right + 6;
        if (x + tw > window.innerWidth - 8) x = r.left - tw - 6;
        var y = r.top + 10;
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
      }
      box.querySelectorAll('.col').forEach(function (g) {
        g.addEventListener('mouseenter', function () { show(g); });
        g.addEventListener('focus', function () { show(g); });
        g.addEventListener('mouseleave', function () { tip.hidden = true; });
        g.addEventListener('blur', function () { tip.hidden = true; });
      });
      var btn = document.getElementById('trendToggle'), tbl = document.getElementById('trendTable');
      btn.onclick = function () {
        var toTable = tbl.hidden;
        tbl.hidden = !toTable; box.hidden = toTable; document.getElementById('trendLegend').hidden = toTable;
        btn.textContent = toTable ? '以圖表檢視' : '以表格檢視';
        btn.setAttribute('aria-pressed', toTable);
      };
    })();
    </script>

    <?php
      $au = $s['by_audience'] ?? [];
      $auRows = [
        'course' => ['課程學生', '以邀請碼登入，閱讀教師指定文本'],
        'guest'  => ['自由試用訪客', '自行上傳文本，未綁課程'],
      ];
      $bm = $s['by_mode']; uasort($bm, fn($a, $b) => $b['calls'] - $a['calls']);
      $modeCost = array_map(fn($v) => stats_cost($v, $priceIn, $priceOut), $bm);
      $modeTotal = array_sum($modeCost);
    ?>
    <div class="grid-2">
      <section class="card tbl-card">
        <div class="card-h"><span class="eyebrow">Audience</span><h2>使用者組成</h2></div>
        <div class="tbl-wrap">
        <table>
          <tr><th>身分</th><th class="num">API 呼叫</th><th class="num">輸入 Tokens</th><th class="num">輸出 Tokens</th><th class="num">花費</th></tr>
          <?php foreach ($auRows as $k => [$label, $desc]):
            $v = $au[$k] ?? ['calls' => 0, 'in' => 0, 'out' => 0];
            $ac = stats_cost($v, $priceIn, $priceOut); ?>
          <tr>
            <td><span class="tag<?= $k === 'guest' ? ' brand' : ' info' ?>"><?= $label ?></span><div style="font-size:12px;color:var(--text-muted);margin-top:4px;white-space:normal"><?= $desc ?></div></td>
            <td class="num"><?= n($v['calls']) ?></td>
            <td class="num"><?= n($v['in']) ?></td>
            <td class="num"><?= n($v['out']) ?></td>
            <td class="num">$<?= number_format($ac, 3) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        </div>
      </section>

      <section class="card tbl-card">
        <div class="card-h"><span class="eyebrow">Breakdown</span><h2>各功能用量</h2></div>
        <div class="tbl-wrap">
        <table>
          <tr><th>功能</th><th class="num">呼叫</th><th class="num">輸入 Tokens</th><th class="num">輸出 Tokens</th><th class="num">花費</th><th>占總花費</th></tr>
          <?php foreach ($bm as $mode => $v): $mc = $modeCost[$mode]; $sh = $modeTotal > 0 ? $mc / $modeTotal : 0; ?>
          <tr><td><?= h($modeNames[$mode] ?? $mode) ?></td>
              <td class="num"><?= n($v['calls']) ?></td>
              <td class="num"><?= n($v['in']) ?></td><td class="num"><?= n($v['out']) ?></td>
              <td class="num">$<?= number_format($mc, 3) ?></td>
              <td><div class="meter"><span class="track"><span class="fill" style="width:<?= round($sh * 100, 1) ?>%"></span></span><small><?= round($sh * 100) ?>%</small></div></td></tr>
          <?php endforeach; if (empty($bm)): ?>
          <tr><td colspan="6" class="empty-row">還沒有任何 API 呼叫紀錄，前台開始使用後就會有數字。</td></tr>
          <?php endif; ?>
        </table>
        </div>
      </section>
    </div>

    <details class="calc-note"><summary>計算方式與資料來源</summary>
      <ul class="foot-note">
        <li>花費為估計值：以 <?= h($model) ?> 定價 $<?= $priceIn ?>／百萬輸入、$<?= $priceOut ?>／百萬輸出 tokens 計算（可在 config.php 以 <code>PRICE_IN_PER_M</code>、<code>PRICE_OUT_PER_M</code> 覆寫）。
          對話的 system prompt 有啟用 prompt caching：寫入快取以 1.25 倍、讀取以 0.1 倍輸入價計算，目前輸入 token 有 <?= round($cachedShare * 100) ?>% 是讀快取。實際帳單以 Anthropic Console 為準。</li>
        <li>Token 數取自 API 回應的 usage 欄位；瀏覽次數為前台載入次數。統計存於 <code>data/stats.json</code>，每日明細保留 90 天。</li>
      </ul>
    </details>

  <?php endif; ?>
  </main>
</div>
<?php endif; ?>
</body>
</html>