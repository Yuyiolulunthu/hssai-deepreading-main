<?php
/**
 * 讀伴 — 教師端「學習分析」
 *   以教師審定的閱讀地圖為參照,分析學生的實際對話,產出形成性回饋(不是評分)。
 *   ・單一學生單篇：sr_run_student()
 *   ・整門課單篇的全班綜覽：sr_run_class()
 *   結果存於 data/review/<courseId>/,與逐字稿分離,重跑會覆蓋。
 */
require_once __DIR__ . '/prompt_lib.php';
require_once __DIR__ . '/school_lib.php';
require_once __DIR__ . '/analysis_lib.php';

const SR_MAX_MAP   = 12000;   // 餵給模型的地圖上限(字元)
const SR_MAX_TR    = 12000;   // 單份逐字稿上限
const SR_CLASS_TR  = 6000;    // 全班綜覽時每人的逐字稿上限
const SR_CLASS_MAX = 12;      // 全班綜覽最多納入幾位學生

function sr_safe($s) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string)$s); }
function sr_dir($cid) { return __DIR__ . '/data/review/' . sr_safe($cid); }
function sr_path($cid, $docId, $sid) {
    return sr_dir($cid) . '/' . sr_safe($docId) . '__' . preg_replace('/[^A-Za-z0-9_\-]/u', '_', (string)$sid) . '.json';
}
function sr_class_path($cid, $textId) { return sr_dir($cid) . '/' . sr_safe($textId) . '__CLASS.json'; }

function sr_get($path) {
    if (!is_file($path)) return null;
    $d = json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : null;
}
function sr_put($path, $rec) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    json_save($path, $rec, JSON_UNESCAPED_UNICODE);
}

/** 課程文本清單中,標題與逐字稿相符的那一篇(逐字稿以標題對應文本) */
function sr_text_by_title($course, $title) {
    foreach (school_norm_texts($course['texts'] ?? []) as $tx) {
        if ($tx['title'] === $title) return $tx;
    }
    return null;
}

/** 教師審定(或 AI 生成)的閱讀地圖;沒有就回空字串 */
function sr_map($cid, $textId) {
    $ar = $textId ? ta_get($cid, $textId) : null;
    if (!$ar || ($ar['status'] ?? '') !== 'done' || trim((string)($ar['md'] ?? '')) === '') return '';
    return u_cut($ar['md'], SR_MAX_MAP);
}

/** 分段共讀的範圍名稱:「第 5 段」或「第 1–12 段」;沒有範圍(舊資料的全文主幹)回空字串 */
function sr_seg_label($sg) {
    if (!is_array($sg)) return '';
    $a = (int)($sg['start'] ?? 0) + 1; $b = (int)($sg['end'] ?? 0) + 1;
    return $a === $b ? "第 {$a} 段" : "第 {$a}–{$b} 段";
}

/** 文本的章節(與前台目錄相同:以「# 」標題分節,至少兩個標題才算;標題前有內文時補「開頭」):[[起點段落, 標題], …] */
function sr_heads($text) {
    $text = (string)$text;
    $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $text)), 'strlen'));
    if (count($paras) < 2) $paras = array_values(array_filter(array_map('trim', preg_split('/\n/u', $text)), 'strlen'));
    $hs = [];
    foreach ($paras as $i => $p) if (preg_match('/^#{1,6}\s+(.*)$/su', $p, $m)) $hs[] = [$i, trim($m[1])];
    if (count($hs) < 2) return [];
    if ($hs[0][0] > 0) array_unshift($hs, [0, '開頭']);
    return $hs;
}

/** 範圍的章節名稱:「〈導論〉」或「〈導論〉至〈第二章〉」;沒有章節回空字串 */
function sr_seg_secs($sg, $heads) {
    if (!is_array($sg) || !$heads) return '';
    $at = function ($p) use ($heads) { $x = null; foreach ($heads as $h) { if ($h[0] > $p) break; $x = $h; } return $x; };
    $nm = fn($h) => '〈' . (mb_strlen($h[1]) > 12 ? mb_substr($h[1], 0, 12) . '…' : $h[1]) . '〉';
    $a = $at((int)($sg['start'] ?? 0)); $b = $at((int)($sg['end'] ?? 0));
    return !$a ? '' : ($a === $b ? $nm($a) : $nm($a) . '至' . $nm($b));
}

