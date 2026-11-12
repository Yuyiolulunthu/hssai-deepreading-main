/* ============================================================
   讀伴・手機版行為(≤ 760px 的觸控裝置)
   搭配 mobile.css。載入在 app.js 之後,不改動 app.js 的任何函式內容。
   手機版結構:
     · 底部分頁列 = 閱讀階段與畫面合一:導讀｜閱讀｜對話｜回顧(與電腦版的階段同名同序)
       (閱讀 = 讀本;導讀、對話是階段,也各自對應中欄;回顧開啟回顧視窗)。頂列只留品牌與「⋯」。
     · 筆記、對話樹各是一個獨立、可拖曳的浮動面板(一次開一個)。
       右側浮動工具座有兩顆按鈕:對話樹(只在對話階段)、筆記(顯示則數;導讀階段也能用)。
   只由 index.html 在手機上載入,電腦不會執行這個檔案。
   載入後手機轉成橫向(超過 760px)時等於沒有作用(元件隱藏、狀態還原)。
   ============================================================ */
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const ws = $('workspace');
  if (!ws) return;

  const PHONE = '(max-width: 760px) and (pointer: coarse)';                 // 與 index.html 的條件相同
  const mq = window.matchMedia(PHONE);
  const isPhone = () => mq.matches;

  // mobile.css 只在手機條件下套用,條件不符時由這裡隱藏已建立的手機元件
  const hideStyle = document.createElement('style');
  hideStyle.textContent = '@media not all and ' + PHONE +
    ' { .phone-nav, .fab-dock, .sheet-grip, .sheet-head, .phone-only, #panelTreeSheet, .branch-bar, .sheet-scrim, .tb-act { display: none !important; } }';
  document.head.appendChild(hideStyle);
  const inGuide = () => ws.classList.contains('stage-guide');

  /* ---------- 小設定:記在瀏覽器 ---------- */
  const KEY = 'dupan.phone.v3';
  const S = { tab: 'reader', notesH: 0.5, treeH: 0.4, fabAt: {}, hinted: false };   // fabAt:使用者拖過浮動球的位置(依畫面分開記)
  try { Object.assign(S, JSON.parse(localStorage.getItem(KEY) || '{}')); } catch (e) {}
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} };
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

  const ICON = {
    book: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/><path d="M9 8h6"/></svg>',
    guide: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.4 5.2L20 9l-4 3.9.9 5.6L12 15.8 7.1 18.5 8 12.9 4 9l5.6-.8z"/></svg>',
    chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    review: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/></svg>',
    tree: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3v12"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>',
    note: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19c0-8 6-14 15-14 0 9-6 15-14 15"/><path d="M5 19l7-7"/></svg>',
    trunk: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="6" r="2.6"/><path d="M12 8.6V21"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
  };

  /* ---------- 建立手機專用元件(桌機時被 CSS 隱藏) ---------- */
  const nav = document.createElement('nav');
  nav.className = 'phone-nav';
  nav.id = 'phoneNav';
  nav.setAttribute('aria-label', '閱讀階段與畫面');
  nav.innerHTML =
    `<button type="button" data-nav="guide">${ICON.guide}<span>導讀</span><i class="pn-dot" id="pnDot"></i></button>` +
    `<button type="button" data-nav="reader">${ICON.book}<span>閱讀</span></button>` +
    `<button type="button" data-nav="dialogue">${ICON.chat}<span>對話</span></button>` +
    `<button type="button" data-nav="review">${ICON.review}<span>回顧</span></button>`;
  ws.after(nav);

  const dock = document.createElement('div');
  dock.className = 'fab-dock';
  dock.id = 'fabDock';
  dock.innerHTML =
    `<button type="button" class="fab" id="treeFab" data-sec="tree" aria-label="開啟對話樹" hidden>${ICON.tree}</button>` +   // 手機版不顯示:對話樹入口在對話頂部的分支列
    `<button type="button" class="fab" id="notesFab" data-sec="notes" aria-label="開啟筆記">${ICON.note}<span class="fab-count" id="fabCount"></span></button>`;
  document.body.appendChild(dock);
  const treeFab = $('treeFab'), notesFab = $('notesFab');

  /* 對話樹、筆記的入口:放在頂列右側「⋯」旁邊(頂列每一頁都在,位置固定,不必另開一列、也不會漂在內容上)。
     筆記每一頁都有;對話樹只在「對話」畫面出現。浮動球改由這兩顆取代。 */
  const mkAct = (id, label, icon, badge) => {
    const b = document.createElement('button');
    b.type = 'button'; b.id = id; b.className = 'tb-act';
    b.setAttribute('aria-label', label); b.title = label;
    b.innerHTML = `${icon}<span class="fab-count" id="${badge}"></span>`;
    return b;
  };
  const tbTree = mkAct('tbTree', '開啟對話樹', ICON.tree, 'tbTreeCount');
  const tbNotes = mkAct('tbNotes', '開啟筆記', ICON.note, 'tbNotesCount');
  tbTree.hidden = true;
  (function () { const r = document.querySelector('.tb-right'); const m = $('btnMenu'); if (r && m) r.insertBefore(tbNotes, m), r.insertBefore(tbTree, tbNotes); })();

  /* 面板零件:每個面板各有握把與標題列 */
  function makeParts(title, id) {
    const grip = document.createElement('div');
    grip.className = 'sheet-grip';
    grip.setAttribute('role', 'separator');
    grip.setAttribute('aria-label', '拖曳調整面板高度，點一下切換大小');
    grip.innerHTML = '<i></i>';
    const head = document.createElement('div');
    head.className = 'sheet-head';
    head.innerHTML = `<h3 class="sheet-title" id="${id}">${title}</h3>` +
      `<button type="button" class="sheet-close" aria-label="收起${title.replace(/<.*$/, '')}">${ICON.close}</button>`;
    return { grip, head };
  }
  const side = $('panelSide');                          // 筆記面板(原右欄)
  const notesParts = makeParts('筆記<small id="notesTitleCount"></small>', 'notesTitle');
  const treeSheet = document.createElement('aside');    // 對話樹面板
  treeSheet.id = 'panelTreeSheet';
  treeSheet.className = 'float-sheet';
  treeSheet.setAttribute('aria-label', '對話樹');
  const treeParts = makeParts('對話樹', 'treeTitle');
  const treeHint = document.createElement('p');         // 提示:怎麼直接拖曳調整
  treeHint.className = 'sheet-hint';
  treeHint.textContent = '按住分支拖到另一個節點上放開，就能改接到它底下。';
  treeSheet.append(treeParts.grip, treeParts.head, treeHint);
  document.body.appendChild(treeSheet);
  const addNoteBtn = $('btnAddNote');
  const addNoteHome = addNoteBtn ? { parent: addNoteBtn.parentNode, next: addNoteBtn.nextSibling } : null;

  const SHEETS = {
    notes: { el: side, key: 'notesH', parts: notesParts },
    tree: { el: treeSheet, key: 'treeH', parts: treeParts }
  };

  /* ---------- 畫面(讀本｜中欄)與分頁列的標示 ---------- */
  function setTab(tab) {
    if (!isPhone()) return;
    if (tab !== 'reader' && tab !== 'chat') tab = 'reader';
    S.tab = tab;
    ws.dataset.tab = tab;
    document.body.classList.remove('chrome-hidden');                       // 換畫面時頂列一律先出現
    syncNav();
    save();
  }

  /** 分頁列亮哪一顆:在讀本=讀本;在中欄=依目前階段亮導讀或對話 */
  function syncNav() {
    const which = S.tab === 'reader' ? 'reader' : (inGuide() ? 'guide' : 'dialogue');
    nav.querySelectorAll('button').forEach((b) => {
      const on = b.dataset.nav === which;
      b.classList.toggle('on', on);
      b.setAttribute('aria-current', on ? 'page' : 'false');
    });
    const done = $('btnBriefDone');
    const ready = inGuide() && done && !done.disabled && S.tab === 'reader';   // 讀完全文,可以去導讀開始對話
    $('pnDot').classList.toggle('on', !!ready);
    const treeOk = !inGuide() && S.tab === 'chat';                              // 對話樹只在「對話」畫面出現(讀本、導讀都不需要)
    treeFab.hidden = true;                                                      // 浮動球已停用(入口改放頂列)
    tbTree.hidden = !treeOk;
    if (!treeOk && active === 'tree') closeSheet();
    requestAnimationFrame(() => { if (typeof placeDock === 'function') placeDock(); });
  }

  nav.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-nav]');
    if (!b) return;
    const k = b.dataset.nav;
    if (k === 'reader') {
      if (S.tab === 'reader') $('readerBody').scrollTo({ top: 0, behavior: 'smooth' });   // 已在讀本:回到最上面
      setTab('reader');
    } else if (k === 'guide') {
      $('btnBriefing').click();            // 交給原本的階段按鈕處理(含「已在導讀」的判斷)
    } else if (k === 'dialogue') {
      $('btnDialogue').click();            // 讀完全文前會跳出提示,不會硬切
    } else if (k === 'review') {
      $('btnSummary').click();
    }
  });

  /* 階段切換時(導讀 ↔ 對話):同步標示;「回顧」視窗開著時也跟著原按鈕的 on 狀態 */
  new MutationObserver(syncNav).observe(ws, { attributes: true, attributeFilter: ['class'] });
  if ($('btnBriefDone')) new MutationObserver(syncNav).observe($('btnBriefDone'), { attributes: true, attributeFilter: ['disabled'] });
  if ($('briefGateHint')) $('briefGateHint').addEventListener('click', () => { if (isPhone()) setTab('reader'); });

  /* ---------- 分支找回:對話頂部分支列、訊息內分支標記、對話樹按鈕徽章 ----------
     問題:開了分支再回到主幹,分支就藏進對話樹裡,看不到、也不知道還有幾條沒收束。
     做法:① 對話標題下方常駐「主幹｜分支…」橫向列,點一下直接切換,目前所在亮起、
          進行中=實心紅點、已保留=空心紅點、已收束=虛線灰;
          ② 主幹裡「從這則訊息開出的分支」,在那則訊息下方留一個「↳ 分支名稱」標記;
          ③ 對話樹按鈕的徽章 = 還沒收束(進行中+已保留)的分支數。
     資料取自 app.js 的 doc / currentNodeId,畫面由本檔自己重畫,不改動 app.js。 */
  const hasDoc = () => typeof doc !== 'undefined' && doc && doc.nodes;
  const STATUS = { open: '進行中', kept: '已保留', merged: '已收束' };
  const short = (t, n) => { t = String(t || ''); return t.length > n ? t.slice(0, n) + '…' : t; };
  const goNode = (id) => { if (typeof switchNode === 'function') { switchNode(id); setTab('chat'); closeSheet(); } };

  /* 分支找回(精簡版):頂列對話樹圖示的徽章 = 還沒收束的分支數;人在分支裡時,對話上方出現
     「主幹 › 分支名稱」路徑列(點「主幹」就回去)和「保留／收束」;其餘靠訊息下方的「↳ 分支」標記。 */
  function renderBranchUI() {
    const pc = $('panelChat');
    if (!isPhone() || !hasDoc()) { pc.classList.remove('in-branch', 'on-branch'); return; }
    const branches = Object.values(doc.nodes).filter((n) => n.type === 'branch');
    const pending = branches.filter((n) => n.status !== 'merged').length;
    $('tbTreeCount').textContent = pending ? String(pending) : '';
    tbTree.setAttribute('aria-label', pending ? `開啟對話樹，還有 ${pending} 條分支未收束` : '開啟對話樹');
    const cur = doc.nodes[currentNodeId];
    const inBranch = !!cur && cur.type === 'branch';
    pc.classList.toggle('on-branch', inBranch && !inGuide());
    pc.classList.toggle('in-branch', inBranch && cur.status === 'open');          // 只有進行中的分支才需要「保留／收束」
  }

  /* 主幹(或任何節點)裡,從某則訊息生出的分支:在那則訊息下方放標記 */
  const chatBody = $('chatBody');
  const pillObs = new MutationObserver(() => { pillObs.disconnect(); try { injectPills(); } finally { pillObs.observe(chatBody, { childList: true }); } });
  function injectPills() {
    chatBody.querySelectorAll('.branch-pills').forEach((e) => e.remove());
    if (!isPhone() || !hasDoc() || !doc.nodes[currentNodeId]) return;
    const node = doc.nodes[currentNodeId];
    const els = [...chatBody.children].filter((e) => e.classList.contains('msg') || e.classList.contains('merge-card'));
    node.messages.forEach((m, i) => {
      const kids = Object.values(doc.nodes).filter((n) => n.type === 'branch' && n.parentId === node.id && n.originMsgId === m.id);
      if (!kids.length || !els[i]) return;
      const row = document.createElement('div');
      row.className = 'branch-pills';
      kids.forEach((k) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'bp-pill ' + (k.status || 'open');
        b.textContent = `↳ ${short(k.title, 10)} · ${STATUS[k.status] || '進行中'}`;
        b.onclick = () => goNode(k.id);
        row.appendChild(b);
      });
      els[i].appendChild(row);
    });
  }
  pillObs.observe(chatBody, { childList: true });
  let branchRaf = 0;
  const scheduleBranchUI = () => { cancelAnimationFrame(branchRaf); branchRaf = requestAnimationFrame(renderBranchUI); };
  new MutationObserver(scheduleBranchUI).observe($('crumbs'), { childList: true });     // 每次切換節點、重畫對話都會動到
  new MutationObserver(scheduleBranchUI).observe($('treeList'), { childList: true });   // 新增、收束、保留、刪除分支都會重畫樹

  /* ---------- 浮動面板:筆記、對話樹(各自獨立,一次開一個) ---------- */
  const SNAPS = [0.4, 0.62, 0.94];
  const H = () => Math.max(260, document.body.getBoundingClientRect().height);   // 面板可用的最大高度 = 整個 App 高度(面板蓋住分頁列,和回顧視窗一樣)
  let active = null;                                   // 目前開著的面板:'notes' | 'tree' | null
  const isOpen = () => active !== null;

  const scrim = document.createElement('div');           // 面板開著時背景變暗(和「回顧」視窗同一個暗度),點背景即收起
  scrim.className = 'sheet-scrim';
  scrim.id = 'sheetScrim';
  document.body.appendChild(scrim);
  scrim.addEventListener('click', () => {
    if (document.body.classList.contains('lookup-open')) { const x = $('btnLookupClose'); if (x) x.click(); }   // 簡要查詢開著:關查詢
    else closeSheet();
  });
  (function watchLookup() {                                // 簡要查詢浮動視窗開啟時,和面板一樣把背景壓暗
    const lp = $('lookupPanel');
    if (!lp) return;
    const sync = () => document.body.classList.toggle('lookup-open', lp.classList.contains('open') && isPhone());
    new MutationObserver(sync).observe(lp, { attributes: true, attributeFilter: ['class'] });
    sync();
  })();

  function applySheet(px) {
    const h = clamp(px, 150, H() - 8);
    document.documentElement.style.setProperty('--sheet-h', h + 'px');
    return h;
  }
  const redrawTree = () => {
    if (typeof drawTreeEdges === 'function') requestAnimationFrame(() => { try { drawTreeEdges(); } catch (e) {} });
  };

  function openSheet(which) {
    if (!isPhone() || !SHEETS[which]) return;
    if (which === 'tree' && (inGuide() || S.tab !== 'chat')) return;   // 只有對話畫面才有對話樹
    active = which;
    document.body.dataset.sheet = which;
    document.body.classList.add('sheet-open');
    applySheet(S[SHEETS[which].key] * H());
    if (which === 'tree') redrawTree();
  }
  function closeSheet() {
    active = null;
    delete document.body.dataset.sheet;
    document.body.classList.remove('sheet-open', 'sheet-dragging');
  }
  Object.values(SHEETS).forEach((sh) => sh.parts.head.querySelector('.sheet-close').addEventListener('click', closeSheet));
  [treeFab, notesFab].forEach((f) => f.addEventListener('click', () => { if (!dock._dragged) openSheet(f.dataset.sec); }));
  tbTree.addEventListener('click', () => openSheet('tree'));
  tbNotes.addEventListener('click', () => openSheet('notes'));

  /* 在對話樹點了節點:收起面板,回到那條對話 */
  let quietTree = 0;
  let treeDragOn = false;                              // 正在(或剛剛)拖曳對話樹節點:期間擋掉原本的「點選」處理
  // 原本的選取邏輯在 pointerup 就重繪列表,不會再有 click,所以這裡也聽 pointerup(位移太大視為捲動/拖曳,不收起)
  (function bindTreePick() {
    const list = $('treeList');
    let x0 = 0, y0 = 0;
    list.addEventListener('pointerdown', (e) => { x0 = e.clientX; y0 = e.clientY; }, true);
    list.addEventListener('pointerup', (e) => {
      if (treeDragOn || !isPhone() || !e.target.closest('.tree-pick') || Math.hypot(e.clientX - x0, e.clientY - y0) > 8) return;
      quietTree = Date.now() + 600;                    // 選節點時 app 會順手呼叫 focusPanel('tree'),這段時間別又把面板打開
      setTimeout(() => { closeSheet(); setTab('chat'); }, 120);
    }, true);
  })();

  /* ---------- 對話樹:直接拖曳調整 ----------
     按住分支一滑(超過 8px,手指會震一下)→ 節點浮起跟著手指 → 拖到另一個節點上放開,
     就接到那個節點底下;拖到清單上下緣會自動捲動;放在空白處或自己身上 = 取消。
     (滑鼠本來就能拖,是 app.js 處理的;觸控的分支名稱不再負責捲動,見 mobile.css 的 touch-action,
     要捲動時滑右側「⋯」欄或空白處。)
     改接本身呼叫 app.js 的 reparent(),所以「不能移到自己的子分支底下」等規則完全一致。 */
  (function bindTreeDrag() {
    const list = $('treeList');
    let st = null, raf = 0;
    const rowAt = (x, y) => { const el = document.elementFromPoint(x, y); return el && el.closest ? el.closest('.tree-row') : null; };
    const clearMarks = () => list.querySelectorAll('.drop-target, .drop-bad').forEach((e) => e.classList.remove('drop-target', 'drop-bad'));
    const release = () => setTimeout(() => { treeDragOn = false; }, 400);   // 放開後一小段時間仍擋掉瀏覽器補發的 pointerup/click

    function finish() {
      if (!st) return;
      cancelAnimationFrame(raf);
      st.unbind();
      if (st.ghost) st.ghost.remove();
      if (st.row) st.row.classList.remove('dragging');
      clearMarks();
      document.body.classList.remove('tree-dragging');
      st = null;
    }
    function place() {
      st.ghost.style.transform = `translate(${Math.round(st.x - 24)}px, ${Math.round(st.y - 58)}px)`;
      const row = rowAt(st.x, st.y);
      clearMarks();
      st.over = null;
      if (row && row.dataset.nodeId && row.dataset.nodeId !== st.id) {
        const bad = typeof subtreeIds === 'function' && subtreeIds(st.id).includes(row.dataset.nodeId);
        row.classList.add(bad ? 'drop-bad' : 'drop-target');
        if (!bad) st.over = row.dataset.nodeId;
      }
    }
    function loop() {                                   // 手指靠近清單上下緣:自動捲動
      if (!st || !st.active) return;
      const r = list.getBoundingClientRect();
      const edge = Math.min(56, r.height * 0.2);        // 面板矮時感應區跟著縮,不會一拖就捲走
      if (st.y < r.top + edge) list.scrollTop -= 9; else if (st.y > r.bottom - edge) list.scrollTop += 9;
      place();
      raf = requestAnimationFrame(loop);
    }
    function start() {
      if (!st) return;
      st.active = true; treeDragOn = true;
      try { if (navigator.vibrate) navigator.vibrate(12); } catch (e) {}
      st.row.classList.add('dragging');
      const g = document.createElement('div');
      g.className = 'tree-ghost';
      g.textContent = (st.row.querySelector('.tree-label') || {}).textContent || '';
      document.body.appendChild(g);
      st.ghost = g;
      document.body.classList.add('tree-dragging');
      place();
      loop();
    }

    list.addEventListener('touchstart', (e) => {
      if (!isPhone() || e.touches.length !== 1 || !hasDoc()) return;
      const pick = e.target.closest && e.target.closest('.tree-pick');   // 只有名稱區能拖,「⋯」與空白處留給捲動
      const row = pick && pick.closest('.tree-row');
      if (!row || !row.dataset.nodeId) return;
      const n = doc.nodes[row.dataset.nodeId];
      if (!n || n.type === 'trunk') return;             // 主幹不能拖
      const t = e.touches[0];
      finish();
      st = { id: n.id, row, x: t.clientX, y: t.clientY, x0: t.clientX, y0: t.clientY, active: false, over: null };
      // 後續觸控事件聽在手指按下的元素本身:app.js 在 pointerup 就重繪清單,元素被移除後事件不會再冒泡到清單或 document
      const tgt = e.target;
      tgt.addEventListener('touchmove', onTouchMove, { passive: false });
      tgt.addEventListener('touchend', onTouchEnd);
      tgt.addEventListener('touchcancel', onTouchCancel);
      st.unbind = () => {
        tgt.removeEventListener('touchmove', onTouchMove);
        tgt.removeEventListener('touchend', onTouchEnd);
        tgt.removeEventListener('touchcancel', onTouchCancel);
      };
    }, { passive: true });
    function onTouchMove(e) {
      if (!st) return;
      const t = e.touches[0];
      st.x = t.clientX; st.y = t.clientY;
      if (!st.active) { if (Math.hypot(st.x - st.x0, st.y - st.y0) > 8) start(); return; }   // 門檻內視為點一下(和 bindTreePick 同為 8px)
      e.preventDefault();                               // 拖曳中不要讓清單跟著捲
      place();
    }
    function onTouchEnd() {
      if (!st) return;
      const was = st.active, id = st.id, over = st.over;
      finish();
      if (!was) return;
      release();
      if (over && typeof reparent === 'function') reparent(id, over);
    }
    function onTouchCancel() { const was = st && st.active; finish(); if (was) release(); }
    // 拖曳期間,原本節點上的點選/長按選單處理一律擋掉(否則放開時會被當成「點了這個節點」而切換過去)
    ['pointerup', 'pointercancel', 'click', 'contextmenu'].forEach((type) => {
      list.addEventListener(type, (e) => {
        if (!treeDragOn) return;
        e.stopImmediatePropagation(); e.stopPropagation();
        if (type === 'click' || type === 'contextmenu') e.preventDefault();
      }, true);
    });
  })();

  /* 拖曳握把:放開時吸附到最近的高度;拖到很低就收起;點一下在三段高度間輪流切換 */
  function bindSheetDrag(which) {
    const sh = SHEETS[which], grip = sh.parts.grip;
    let y0 = 0, h0 = 0, moved = false, id = null;
    grip.addEventListener('pointerdown', (e) => {
      id = e.pointerId; y0 = e.clientY; h0 = sh.el.getBoundingClientRect().height; moved = false;
      grip.setPointerCapture(id);
      document.body.classList.add('sheet-dragging');
    });
    grip.addEventListener('pointermove', (e) => {
      if (id !== e.pointerId) return;
      const dy = e.clientY - y0;
      if (Math.abs(dy) > 4) moved = true;
      if (moved) applySheet(h0 - dy);
    });
    const end = (e) => {
      if (id !== e.pointerId) return;
      id = null;
      document.body.classList.remove('sheet-dragging');
      const cur = sh.el.getBoundingClientRect().height / H();
      if (!moved) {
        S[sh.key] = SNAPS.find((r) => r > cur + 0.04) || SNAPS[0];
        applySheet(S[sh.key] * H());
      } else if (cur < 0.22) {
        closeSheet();
      } else {
        S[sh.key] = SNAPS.reduce((a, b) => (Math.abs(b - cur) < Math.abs(a - cur) ? b : a));
        applySheet(S[sh.key] * H());
      }
      save();
    };
    grip.addEventListener('pointerup', end);
    grip.addEventListener('pointercancel', end);
  }
  bindSheetDrag('notes');
  bindSheetDrag('tree');

  /* 浮動筆記球:預設自動停在「該畫面底部操作區」的上方(對話=輸入區、導讀=開始對話鈕、讀本=分頁列),
     不壓到送出鈕與正文;使用者拖過就記住,而且每個畫面各記各的;連點兩下回到預設位置。 */
  const dockKey = () => (S.tab === 'reader' ? 'reader' : (inGuide() ? 'guide' : 'chat'));
  const dockAnchor = () => {
    if (S.tab === 'reader') return null;
    const sel = inGuide() ? '.brief-foot' : '#panelChat .composer';
    const el = document.querySelector(sel);
    return el && el.offsetParent ? el : null;
  };
  const dockLimits = () => {
    const top = nav.offsetTop || window.innerHeight;                       // 分頁列頂端
    return { lo: 70, hi: Math.max(70, top - dock.offsetHeight - 12), top };
  };
  function placeDock() {
    if (!isPhone()) return;
    const { lo, hi, top } = dockLimits();
    const saved = S.fabAt[dockKey()];
    let y;
    if (typeof saved === 'number') y = saved * top;
    else { const a = dockAnchor(); y = a ? a.getBoundingClientRect().top - dock.offsetHeight - 12 : hi; }
    dock.style.top = clamp(y, lo, hi) + 'px';
  }
  (function bindDockDrag() {
    placeDock();
    let y0 = 0, t0 = 0, id = null, target = null;
    dock.addEventListener('pointerdown', (e) => {
      target = e.target.closest('.fab'); if (!target) return;
      id = e.pointerId; y0 = e.clientY; t0 = dock.getBoundingClientRect().top; dock._dragged = false;
      target.setPointerCapture(id);
    });
    dock.addEventListener('pointermove', (e) => {
      if (id !== e.pointerId) return;
      const dy = e.clientY - y0;
      if (Math.abs(dy) > 6) dock._dragged = true;
      if (dock._dragged) { const { lo, hi } = dockLimits(); dock.style.top = clamp(t0 + dy, lo, hi) + 'px'; }
    });
    const end = (e) => {
      if (id !== e.pointerId) return;
      id = null;
      if (dock._dragged) {
        S.fabAt[dockKey()] = dock.getBoundingClientRect().top / dockLimits().top;
        save();
        setTimeout(() => { dock._dragged = false; }, 50);        // 讓緊接的 click 被忽略
      }
    };
    dock.addEventListener('pointerup', end);
    dock.addEventListener('pointercancel', end);
    dock.addEventListener('dblclick', () => { delete S.fabAt[dockKey()]; save(); placeDock(); });
    window.addEventListener('resize', placeDock);
    const composer = document.querySelector('#panelChat .composer');
    if (composer && window.ResizeObserver) new ResizeObserver(placeDock).observe(composer);      // 輸入框長高時球跟著上移
  })();

  /* 筆記數量同步到浮動按鈕與面板分頁;新增時彈一下 */
  (function syncCount() {
    const src = $('noteCount'), out = $('fabCount');
    if (!src) return;
    let last = 0;
    const sync = () => {
      const n = parseInt(src.textContent, 10) || 0;
      out.textContent = n ? String(n) : '';
      $('tbNotesCount').textContent = n ? String(n) : '';
      tbNotes.setAttribute('aria-label', n ? `開啟筆記，共 ${n} 則` : '開啟筆記');
      notesParts.head.querySelector('#notesTitleCount').textContent = n ? n + ' 則' : '';
      notesFab.setAttribute('aria-label', n ? `開啟筆記，共 ${n} 則` : '開啟筆記');
      if (n > last && isPhone() && !isOpen()) { tbNotes.classList.remove('bump'); void tbNotes.offsetWidth; tbNotes.classList.add('bump'); }
      last = n;
    };
    new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true });
    sync();
  })();

  /* ---------- 取代 focusPanel:手機=切畫面/開面板;桌機照舊 ---------- */
  const origFocus = window.focusPanel;
  window.focusPanel = function (which) {
    if (!isPhone()) return origFocus ? origFocus.apply(this, arguments) : undefined;
    if (which === 'notes') openSheet('notes');
    else if (which === 'tree') { if (Date.now() > quietTree && S.tab === 'chat') openSheet('tree'); }
    else { closeSheet(); setTab(which === 'reader' ? 'reader' : 'chat'); }   // 換畫面時收起面板,跳到的內容才不會被蓋住
  };

  /* ---------- 進入 / 離開手機版 ---------- */
  // 說明裡「按頂部的回顧」:手機的回顧在底部分頁列,用字跟著換
  const helpText = (() => {
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (w.nextNode()) if (w.currentNode.nodeValue.includes('按頂部的「回顧」')) return w.currentNode;
    return null;
  })();
  const setHelpText = (phone) => {
    if (!helpText) return;
    helpText.nodeValue = phone ? helpText.nodeValue.replace('按頂部的「回顧」', '按「回顧」') : helpText.nodeValue.replace('按「回顧」', '按頂部的「回顧」');
  };
  // 讀本功能:用電腦版頂列的同一個「≡」選單,手機版放在讀本標題列右側。
  // 字級鈕與標記圖例和電腦版一樣搬進選單;回到電腦版面時由 app.js 依它記下的位置處理,這裡不搬回。
  const rBar = $('tbReader');
  const rBarHome = rBar && { parent: rBar.parentNode, next: rBar.nextSibling };
  function readerMenuToPhone() {
    if (!rBar || !$('rmFont')) return;
    document.querySelector('#panelReader .col-head').appendChild(rBar);
    rBar.hidden = false;
    ['btnFontMinus', 'btnFontPlus'].forEach((id) => { if ($(id) && $(id).parentNode !== $('rmFont')) $('rmFont').appendChild($(id)); });
    const legend = document.querySelector('.reader-legend');
    if (legend && legend.parentNode !== $('rmLegend')) $('rmLegend').appendChild(legend);
  }
  function readerMenuToDesktop() {
    if (!rBar) return;
    if (typeof readerMenuOpen === 'function') readerMenuOpen(false);
    // app.js 已先把文本標題等搬進 rBar 並設好 hidden,這裡只把整列放回頂列原位
    rBarHome.parent.insertBefore(rBar, rBarHome.next && rBarHome.next.parentNode === rBarHome.parent ? rBarHome.next : null);
  }
  function enterPhone() {
    readerMenuToPhone();
    if (!notesParts.grip.parentNode) {
      side.prepend(notesParts.head);                    // 筆記面板:握把 → 標題列(含「手寫筆記」)→ 筆記區
      side.prepend(notesParts.grip);
      if (addNoteBtn) notesParts.head.insertBefore(addNoteBtn, notesParts.head.querySelector('.sheet-close'));
      treeSheet.appendChild($('treeSec'));              // 對話樹面板:握把 → 標題列 → 對話樹
    }
    measureTopbar();
    setTab(S.tab);
    onViewport();
    renderBranchUI(); injectPills();
    setHelpText(true);
  }
  function leavePhone() {
    closeSheet();
    delete ws.dataset.tab;
    readerMenuToDesktop();
    setHelpText(false);
    if ($('composerInput')) $('composerInput').style.height = '';
    if (notesParts.grip.parentNode) {                   // 還原成桌機的右欄結構
      side.insertBefore($('treeSec'), $('sideSplitter'));
      if (addNoteBtn && addNoteHome) addNoteHome.parent.insertBefore(addNoteBtn, addNoteHome.next);
      notesParts.grip.remove(); notesParts.head.remove();
    }
    $('panelChat').classList.remove('in-branch', 'on-branch');
    chatBody.querySelectorAll('.branch-pills').forEach((e) => e.remove());
    document.body.classList.remove('chrome-hidden', 'kb-open');
    const root = document.documentElement.style;
    ['--app-h', '--kb-h', '--sheet-h', '--sheet-pad', '--tb-h'].forEach((p) => root.removeProperty(p));
    if (typeof applyLayout === 'function') applyLayout();
  }
  function measureTopbar() {
    const tb = document.querySelector('.topbar');
    if (tb && !document.body.classList.contains('chrome-hidden')) {
      document.documentElement.style.setProperty('--tb-h', tb.offsetHeight + 'px');
    }
  }
  const onChange = () => (isPhone() ? enterPhone() : leavePhone());
  if (mq.addEventListener) mq.addEventListener('change', onChange); else mq.addListener(onChange);

  /* ---------- 鍵盤:整頁高度跟著可視區縮放;浮動面板抬到鍵盤上方 ----------
     鍵盤彈出時,瀏覽器只縮小「可視區」(visualViewport),固定定位(position: fixed)的浮動面板
     仍貼著「版面區」底端,會被鍵盤整個蓋住。所以:
       · --kb-h = 鍵盤蓋住的高度,所有貼底的浮動元件把 bottom 抬高這麼多;
       · 鍵盤開著時筆記/對話樹面板撐滿可視區,輸入框一定在鍵盤上方;
       · 輸入框取得焦點後,把它捲到面板中央。
     Chrome Android 另外在 index.html 的 viewport 設 interactive-widget=resizes-content,
     連版面區一起縮小,此時 --kb-h 會是 0,公式同樣成立。 */
  let baseH = window.innerHeight;                        // 沒開鍵盤時的視窗高度(取歷來最大值)
  function onViewport() {
    if (!isPhone()) return;
    const vv = window.visualViewport;
    const vh = vv ? vv.height : window.innerHeight;
    const top = vv ? vv.offsetTop : 0;
    baseH = Math.max(baseH, window.innerHeight);
    const kb = baseH - vh > 110;
    const kbH = Math.max(0, window.innerHeight - vh - top);
    const root = document.documentElement.style;
    document.body.classList.toggle('kb-open', kb);
    if (kb && S.tab === 'chat') document.body.classList.add('chrome-hidden');      // 打字時連頂列也收起,輸入區最大
    else if (!kb && S.tab === 'chat') document.body.classList.remove('chrome-hidden');
    root.setProperty('--kb-h', (kb ? kbH : 0) + 'px');
    root.setProperty('--app-h', kb ? vh + 'px' : '100dvh');
    if (kb) window.scrollTo(0, 0);
    if (isOpen()) applySheet((kb ? 0.96 : S[SHEETS[active].key]) * H());
    measureTopbar();
    placeDock();
    if (kb) keepFocusVisible();
  }
  /** 輸入框別被鍵盤蓋住:在面板裡就捲到面板中央,在對話輸入區就捲到可見處 */
  function keepFocusVisible() {
    const a = document.activeElement;
    if (!a || !/^(TEXTAREA|INPUT)$/.test(a.tagName)) return;
    const inSheet = !!a.closest('#panelSide, #panelTreeSheet');
    setTimeout(() => { try { a.scrollIntoView({ block: inSheet ? 'center' : 'nearest' }); } catch (e) {} }, 120);
  }
  document.addEventListener('focusin', (e) => {
    if (!isPhone() || !e.target.matches || !e.target.matches('textarea, input')) return;
    setTimeout(() => { onViewport(); keepFocusVisible(); }, 250);   // 鍵盤要等一下才會升起
  });
  if (window.visualViewport) {
    visualViewport.addEventListener('resize', onViewport);
    visualViewport.addEventListener('scroll', () => { if (document.body.classList.contains('kb-open')) { window.scrollTo(0, 0); onViewport(); } });
  }
  window.addEventListener('orientationchange', () => setTimeout(() => { baseH = window.innerHeight; onViewport(); }, 300));

  /* ---------- 輸入框隨內容長高(最多約五行) ---------- */
  (function bindAutosize() {
    const ta = $('composerInput');
    if (!ta) return;
    const fit = () => {
      if (!isPhone()) return;
      ta.style.height = 'auto';
      ta.style.height = Math.min(ta.scrollHeight + 2, 132) + 'px';
    };
    ta.addEventListener('input', fit);
    ta.addEventListener('focus', fit);
    // 送出後輸入框被程式清空,不會觸發 input:補一次
    if ($('btnSend')) $('btnSend').addEventListener('click', () => setTimeout(fit, 80));
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter') setTimeout(fit, 80); });
  })();

  /* ---------- 其他覆蓋層開著時,隱藏浮動工具座與分頁列 ---------- */
  (function watchOverlays() {
    const overlays = [...document.querySelectorAll('.overlay')];
    const gate = $('courseGate');
    const sync = () => {
      document.body.classList.toggle('dialog-open', overlays.some((o) => !o.classList.contains('hidden')));
      document.body.classList.toggle('gate-open', !!gate && !gate.classList.contains('hidden'));
    };
    const mo = new MutationObserver(sync);
    overlays.concat(gate ? [gate] : []).forEach((o) => mo.observe(o, { attributes: true, attributeFilter: ['class'] }));
    sync();
  })();

  /* ---------- 第一次使用:提示浮動按鈕(只出現一次) ---------- */
  setTimeout(() => {
    if (!isPhone() || S.hinted || document.body.classList.contains('gate-open') || document.body.classList.contains('dialog-open')) return;
    S.hinted = true; save();
    if (typeof toast === 'function') toast('筆記與對話樹在右上角的圖示，點開就是浮動面板');
  }, 2500);

  onChange();
})();