/** 分支的狀態:收束到別段時寫出是哪一段 */
function sr_branch_status($nd) {
    $st = $nd['status'] ?? '';
    if ($st === 'merged') return !empty($nd['mergedSeg']) ? '已收束到' . $nd['mergedSeg'] : '已收束回主幹';
    return $st === 'kept' ? '獨立保留' : '討論中';
}

/** 主幹依段落順序排列,各自帶著底下的分支(和對話樹相同的上下順序,父分支在前):[[主幹, [分支…]], …];
 *  找不到所屬主幹的分支放在最後一組(主幹為 null) */
function sr_trunk_groups($nodes) {
    $nodes = is_array($nodes) ? $nodes : [];
    $byId = [];
    foreach ($nodes as $k => $nd) if (is_array($nd)) $byId[(string)($nd['id'] ?? $k)] = $nd;
    $trunks = array_values(array_filter($byId, fn($nd) => ($nd['type'] ?? '') === 'trunk'));
    usort($trunks, fn($a, $b) => ($a['seg']['start'] ?? -1) <=> ($b['seg']['start'] ?? -1));
    $kids = [];
    foreach ($byId as $id => $nd) if (($nd['type'] ?? '') === 'branch') $kids[(string)($nd['parentId'] ?? '')][] = $id;
    foreach ($kids as &$ks) sort($ks, SORT_STRING);
    unset($ks);
    $seen = [];
    $walk = function ($id) use (&$walk, &$seen, $kids, $byId) {
        $res = [];
        foreach ($kids[$id] ?? [] as $cid) {
            if (isset($seen[$cid])) continue;
            $seen[$cid] = true;
            $res[] = $byId[$cid];
            array_push($res, ...$walk($cid));
        }
        return $res;
    };
    $groups = [];
    foreach ($trunks as $tk) $groups[] = [$tk, $walk((string)($tk['id'] ?? ''))];
    $orphans = array_values(array_filter($byId, fn($nd, $id) => ($nd['type'] ?? '') === 'branch' && !isset($seen[$id]), ARRAY_FILTER_USE_BOTH));
    if ($orphans) $groups[] = [null, $orphans];
    return $groups;
}

/** 對話樹 → 純文字逐字稿(每一段的主幹 → 它的分支 → 筆記/畫線) */
function sr_transcript_text($tr, $limit = SR_MAX_TR) {
    $persona = ['guide' => '引導者', 'critic' => '批判者', 'explorer' => '探索者'];
    $nodes = $tr['nodes'] ?? [];
    $out = [];
    $dump = function ($nd) use ($persona, &$out) {
        foreach (($nd['messages'] ?? []) as $m) {
            $role = $m['role'] ?? '';
            if ($role === 'merge') { $out[] = '【分支收束' . (!empty($m['fromSeg']) ? '（來自' . $m['fromSeg'] . '）' : '') . '】' . ($m['content'] ?? ''); continue; }
            $who = $role === 'user' ? '【學生】'
                 : '【讀伴' . (isset($m['persona'], $persona[$m['persona']]) ? '・' . $persona[$m['persona']] : '') . '】';
            $q = !empty($m['quote']) ? '(引用原文「' . $m['quote'] . '」)' : '';
            $out[] = $who . $q . ($m['content'] ?? '');
        }
    };
    foreach (sr_trunk_groups($nodes) as [$tk, $brs]) {   // 分段共讀:每一段讀完的範圍一條主幹,分支跟在所屬的段落後面
        $lb = $tk ? sr_seg_label($tk['seg'] ?? null) : '';
        if ($tk) {
            $out[] = $lb !== '' ? "===== 主幹(這次對話的範圍:{$lb}) =====" : '===== 主幹 =====';
            $dump($tk);
        }
        foreach ($brs as $nd) {
            $st = sr_branch_status($nd);
            $out[] = '===== 分支「' . ($nd['title'] ?? '') . '」(' . ($lb !== '' ? "屬於{$lb};" : '') . $st . ';起點:「' . ($nd['quote'] ?? '') . '」) =====';
            $dump($nd);
        }
    }
    // 筆記存在 content(舊版誤讀 text,筆記從未進入分析);個人註記是學生自己的想法,一併納入
    foreach (($tr['notes'] ?? []) as $n) {
        if (!is_array($n)) { $out[] = '【學生筆記】' . $n; continue; }
        $out[] = '【學生筆記】' . (string)($n['content'] ?? $n['text'] ?? '')
               . (trim((string)($n['comment'] ?? '')) !== '' ? "\n【學生對這則筆記的個人註記】" . $n['comment'] : '');
    }
    foreach (sr_hl_marks($tr) as $g) $out[] = '【學生螢光筆】' . (is_array($g) ? ($g['text'] ?? '') : $g);
    return u_cut(implode("\n\n", $out), $limit);
}

/** 讀本標記中「僅螢光筆」的部分(筆記葉、主幹引用、節外生枝的標記另有筆記與對話可看) */
function sr_hl_marks($tr) {
    return array_values(array_filter($tr['highlights'] ?? [], fn($g) => !is_array($g) || ($g['kind'] ?? 'hl') === 'hl'));
}

/** 逐字稿裡學生實際說了幾則(用來擋「還沒開始就分析」) */
function sr_student_turns($tr) { return tr_count_one($tr)['resp']; }

/** 單份逐字稿的客觀數字(不經模型,直接數) */
function sr_tr_stats($tr) {
    $turns = 0; $chars = 0; $quotes = 0; $branch = 0; $merged = 0; $persona = []; $ai = 0;
    foreach (($tr['nodes'] ?? []) as $nd) {
        if (($nd['type'] ?? '') === 'branch') {
            $branch++;
            if (($nd['status'] ?? '') === 'merged') $merged++;
        }
        foreach (($nd['messages'] ?? []) as $m) {
            $role = $m['role'] ?? '';
            if ($role === 'user') {
                $turns++;
                $chars += function_exists('mb_strlen') ? mb_strlen((string)($m['content'] ?? '')) : strlen((string)($m['content'] ?? ''));
                if (!empty($m['quote'])) $quotes++;
            } elseif ($role === 'ai' || $role === 'assistant') {
                $ai++;
                if (!empty($m['persona'])) $persona[$m['persona']] = true;
            }
        }
    }
    return ['turns' => $turns, 'ai' => $ai, 'chars' => $chars, 'avg' => $turns ? (int)round($chars / $turns) : 0,
            'quotes' => $quotes, 'branch' => $branch, 'merged' => $merged,
            'persona' => array_keys($persona),
            'notes' => count($tr['notes'] ?? []), 'marks' => count(sr_hl_marks($tr))];
}

/** 中位數 */
function sr_median(array $xs) {
    if (!$xs) return 0;
    sort($xs);
    $n = count($xs); $h = intdiv($n, 2);
    return $n % 2 ? $xs[$h] : (int)round(($xs[$h - 1] + $xs[$h]) / 2);
}

/** 全班的客觀統計 */
function sr_class_stats(array $trs) {
    $t = array_map('sr_tr_stats', $trs);
    $turns = array_column($t, 'turns');
    $agg = ['students' => count($t), 'turns_sum' => array_sum($turns),
            'turns_avg' => count($t) ? (int)round(array_sum($turns) / count($t)) : 0,
            'turns_med' => sr_median($turns),
            'turns_min' => $turns ? min($turns) : 0, 'turns_max' => $turns ? max($turns) : 0,
            'avg_chars' => count($t) ? (int)round(array_sum(array_column($t, 'chars')) / max(1, array_sum($turns))) : 0,
            'ai_sum' => array_sum(array_column($t, 'ai')),
            'quotes' => array_sum(array_column($t, 'quotes')),
            'branch_users' => count(array_filter($t, fn($x) => $x['branch'] > 0)),
            'branch_sum' => array_sum(array_column($t, 'branch')),
            'merged_sum' => array_sum(array_column($t, 'merged')),
            'notes' => array_sum(array_column($t, 'notes')), 'marks' => array_sum(array_column($t, 'marks')),
            'persona' => []];
    foreach (['guide' => '引導者', 'critic' => '批判者', 'explorer' => '探索者'] as $k => $label) {
        $agg['persona'][$label] = count(array_filter($t, fn($x) => in_array($k, $x['persona'], true)));
    }
    return $agg;
}

const SR_SYS_STUDENT = <<<'P'
你是人文社會領域的閱讀教學顧問,正在協助一位大學教師了解某位學生的閱讀歷程。
你會拿到：這篇文本的專家解析(閱讀地圖,教師審定版)、以及這位學生與 AI 讀伴的完整對話逐字稿。

立場：這是**形成性回饋,不是評分**。不要給分數、等第或排名。
目的是讓教師知道「這個學生現在站在哪裡、下一步可以怎麼帶」。

重要原則：
- 地圖是「合理解讀空間」的參照,不是標準答案。學生走到地圖沒有列出、但文本站得住的讀法,
  要特別指認出來並給予肯定——那通常比命中地圖重點更有價值。
- 所有判斷都要有依據：引用學生自己說過的話(用「」),並指出是在主幹還是哪一條分支。
- 誠實。學生停在字面複述就說停在字面複述,不要粉飾;但措辭對事不對人。

請用繁體中文輸出 Markdown,依下列五節,總長約 600–900 字：

## 一、抵達的理解層次
判定為下列何者並說明理由(引用學生的話)：
字面複述 ／ 能詮釋意義並說出理由 ／ 觸及結構、預設或意識形態層次

## 二、觸及了地圖的哪些重點
條列碰到的(各附學生的話),再條列**明顯沒有碰到、但值得帶到**的重點。

## 三、學生自己提出的洞見
地圖上沒有、但文本站得住的讀法。沒有就寫「這次沒有出現」,不要硬湊。

## 四、卡住或誤讀的地方
指出具體位置(主幹第幾輪／哪條分支)與可能的原因。沒有就說明。

## 五、給教師的下一步建議
兩到三個具體、可以直接拿去用的提問或引導方向,不是泛泛的教學原則。
P;

const SR_SYS_CLASS = <<<'P'
你是人文社會領域的閱讀教學顧問,正在協助一位大學教師掌握**全班**在某一篇文本上的閱讀狀況。
你會拿到：這篇文本的專家解析(閱讀地圖,教師審定版),以及班上多位學生與 AI 讀伴的對話逐字稿。

立場：形成性回饋,不是評分。不要給分數或排名,也不要逐一評論每位學生的優劣。
重點是**班級層次的樣態**：多數人走到哪裡、卡在哪裡、集體漏掉了什麼。

**輸出格式：先一個 JSON 區塊,再接 Markdown 散文。**

第一部分用 ```json 包起來,依下列結構：
```json
{"levels": {"學生姓名": 1, "...": 2},
 "coverage": [{"point": "地圖重點名稱(20 字內)", "hit": 3}],
 "issues": [{"issue": "共同卡點(20 字內)", "n": 2}]}
```
- `levels`：**每一位**學生一個層次判定——1=字面復述、2=能詮釋意義並說出理由、3=觸及結構、預設或意識形態層次。
  姓名用逐字稿上給的名字,不要漏掉任何一位。
- `coverage`：這篇解析的主要重點（五到八項）,`hit` 是碰到該項的**學生人數**。
- `issues`：出現在兩位以上學生身上的共同卡點（最多五項）,`n` 是人數。

數字要與逐字稿一致,不確定就寫小不寫大。

第二部分用繁體中文輸出 Markdown,依下列三節,總長約 500–700 字（層次分布已經在 JSON 裡,散文不要再重複）：

## 一、多數人沒有碰到的地圖重點
最值得下一堂課補的三到五項,並說明為什麼學生容易錯過它。

## 二、共同的誤讀或卡點
出現在兩位以上學生身上的模式,附上具代表性的學生原話(可匿名引用)。
只出現一次的個別狀況不要放在這裡。

## 三、下一堂課的建議
針對上述漏洞,給兩到三個可以直接使用的課堂提問或討論設計。
P;

/** 單一學生單篇:產生並儲存學習分析 */
function sr_run_student($cid, $course, $tr, $by = '') {
    $tx    = sr_text_by_title($course, $tr['title'] ?? '');
    $map   = sr_map($cid, $tx['id'] ?? '');
    $body  = $tx['text'] ?? '';
    $user  = "【文本】《" . ($tr['title'] ?? '') . "》\n\n"
           . ($body !== '' ? "【原文】\n" . u_cut($body, 20000) . "\n\n" : '')
           . ($map !== '' ? "【專家解析(閱讀地圖,教師審定)】\n{$map}\n\n" : "【專家解析】(這篇尚未有審定的解析,請只依原文判斷)\n\n")
           . "【學生：" . ($tr['name'] ?: $tr['sid']) . "】與讀伴的完整對話\n" . sr_transcript_text($tr);
    $r = dupan_llm(SR_SYS_STUDENT, $user, 3000);
    if (trim((string)($r['text'] ?? '')) === '') return ['ok' => false, 'error' => $r['error'] ?? '分析失敗'];
    $rec = ['md' => trim($r['text']), 'ts' => time(), 'sid' => $tr['sid'], 'name' => $tr['name'] ?? '',
            'title' => $tr['title'] ?? '', 'turns' => sr_student_turns($tr), 'hasMap' => $map !== '', 'by' => $by];
    sr_put(sr_path($cid, $tr['docId'] ?? '', $tr['sid']), $rec);
    return ['ok' => true, 'rec' => $rec];
}

/** 整門課單篇:以全部學生的逐字稿產生全班綜覽 */
function sr_run_class($cid, $course, $tx, $by = '') {
    $map   = sr_map($cid, $tx['id']);
    $parts = []; $trs = [];
    $n = 0;
    foreach (transcripts_for($cid) as $tr) {
        if (($tr['title'] ?? '') !== $tx['title']) continue;
        if (sr_student_turns($tr) < 1) continue;
        $parts[] = "===== 學生 " . ($tr['name'] ?: $tr['sid']) . " =====\n" . sr_transcript_text($tr, SR_CLASS_TR);
        $trs[] = $tr;
        if (++$n >= SR_CLASS_MAX) break;
    }
    if (!$n) return ['ok' => false, 'error' => '這篇還沒有任何學生回應。'];
    $user = "【文本】《{$tx['title']}》\n\n"
          . ($map !== '' ? "【專家解析(閱讀地圖,教師審定)】\n{$map}\n\n" : "【專家解析】(尚未有審定的解析)\n\n")
          . "【全班共 {$n} 位學生的對話】\n" . implode("\n\n", $parts);
    $r = dupan_llm(SR_SYS_CLASS, $user, 3000);
    if (trim((string)($r['text'] ?? '')) === '') return ['ok' => false, 'error' => $r['error'] ?? '分析失敗'];
    /* 抽出前面的 JSON 區塊,剩下的當散文 */
    $txt = trim($r['text']); $j = null;
    if (preg_match('/```json\s*(\{.*?\})\s*```/us', $txt, $m)) {
        $j = json_decode($m[1], true);
        $txt = trim(str_replace($m[0], '', $txt));
    } elseif (preg_match('/\A\s*(\{.*?\})\s*(?=#)/us', $txt, $m)) {
        $j = json_decode($m[1], true);
        $txt = trim(substr($txt, strlen($m[0])));
    }
    $rec = ['md' => $txt, 'ts' => time(), 'students' => $n, 'title' => $tx['title'], 'hasMap' => $map !== '', 'by' => $by,
            'stats'    => sr_class_stats($trs),
            'levels'   => is_array($j['levels'] ?? null)   ? $j['levels']   : [],
            'coverage' => is_array($j['coverage'] ?? null) ? $j['coverage'] : [],
            'issues'   => is_array($j['issues'] ?? null)   ? $j['issues']   : []];
    sr_put(sr_class_path($cid, $tx['id']), $rec);
    return ['ok' => true, 'rec' => $rec];
}

/** 極簡 Markdown → HTML(只處理分析輸出會用到的標題、清單、粗體、段落) */
function sr_md_html($md) {
    $out = []; $inUl = false;
    /* 注意:不可用 /\R/ 切行——沒有 u 修飾子時 \R 會match位元組 0x85,
       那是中文 UTF-8 字元的後續位元組,會把字切成兩半 */
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$md)) as $ln) {
        $t = rtrim($ln);
        $esc = fn($s) => preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $t, $m)) {
            if ($inUl) { $out[] = '</ul>'; $inUl = false; }
            $lv = min(6, strlen($m[1]) + 2);
            $out[] = "<h{$lv} class=\"sr-h\">" . $esc($m[2]) . "</h{$lv}>";
        } elseif (preg_match('/^\s*[-*]\s+(.*)$/u', $t, $m)) {
            if (!$inUl) { $out[] = '<ul class="sr-ul">'; $inUl = true; }
            $out[] = '<li>' . $esc($m[1]) . '</li>';
        } elseif (trim($t) === '') {
            if ($inUl) { $out[] = '</ul>'; $inUl = false; }
        } else {
            if ($inUl) { $out[] = '</ul>'; $inUl = false; }
            $out[] = '<p class="sr-p">' . $esc($t) . '</p>';
        }
    }
    if ($inUl) $out[] = '</ul>';
    return implode("\n", $out);
}

/** 全班綜覽的統計表格(HTML) */
function sr_class_tables($rec) {
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $st = $rec['stats'] ?? [];
    $out = [];

    /* 一、參與概況 */
    if ($st) {
        $p = $st['persona'] ?? [];
        $rows = [
            ['有對話紀錄的學生', ($st['students'] ?? 0) . ' 人'],
            ['學生回應', '共 ' . ($st['turns_sum'] ?? 0) . ' 則　每人平均 ' . ($st['turns_avg'] ?? 0)
                . '　中位數 ' . ($st['turns_med'] ?? 0) . '　範圍 ' . ($st['turns_min'] ?? 0) . '–' . ($st['turns_max'] ?? 0)],
            ['每則回應平均字數', ($st['avg_chars'] ?? 0) . ' 字'],
            ['引用原文發問', ($st['quotes'] ?? 0) . ' 次'],
            ['開過分支的學生', ($st['branch_users'] ?? 0) . ' / ' . ($st['students'] ?? 0)
                . ' 人　共 ' . ($st['branch_sum'] ?? 0) . ' 條(已收束 ' . ($st['merged_sum'] ?? 0) . ' 條)'],
            ['用過的對話模式', implode('　', array_map(fn($k, $v) => "{$k} {$v} 人", array_keys($p), array_values($p)))],
            ['筆記泡泡 / 螢光筆', ($st['notes'] ?? 0) . ' 則 / ' . ($st['marks'] ?? 0) . ' 處'],
        ];
        $tb = '';
        foreach ($rows as $r) $tb .= '<tr><th>' . $h($r[0]) . '</th><td>' . $h($r[1]) . '</td></tr>';
        $out[] = '<div class="sr-tw"><table class="sr-t sr-t2"><caption>參與概況</caption>' . $tb . '</table></div>';
    }

    /* 二、理解層次分布 */
    $lv = $rec['levels'] ?? [];
    if ($lv) {
        $label = [1 => '字面復述', 2 => '能詮釋並說出理由', 3 => '觸及結構或預設'];
        $cnt = [1 => 0, 2 => 0, 3 => 0];
        foreach ($lv as $v) { $v = (int)$v; if (isset($cnt[$v])) $cnt[$v]++; }
        $tot = max(1, array_sum($cnt));
        $tb = '<tr><th>理解層次</th><th class="num">人數</th><th>占比</th></tr>';
        foreach ([3, 2, 1] as $k) {
            $pct = round(100 * $cnt[$k] / $tot);
            $tb .= '<tr><td>' . $k . '　' . $h($label[$k]) . '</td><td class="num">' . $cnt[$k] . '</td>'
                 . '<td><span class="sr-bar" style="width:' . max(2, $pct) . '%"></span> ' . $pct . '%</td></tr>';
        }
        $out[] = '<div class="sr-tw"><table class="sr-t"><caption>理解層次分布(共 ' . $tot . ' 人)</caption>' . $tb . '</table></div>';
    }

    /* 三、地圖重點覆蓋 */
    $cov = $rec['coverage'] ?? [];
    if ($cov) {
        $tot = max(1, (int)($rec['students'] ?? 1));
        $tb = '<tr><th>解析重點</th><th class="num">碰到的人數</th><th>覆蓋率</th></tr>';
        usort($cov, fn($a, $b) => ((int)($b['hit'] ?? 0)) <=> ((int)($a['hit'] ?? 0)));
        foreach ($cov as $c) {
            $hit = (int)($c['hit'] ?? 0); $pct = round(100 * $hit / $tot);
            $tb .= '<tr><td>' . $h($c['point'] ?? '') . '</td><td class="num">' . $hit . ' / ' . $tot . '</td>'
                 . '<td><span class="sr-bar' . ($pct < 50 ? ' low' : '') . '" style="width:' . max(2, $pct) . '%"></span> ' . $pct . '%</td></tr>';
        }
        $out[] = '<div class="sr-tw"><table class="sr-t"><caption>解析重點覆蓋(低於一半的值得補)</caption>' . $tb . '</table></div>';
    }

    /* 四、共同卡點 */
    $iss = $rec['issues'] ?? [];
    if ($iss) {
        $tb = '<tr><th>共同卡點</th><th class="num">出現人數</th></tr>';
        usort($iss, fn($a, $b) => ((int)($b['n'] ?? 0)) <=> ((int)($a['n'] ?? 0)));
        foreach ($iss as $c) $tb .= '<tr><td>' . $h($c['issue'] ?? '') . '</td><td class="num">' . (int)($c['n'] ?? 0) . ' 人</td></tr>';
        $out[] = '<div class="sr-tw"><table class="sr-t"><caption>共同卡點</caption>' . $tb . '</table></div>';
    }

    return $out ? '<div class="sr-stats">' . implode('', $out) . '</div>' : '';
}
