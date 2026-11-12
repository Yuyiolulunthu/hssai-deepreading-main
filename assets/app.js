/* ============================================================
   讀伴・樹狀閱讀 — 前端主程式
   資料模型：
     doc = { id, title, text, createdAt, rootId,
             nodes: { id: node }, notes: [note] }
     node = { id, parentId, type:'trunk'|'branch', title, quote,
              originMsgId, status:'open'|'merged'|'kept',
              messages: [msg] }
     msg  = { id, role:'user'|'ai'|'merge', content, quote, branchId, ts }
     note = { id, content, ts }
   儲存：localStorage（單機、免資料庫，適合 cPanel 靜態部署）
   ============================================================ */

'use strict';

/* 免費版(自行上傳)的上限;教師上傳的課程文本另為中文 10 萬字、英文不限字數、10 MB(見 school_lib / teacher.php) */
/* 免費測試的限制;啟動時以 site.php 回傳的數字為準(伺服器端 guest_lib.php 同一組) */
let FREE_MAX_CHARS = 10000;
let FREE_MAX_BYTES = 1024 * 1024;
let FREE_MAX_TURNS = 50;

const LS_DOCS = 'dupan.docs.v1';
const LS_CURRENT = 'dupan.currentDoc.v1';

const $ = (id) => document.getElementById(id);
const uid = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const trunc = (s, n) => { s = String(s || '').replace(/\s+/g, ' ').trim(); return s.length > n ? s.slice(0, n) + '…' : s; };

/** 輕量 Markdown 渲染：先跳脫 HTML,再處理粗體/斜體/行內代碼/標題/清單 */
function mdLite(s) {
  let h = esc(s);
  h = h.replace(/^#{1,4}\s*(.+)$/gm, '<strong>$1</strong>');          // # 標題 → 粗體
  h = h.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');          // **粗體**
  h = h.replace(/(^|[\s(「(])\*([^*\n]+)\*(?=[\s)」)。,.!?!?、]|$)/g, '$1<em>$2</em>'); // *斜體*
  h = h.replace(/`([^`\n]+)`/g, '<code>$1</code>');                    // `代碼`
  h = h.replace(/^\s*[-*]\s+/gm, '・');                                // - 清單 → ・
  h = h.replace(/^\s*(\d+)[.、]\s+/gm, '$1、');                        // 1. 清單
  return h;
}

let readerReadDone = false;   // 讀本是否已捲到底（閱讀門檻）
let nickname = localStorage.getItem('dupan.nickname') || '';   // 使用者暱稱
let siteMode = 'both';        // 'both' 兩種入口 | 'open' 僅公開 | 'course' 僅課程
let audience = localStorage.getItem('dupan.audience') || '';   // 'course' | 'guest'
let course = null;            // { courseId, courseName, sid, name } 加入課程後存於 localStorage
try { course = JSON.parse(localStorage.getItem('dupan.course') || 'null'); } catch { course = null; }
let docs = [];
let doc = null;            // 目前文本
let currentNodeId = null;  // 目前對話節點
let pendingQuote = '';     // 待送出的引用
let pendingQuoteMarks = []; // 待送出引用在讀本上的位置(送出後畫底線)
let busy = false;          // 等待 AI 回覆中

/* ---------------- 儲存 ---------------- */

function loadDocs() {
  try { docs = JSON.parse(localStorage.getItem(LS_DOCS) || '[]'); }
  catch { docs = []; }
}
function saveDocs() {
  try { localStorage.setItem(LS_DOCS, JSON.stringify(docs)); }
  catch { toast('儲存空間已滿，請匯出後刪除舊文本', true); }
  if (doc) localStorage.setItem(LS_CURRENT, doc.id);
  if (typeof syncTranscript === 'function') syncTranscript();
}

/* ---------------- 分段共讀 ----------------
 * 讀者用書籤標出最近讀完的段落,讀伴就只針對這一段對話。每一段是一條主幹(trunk.seg = {start, end},段落索引),
 * 分支掛在各自的主幹下;doc.rootId 是目前這一段的主幹。舊資料的主幹沒有 seg,視為全文。 */
function trunksOf(d) {
  return Object.values((d || doc).nodes).filter(n => n.type === 'trunk')
    .sort((a, b) => (a.seg ? a.seg.start : -1) - (b.seg ? b.seg.start : -1));
}
function trunkOf(id) { let n = doc.nodes[id]; while (n && n.parentId) n = doc.nodes[n.parentId]; return n || doc.nodes[doc.rootId]; }
function segRange(t) { return (t && t.seg) ? t.seg : { start: 0, end: splitParas(doc.text).length - 1 }; }
/** 分支的狀態:收束到別段時寫出是哪一段 */
function branchStatus(b) {
  return b.status === 'merged' ? (b.mergedSeg ? `已收束到${b.mergedSeg}` : '已收束回主幹')
    : b.status === 'kept' ? '獨立保留' : '對話中';
}
/** 收束摘要的來源說明:從別段收束過來時是「（來自〈導論〉（第 1–12 段））」 */
const mergeFrom = m => m.fromSeg ? `（來自${m.fromSeg}）` : '';
/** 範圍的段落編號:「第 5 段」或「第 1–12 段」 */
function rangeLabel(r) { return r.start === r.end ? `第 ${r.start + 1} 段` : `第 ${r.start + 1}–${r.end + 1} 段`; }
/** 範圍的章節名稱:「〈導論〉」或「〈導論〉至〈第二章〉」。文本沒有小標題時回空字串
 *  (那時目錄是依段落自動分節,節名只是開頭幾個字,不適合當名稱) */
function segSecs(r) {
  const items = tocItems();
  if (!items.length || !items[0].h) return '';
  const at = p => { let x = null; for (const it of items) { if (it.start > p) break; x = it; } return x; };
  const nm = x => `〈${trunc(x.title === '（開頭）' ? '開頭' : x.title, 12)}〉`;
  const a = at(r.start), b = at(r.end);
  return !a ? '' : a === b ? nm(a) : `${nm(a)}至${nm(b)}`;
}
/** 範圍的完整名稱:章節與段落並列「〈導論〉至〈第二章〉（第 13–49 段）」;沒有小標題時只有段落 */
function rangeName(r) { const sc = segSecs(r); return sc ? `${sc}（${rangeLabel(r)}）` : rangeLabel(r); }
function segLabel(t) { return rangeLabel(segRange(t)); }
function trunkName(t) {
  if (!t || !t.seg) return '主幹';
  return segSecs(t.seg) ? rangeName(t.seg) : `段落：${segLabel(t)}`;
}
/** 分支所屬的段落:「（〈導論〉，第 1–12 段）」;舊資料的全文主幹回空字串 */
function segTag(n) {
  const t = trunkOf(n.id);
  if (!t || !t.seg) return '';
  const sc = segSecs(t.seg);
  return `（${sc ? sc + '，' : ''}${segLabel(t)}）`;
}
/** 所有分支,依所屬主幹的段落順序、和對話樹相同的上下順序排列(父分支在前),匯出時分支才會跟著段落走 */
function branchesBySeg() {
  const all = Object.values(doc.nodes), out = [];
  const walk = id => all.filter(c => c.parentId === id).sort((a, b) => a.id.localeCompare(b.id))
    .forEach(c => { if (c.type === 'branch' && !out.includes(c)) { out.push(c); walk(c.id); } });
  trunksOf().forEach(t => walk(t.id));
  return out.concat(all.filter(n => n.type === 'branch' && !out.includes(n)));   // 找不到主幹的分支放最後
}
/** 每一段一個顏色(依段落順序輪流),讓對話樹、路徑列、讀本上的「這次對話的範圍」一看就知道是同一段 */
const SEG_COLORS = ['#7A2331', '#2F6B8A', '#4C7A4A', '#B07A1E', '#6B4C8A'];
function segColor(t) {
  const i = Math.max(0, trunksOf().filter(x => x.seg).findIndex(x => x.id === (t && t.id)));
  return SEG_COLORS[i % SEG_COLORS.length];
}
/** 這一段底下的分支數 */
function branchCount(t) { return Object.values(doc.nodes).filter(n => n.type === 'branch' && trunkOf(n.id) === t).length; }
/** 把另一段的討論(摘要,沒有就取讀伴最近一次的回覆)放進目前對話的引用 */
function quoteFromSegment(t) {
  const lastAi = [...t.messages].reverse().find(m => m.role === 'ai');   // 讀伴的訊息 role 是 'ai'
  const body = (t.summary || (lastAi && lastAi.content) || '').replace(/\s+/g, ' ').trim();
  if (!body) { toast('這一段還沒有讀伴的回覆可以引用', true); return; }
  pendingQuote = `【${trunkName(t)}的討論】` + trunc(body, 300);
  pendingQuoteMarks = [];
  updatePendingQuote();
  focusPanel('chat');
  $('composerInput').focus();
  toast(`已把「${trunkName(t)}」的討論放進引用，寫下你想連結的想法`);
}
/** 這次對話的範圍:讀者在目錄選的起點(bm.head)到終點(bm.cur)。各次範圍彼此獨立,中間可以跳過;
 *  和已對話過的範圍完全相同就回到那一段。沒選範圍時,回到目前這一段的對話 */
function nextSegRange() {
  const n = splitParas(doc.text).length, b = doc.bm || {};
  let start = 0, end = n - 1;
  if (b.head >= 0 && b.cur >= 0) { start = Math.min(b.head, n - 1); end = Math.min(Math.max(b.cur, start), n - 1); }
  else {
    const t = doc.nodes[doc.rootId];
    if (t && t.messages.length) { const r = segRange(t); return { start: r.start, end: r.end, existing: t }; }
  }
  const existing = trunksOf().find(t => t.seg ? (t.seg.start === start && t.seg.end === end)
                                              : (t.messages.length > 0 && start === 0 && end === n - 1)) || null;
  return { start, end, existing };
}
/** 進入(或建立)這一段的主幹 */
function enterSegment() {
  const r = nextSegRange();
  let t = r.existing;
  if (!t) {
    t = trunksOf().find(x => !x.seg && !x.messages.length);       // 新文本預留的空主幹:直接用它
    if (t) t.seg = { start: r.start, end: r.end };
    else {
      const id = uid();
      t = doc.nodes[id] = { id, parentId: null, type: 'trunk', title: '主幹', seg: { start: r.start, end: r.end },
                            quote: '', originMsgId: null, status: 'open', messages: [] };
    }
  }
  doc.rootId = t.id;
  currentNodeId = t.id;
  segShowAll = false;
  return t;
}
/** 其他已對話過、還沒有(或已過時)摘要的段落:各整理 2–3 句,新的一段開場時給讀伴參考(可以跳著讀,所以不只前面的段落) */
async function summarizeOtherSegments(t) {
  const todo = trunksOf().filter(x => x !== t && x.messages.some(m => m.role === 'user') && !(x.summary && x.sumN === respCount(x)));
  await Promise.all(todo.map(async x => {
    try {
      x.summary = (await callApi({ mode: 'segment_summary', docTitle: doc.title, docText: doc.text,
        seg: { start: segRange(x).start, end: segRange(x).end }, messages: buildContext(x) })).trim();
      x.sumN = respCount(x);
    } catch (e) { /* 摘要失敗不影響開場 */ }
  }));
  if (todo.length) saveDocs();
}
/** 送給讀伴的這一段資訊:範圍、讀者在這段的筆記與重點畫記、其他已對話段落的摘要 */
function segPayload(node) {
  const t = trunkOf(node.id);
  if (!t || !t.seg) return {};
  const r = t.seg, inR = p => p != null && p >= r.start && p <= r.end;
  const paras = splitParas(doc.text);
  const notes = doc.notes.filter(x => x.source && inR(x.source.para))
    .map(x => x.content + (x.comment ? `（我的註記：${x.comment}）` : '')).slice(-20);
  const marks = (doc.highlights || []).filter(h => (h.kind || 'hl') === 'hl' && inR(h.para)).map(h => {
    const flat = paraText(paras[h.para] || '').replace(/\n/g, '');
    return typeof h.start === 'number' ? flat.slice(h.start, h.end) : String(h.text || '');
  }).filter(Boolean).slice(-20);
  const prev = trunksOf().filter(x => x !== t && x.summary).map(x => ({ range: segLabel(x), text: x.summary }));   // 依段落順序
  return { seg: { start: r.start, end: r.end }, segNotes: notes, segMarks: marks, prevSummaries: prev };
}
/** 範圍的字數(不計空白) */
const SEG_MAX_CHARS = 5000;
function segChars(a, b) { return splitParas(doc.text).slice(a, b + 1).reduce((s, p) => s + p.replace(/\s+/g, '').length, 0); }
/** 範圍超過 SEG_MAX_CHARS:提醒讀者縮小範圍(仍可直接開始) */
function warnLongSeg(r, go) {
  $('segLongSub').textContent = `這次的範圍是${rangeName(r)}，約 ${segChars(r.start, r.end).toLocaleString()} 字，`
    + `超過 ${SEG_MAX_CHARS.toLocaleString()} 字，讀伴的討論容易變得空泛。建議回到「目錄與書籤」，只選這次真正讀完的部分；沒讀的段落可以跳過，之後再另外選。`;
  $('segLongBack').onclick = () => {
    $('segLongOverlay').classList.add('hidden');
    if (stage !== 'read') showBriefingView(true, 'read');
    setReadToc(true, false);
  };
  $('segLongGo').onclick = () => { $('segLongOverlay').classList.add('hidden'); go(); };
  $('segLongOverlay').classList.remove('hidden');
}

/** 對話頁的讀本只顯示目前這一段(電腦版);segShowAll 時顯示全文、這段以外變淡 */
let segShowAll = false;
function readerSeg() {
  if (!doc || stage !== 'dialogue' || isPhoneLayout()) return null;
  const t = trunkOf(currentNodeId);
  return t && t.seg ? t.seg : null;
}

/* ---------------- 原檔 ----------------
 * 課程文本:老師上傳時保存在伺服器(orig.php)。免費試用:上傳的 PDF/docx 只存在這個瀏覽器的 IndexedDB(不上傳),
 * doc.localOrig = {name, type} 記著有存;清除瀏覽器資料或登出就沒了。 */
function origDb() {
  return new Promise((res, rej) => {
    const r = indexedDB.open('dupan-orig', 1);
    r.onupgradeneeded = () => r.result.createObjectStore('files');
    r.onsuccess = () => res(r.result);
    r.onerror = () => rej(r.error);
  });
}
async function origStore(mode, fn) {
  const db = await origDb();
  return new Promise((res, rej) => {
    const tx = db.transaction('files', mode), req = fn(tx.objectStore('files'));
    tx.oncomplete = () => { db.close(); res(req && req.result); };
    tx.onerror = () => { db.close(); rej(tx.error); };
  });
}
const origPut = (id, rec) => origStore('readwrite', st => st.put(rec, id));
const origGet = id => origStore('readonly', st => st.get(id));
const origDel = id => origStore('readwrite', st => st.delete(id)).catch(() => {});
/** 這篇有沒有原檔:'course' 伺服器上的、'local' 這個瀏覽器裡的、'' 沒有 */
function origKind() {
  if (!doc) return '';
  if (!$('btnOrig').hidden) return 'course';
  return doc.localOrig ? 'local' : '';
}
/** 另開分頁顯示原檔(PDF 用瀏覽器的檢視器;docx 轉成網頁,圖片也在) */
async function openOrig() {
  const kind = origKind();
  if (kind === 'course') { window.open($('btnOrig').href, '_blank', 'noopener'); return; }
  if (kind !== 'local') return;
  const w = window.open('', '_blank');               // 先開分頁(非同步之後再開會被擋)
  if (w) { w.opener = null; w.document.title = '原檔'; w.document.body.textContent = '載入原檔中⋯'; }
  try {
    const rec = await origGet(doc.id);
    if (!rec || !rec.blob) throw new Error('gone');
    let url;
    if (/\.pdf$/i.test(rec.name)) url = URL.createObjectURL(new Blob([rec.blob], { type: 'application/pdf' }));
    else {
      const r = await mammoth.convertToHtml({ arrayBuffer: await rec.blob.arrayBuffer() });   // 圖片轉成內嵌,保留
      const html = `<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(rec.name)}</title><style>body{max-width:820px;margin:32px auto;padding:0 20px;font:17px/1.85 "Noto Serif TC",serif;color:#2a2622;background:#fdfbf7}
img{max-width:100%;height:auto}table{border-collapse:collapse;margin:1em 0}td,th{border:1px solid #bbb;padding:4px 8px;vertical-align:top}
.note{font:13px/1.6 sans-serif;color:#8a7f73;border-bottom:1px solid #e5ded3;padding-bottom:8px;margin-bottom:20px}</style></head>
<body><div class="note">原檔：${esc(rec.name)}（僅存於此次使用的瀏覽器）</div>${r.value}</body></html>`;
      url = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
    }
    if (w) w.location.href = url; else window.open(url, '_blank');
  } catch (e) {
    if (w) w.close();
    toast(e.message === 'gone' ? '這個瀏覽器裡已經沒有這篇的原檔（可能清除過瀏覽器資料）' : '開啟原檔失敗：' + e.message, true);
    if (e.message === 'gone') { delete doc.localOrig; saveDocs(); renderReader(); }
  }
}
/** 圖說、表說的段落(「圖一：」「表 2.」「Figure 3」⋯) */
const RE_FIG_CAP = /^(?:[圖表]\s*[一二三四五六七八九十百零〇\d]+(?:[-－.．]\d+)?\s*[：:．.、\s]|(?:Figure|Fig\.|Table)\s*\d+)/i;

/* ---------------- 建立文本 ---------------- */

function createDoc(title, text) {
  const rootId = uid();
  const d = {
    id: uid(), title, text, createdAt: Date.now(), rootId,
    persona: 'guide',
    highlights: [],
    nodes: {
      [rootId]: { id: rootId, parentId: null, type: 'trunk', title: '主幹',
                  quote: '', originMsgId: null, status: 'open', messages: [] }
    },
    notes: []
  };
  docs.unshift(d);
  return d;
}

let readingCourseId = '';   // 目前開著的課程文本是在哪一門課底下開的(「回上一步」只回同一門課的文本)
function openDoc(d) {
  readingCourseId = (d && d.courseTextId && course && course.courseId) ? course.courseId : '';
  doc = d;
  if (!doc.persona) doc.persona = 'guide';        // 相容舊資料
  if (doc.persona === 'ta') doc.persona = 'explorer';
  if (!doc.highlights) doc.highlights = [];
  if (!doc.bm) doc.bm = { head: -1, cur: -1 };     // 書籤:這次讀完的範圍,head 起點、cur 終點(段落索引,-1 = 沒有)
  currentNodeId = d.rootId;
  bmPendingK = -1;                                  // 上一篇選到一半的書籤起點不帶過來
  pendingQuote = ''; pendingQuoteMarks = [];
  noteDraftOpen = false; noteDraft = { title: '', text: '', editId: null };   // 手寫筆記草稿屬於上一篇,不帶過來
  // 已開始討論或已看過導讀的舊文本，不再要求重讀
  readerReadDone = !!(d.briefing && d.briefing.done) || d.nodes[d.rootId].messages.length > 0;
  d.openedAt = Date.now();   // 書架依「上次閱讀」排序
  saveDocs();
  $('topDocTitle').textContent = `《${d.title}》`;
  // 「看原檔」:課程文本且老師上傳時有保存 pdf/docx 才出現(另開分頁,PDF 用瀏覽器檢視、docx 轉成網頁)
  const orig = $('btnOrig');
  orig.hidden = !(d.courseTextId && d.orig && course && course.courseId);
  if (!orig.hidden) orig.href = `orig.php?view=1&c=${encodeURIComponent(course.courseId)}&t=${encodeURIComponent(d.courseTextId)}`;
  syncDocSwitchTitle();
  $('uploadOverlay').classList.add('hidden');
  closeShelf();
  syncPersonaPills();
  renderAll();
  const root = d.nodes[d.rootId];
  const started = !!(d.briefing && d.briefing.done) || root.messages.length > 0;
  const saved = isPhoneLayout() ? null : loadPos(d.id);
  if (saved && saved.stage === 'read') {
    showBriefingView(true, 'read');          // 上次停在閱讀頁：回到那裡，位置、書籤、筆記都還在
  } else if (!started) {
    generateBriefing(false);                 // 新文本：先進導讀頁，之後閱讀、再開始對話
  } else {
    showBriefingView(false);
    if (root.messages.length === 0) requestReply(root);
  }
}

/** 讀本標題(兼「更換文本」入口)的提示:完整標題＋點擊後的動作 */
function syncDocSwitchTitle() {
  const action = $('changeDocLabel').textContent;   // 「更換文本」或課程版的「返回文本清單」
  $('btnChangeDoc').title = doc ? `《${doc.title}》・點擊${action}` : action;
}
/** 使用者要求「減少動態效果」時,捲動改為直接跳到位置 */
function scrollMotion() {
  return window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
}

/* ---------------- 畫面渲染 ---------------- */

function renderAll() {
  renderReader();
  renderChat();
  renderTree();
  renderNotes();
}

function splitParas(text) {
  // 優先以空行分段（段內換行保留，詩歌不受影響）;沒有空行才退回單一換行
  let paras = text.split(/\n\s*\n/).map(s => s.trim()).filter(Boolean);
  if (paras.length < 2) paras = text.split(/\n/).map(s => s.trim()).filter(Boolean);
  return paras;
}

/*
 * 讀本上的標記(存在 doc.highlights,一個陣列放四種):
 *   hl 重點畫記(淡黃底色)、note 存成筆記(綠色虛底線)、quote 引至對話(黑色底線)、branch 節外生枝(紅色底線)
 *   位置以「段落文字去掉換行後」的字元位移記錄 {para, start, end};可以互相重疊,顏色與底線直接疊加
 *   舊資料只有 {para, text}(當時只有螢光筆),顯示時用文字找回位置
 */
const MARK_KINDS = ['hl', 'note', 'quote', 'branch'];
const MARK_TIP = { hl: '重點畫記(點擊移除)', note: '筆記(點擊開啟筆記)', quote: '引用', branch: '分支(點擊前往分支)' };
const markKind = h => h.kind || 'hl';
/** 只算「螢光筆」的標記(匯出、教師端的「螢光筆標記」用) */
const hlMarks = () => (doc.highlights || []).filter(h => markKind(h) === 'hl');

/** 標記是否仍有效:筆記或分支被刪掉後,對應的標記就不再顯示 */
function markAlive(h) {
  if (h.noteId) return doc.notes.some(n => n.id === h.noteId);
  if (h.nodeId) return !!doc.nodes[h.nodeId];
  return true;
}

/** 一段文字套上標記:逐字算出每個位置有哪些標記,相同的連成一段 <span> */
/** 表格段落:兩列以上、每列都有「 | 」(docx 表格與 PDF 表格解析出來的格式) */
function isTablePara(raw) { const ls = raw.split('\n'); return ls.length >= 2 && ls.every(l => l.includes(' | ')); }
/** 段落 → HTML(含標記)。表格段落畫成表格:欄位間的「 | 」留作隱藏文字,畫記與選取的字元位置才和原文一致;
 *  某列欄數較少(合併儲存格)就在前面補空格對齊 */
function paraWithMarks(raw, i, asTable) {
  const flat = raw.replace(/\n/g, '');
  const marks = [];
  (doc.highlights || []).forEach(h => {
    if (h.para !== i || !markAlive(h)) return;
    let s = h.start, e = h.end;
    if (typeof s !== 'number') {                      // 舊資料:用文字找位置
      const t = String(h.text || '').replace(/\n/g, '').replace(/…$/, '');
      s = t ? flat.indexOf(t) : -1; e = s + t.length;
    }
    if (s >= 0 && e > s) marks.push({ s, e: Math.min(e, flat.length), h });
  });
  if (!marks.length && !asTable) return esc(raw).replace(/\n/g, '<br>');
  const keyAt = (pos) => {
    const on = marks.filter(m => pos >= m.s && pos < m.e);
    if (!on.length) return '';
    const kinds = MARK_KINDS.filter(k => on.some(m => markKind(m.h) === k));
    const ids = k => on.filter(m => markKind(m.h) === k).map(m => m.h.noteId || m.h.nodeId || m.h.id).join(',');
    return kinds.map(k => `${k}:${ids(k)}`).join('|');
  };
  const open = (key) => {
    const parts = key.split('|').map(x => x.split(':'));
    const cls = parts.map(([k]) => 'mk-' + k).join(' ');
    const data = parts.map(([k, v]) => ` data-${k}="${esc(v)}"`).join('');
    const tip = parts.map(([k]) => MARK_TIP[k]).join('、');
    return `<span class="mk ${cls}"${data} title="${tip}">`;
  };
  const cells = asTable ? raw.split('\n').map(l => l.split(' | ').length) : [];
  const maxCells = Math.max(0, ...cells);
  let row = 0;
  const rowOpen = () => '<span class="tr">' + '<span class="td"></span>'.repeat(maxCells - cells[row]) + '<span class="td">';
  let out = asTable ? '<span class="tb">' + rowOpen() : '', cur = '', pos = 0;
  const cut = (mid) => { if (cur) out += '</span>'; out += mid; if (cur) out += open(cur); };   // 換列、換格時,畫記的 span 先關再開
  for (let j = 0; j < raw.length;) {
    if (raw[j] === '\n') {
      if (asTable) { row++; cut('</span></span>' + rowOpen()); } else out += '<br>';
      j++; continue;
    }
    if (asTable && raw.startsWith(' | ', j)) { cut('<span class="tsep"> | </span></span><span class="td">'); j += 3; pos += 3; continue; }
    const ch = String.fromCodePoint(raw.codePointAt(j));
    const k = keyAt(pos);
    if (k !== cur) { if (cur) out += '</span>'; if (k) out += open(k); cur = k; }
    out += esc(ch); pos += ch.length; j += ch.length;
  }
  if (cur) out += '</span>';
  if (asTable) out += '</span></span></span>';
  return out;
}

/** 目前選取範圍落在讀本哪些段落、各段的字元位置(跨段選取時每段各一筆) */
function selRanges(range) {
  const out = [];
  $('readerBody').querySelectorAll('p.rp').forEach(p => {
    if (!range.intersectsNode(p)) return;
    const off = (node, o) => { const r = document.createRange(); r.selectNodeContents(p); r.setEnd(node, o); return r.toString().length; };
    const len = p.textContent.length;
    const start = p.contains(range.startContainer) ? off(range.startContainer, range.startOffset) : 0;
    const end = p.contains(range.endContainer) ? off(range.endContainer, range.endOffset) : len;
    if (end > start) out.push({ para: parseInt(p.dataset.para, 10), start, end, text: p.textContent.slice(start, end) });
  });
  return out;
}

/** 某段落裡所有重點畫記的實際位置(舊資料用文字找回位置) */
function hlSpans(para) {
  const flat = paraText(splitParas(doc.text)[para] || '').replace(/\n/g, '');
  return (doc.highlights || []).filter(h => markKind(h) === 'hl' && h.para === para).map(h => {
    let s = h.start, e = h.end;
    if (typeof s !== 'number') {
      const t = String(h.text || '').replace(/\n/g, '').replace(/…$/, '');
      s = t ? flat.indexOf(t) : -1; e = s + t.length;
    }
    return { h, s, e };
  }).filter(x => x.s >= 0 && x.e > x.s);
}
/** 選取的每一個字都已經畫過重點 → 這次按下去是「取消」 */
function rangesAllHighlighted(ranges) {
  return ranges.length > 0 && ranges.every(r => {
    const spans = hlSpans(r.para);
    for (let i = r.start; i < r.end; i++) if (!spans.some(x => i >= x.s && i < x.e)) return false;
    return true;
  });
}
/** 從畫記中扣掉選取的範圍:畫記比選取長時,只留下選取以外的前後兩截 */
function removeHlInRanges(ranges) {
  ranges.forEach(r => {
    hlSpans(r.para).forEach(({ h, s, e }) => {
      if (e <= r.start || s >= r.end) return;
      doc.highlights = doc.highlights.filter(x => x !== h);
      if (s < r.start) doc.highlights.push({ id: uid(), kind: 'hl', para: r.para, start: s, end: r.start });
      if (e > r.end) doc.highlights.push({ id: uid(), kind: 'hl', para: r.para, start: r.end, end: e });
    });
  });
}

function addMarks(ranges, kind, extra) {
  (ranges || []).forEach(r => doc.highlights.push(Object.assign({ id: uid(), kind }, r, extra || {})));
}

/* 以 1–3 個 # 開頭的段落是章節標題(docx 上傳時自動產生,教師也可在後台自己加)。
   標記的位置以「顯示出來的文字」計,所以標題要先去掉 # 再算 */
const RE_HEAD = /^(#{1,6})\s+([^]*)$/;
function paraView(raw) {
  const m = raw.match(RE_HEAD);
  return m ? { kind: 'h', level: Math.min(m[1].length, 3), text: m[2].trim() } : { kind: 'p', text: raw };
}
const paraText = raw => paraView(raw).text;

function renderReader() {
  const body = $('readerBody');
  const paras = splitParas(doc.text);
  // 標記方式改由選取工具列與欄底圖例說明,讀本裡只放原文
  let html = '';
  const seg = readerSeg();
  const segTxt = seg ? rangeLabel(seg) : '';
  if (seg && (seg.start > 0 || seg.end < paras.length - 1)) {
    html += `<div class="seg-bar" style="--seg:${segColor(trunkOf(currentNodeId))}">這次對話的範圍：${esc(rangeName(seg))}<button type="button" class="link-btn" id="btnSegAll">${segShowAll ? '只顯示這一段' : '顯示全文'}</button></div>`;
  }
  const okind = origKind();
  const figBtn = okind ? `<button type="button" class="fig-orig" aria-label="在原檔看這張圖表" title="在原檔看這張圖表${okind === 'local' ? '（原檔僅存於此次使用的瀏覽器）' : ''}"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-9 9"/></svg></button>` : '';
  paras.forEach((p, i) => {
    const out = seg && (i < seg.start || i > seg.end);
    if (out && !segShowAll) return;
    const v = paraView(p);
    const cls = (v.kind === 'h' ? ` rh rh${v.level}` : '') + (out ? ' out-seg' : '');
    const fig = figBtn && v.kind === 'p' && RE_FIG_CAP.test(v.text) ? figBtn : '';
    const tbl = v.kind === 'p' && isTablePara(v.text);
    html += `<p class="rp${cls}${fig ? ' fig-cap' : ''}${tbl ? ' rtable' : ''}" data-no="${i + 1}" data-para="${i}">${paraWithMarks(v.text, i, tbl)}${fig}</p>`;
  });
  html += seg && !segShowAll && seg.end < paras.length - 1
    ? `<div class="reader-end">這一段到此（${segTxt}）・全文共 ${paras.length} 段</div>`
    : `<div class="reader-end">全文完・共 ${paras.length} 段</div>`;
  body.innerHTML = html;
  if ($('btnSegAll')) $('btnSegAll').onclick = () => { segShowAll = !segShowAll; renderReader(); };
  body.querySelectorAll('.fig-orig').forEach(b => b.onclick = (e) => { e.stopPropagation(); openOrig(); });
  renderChapterSel(paras);
  paintBookmarks();
  renderToc();
  if (rf.q) rfRun();                     // 重畫後搜尋的標亮要重做(Range 綁在舊節點上)

  requestAnimationFrame(checkReaderScroll);
  // 點擊標記:螢光筆 → 移除(重疊時移除最新的一筆);筆記 → 開啟筆記;分支 → 前往分支
  body.querySelectorAll('p.rp span.mk').forEach(m => {
    m.onclick = () => {
      const sel = window.getSelection();
      if (sel && !sel.isCollapsed) return;           // 正在選取文字,不是點擊
      if (m.dataset.hl) {
        const last = m.dataset.hl.split(',').pop();
        doc.highlights = doc.highlights.filter(h => h.id !== last);
        saveDocs(); renderReader(); toast('已移除重點畫記');
      } else if (m.dataset.note) {
        const nid = m.dataset.note.split(',').pop();
        if (stage === 'read') openNotePop(nid, m.getBoundingClientRect());
        else openNoteBubble(nid, false);
      } else if (m.dataset.branch) {
        switchNode(m.dataset.branch.split(',').pop());
        focusPanel('chat');
      }
    };
  });
}

/** 篇章下拉選單:文本有「# 標題」才出現,選了就捲到該段 */
function renderChapterSel(paras) {
  const sel = $('chapterSel');
  const hs = [];
  paras.forEach((p, i) => { const v = paraView(p); if (v.kind === 'h') hs.push({ i, level: v.level, text: v.text }); });
  sel.hidden = hs.length === 0;
  // 「≡」選單裡的目錄：和閱讀頁的目錄同一份內容、同樣的步驟樣式（沒有小標題的文本依段落分節）
  const items = tocItems();
  $('rmChToggle').disabled = !items.length;
  $('rmChNote').textContent = items.length ? `（${items.length}）` : '';
  if (!items.length) rmChaptersOpen(false);
  $('rmChapterList').innerHTML = items.map((it, k) => {
    const label = rangeLabel(it);
    return `<button type="button" role="menuitem" class="rm-toc" data-para="${it.start}"><span class="toc-n">${k + 1}</span><span class="toc-t">${esc(it.title)}<small>${label}</small></span></button>`;
  }).join('');
  if (!hs.length) return;
  sel.innerHTML = '<option value="">目錄⋯</option>'
    + hs.map(h => `<option value="${h.i}">${'　'.repeat(h.level - 1)}${esc(trunc(h.text, 40))}</option>`).join('');
}

/* ---------------- 讀本搜尋(關鍵字逐一標亮;輸入數字跳到該段) ---------------- */

const rf = { matches: [], cur: -1, q: '' };
const HL_OK = typeof CSS !== 'undefined' && CSS.highlights && typeof Highlight !== 'undefined';   // 舊瀏覽器只跳段、不標亮
function rfOpen() {
  $('readerFind').hidden = false;
  focusPanel('reader');
  $('rfInput').focus(); $('rfInput').select();
}
function rfClose() { $('readerFind').hidden = true; $('rfInput').value = ''; rfClear(); }
function rfClear() {
  rf.matches = []; rf.cur = -1; rf.q = '';
  $('rfCount').textContent = '';
  if (HL_OK) { CSS.highlights.delete('rf-all'); CSS.highlights.delete('rf-cur'); }
}
function rfRun() {
  const q = $('rfInput').value.trim();
  rfClear(); rf.q = q;
  if (!q) return;
  const paras = $('readerBody').querySelectorAll('p.rp');
  if (/^[0-9０-９]+$/.test(q)) {
    const n = parseInt(q.replace(/[０-９]/g, d => String.fromCharCode(d.charCodeAt(0) - 0xFEE0)), 10);
    if (n >= 1 && n <= paras.length) { $('rfCount').textContent = `第 ${n} 段`; jumpToNoteSource({ para: n - 1 }); }
    else $('rfCount').textContent = `共 ${paras.length} 段`;
    return;
  }
  const ql = q.toLowerCase();
  paras.forEach(p => {
    // 段落裡的文字節點拼成一條字串找符合處,再換算回節點位置(不動 DOM,畫記不受影響)
    const walker = document.createTreeWalker(p, NodeFilter.SHOW_TEXT);
    const nodes = []; let txt = '', t;
    while ((t = walker.nextNode())) { nodes.push({ node: t, start: txt.length }); txt += t.nodeValue; }
    let low = txt.toLowerCase();
    if (low.length !== txt.length) low = txt;
    let idx = low.indexOf(ql);
    while (idx >= 0) {
      const at = pos => { let last = nodes[0]; for (const n of nodes) { if (n.start <= pos) last = n; else break; } return [last.node, pos - last.start]; };
      const r = document.createRange(); r.setStart(...at(idx)); r.setEnd(...at(idx + q.length));
      rf.matches.push({ p, range: r });
      idx = low.indexOf(ql, idx + q.length);
    }
  });
  if (HL_OK && rf.matches.length) CSS.highlights.set('rf-all', new Highlight(...rf.matches.map(m => m.range)));
  rfGo(0);
}
function rfGo(i) {
  const n = rf.matches.length;
  if (!n) { $('rfCount').textContent = '沒有符合的內容'; return; }
  rf.cur = ((i % n) + n) % n;
  const m = rf.matches[rf.cur];
  $('rfCount').textContent = `${rf.cur + 1} / ${n}`;
  if (HL_OK) CSS.highlights.set('rf-cur', new Highlight(m.range));
  scrollReaderTo(m.range.startContainer.parentElement || m.p);
  if (!HL_OK) { m.p.style.transition = 'background .3s'; m.p.style.background = 'var(--tip)'; setTimeout(() => { m.p.style.background = ''; }, 1400); }
}

/* ---------------- 對話裡的引文對位到讀本段落 ---------------- */

let flatCache = { key: '', flats: [] };
const normQ = s => String(s).replace(/[\s\p{P}\p{S}]/gu, '');   // 去掉空白與標點再比對,模型改了標點也找得到
/** 各段去掉空白與標點後的文字(依文本快取) */
function readerFlats() {
  const key = doc ? doc.id + ':' + doc.text.length : '';
  if (flatCache.key !== key) flatCache = { key, flats: splitParas(doc.text).map(normQ) };
  return flatCache.flats;
}
/** 引文在哪一段:先整句找;有「⋯⋯」就拆開找最長的一截;再不行取前後 10 字容錯。找不到回 -1 */
function findQuotePara(q) {
  const flats = readerFlats();
  const pieces = String(q).replace(/【\d+】/g, '').split(/[⋯…]+|\.{3,}/).map(normQ).filter(s => s.length >= 4)
    .sort((a, b) => b.length - a.length);
  const find = s => flats.findIndex(f => f.includes(s));
  for (const p of pieces) { const i = find(p); if (i >= 0) return i; }
  if (pieces.length) for (const s of [pieces[0].slice(0, 10), pieces[0].slice(-10)]) {
    if (s.length >= 6) { const i = find(s); if (i >= 0) return i; }
  }
  return -1;
}
/** 讀伴訊息的 HTML:「第N段」與找得到出處的「引文」變成可點擊,引文後標上段落號 */
function quoteLinks(html) {
  if (!doc) return html;
  const total = splitParas(doc.text).length;
  const link = (i, inner) => `<a class="q-link" data-para="${i}" title="在讀本顯示第 ${i + 1} 段">${inner}</a>`;
  html = html.replace(/第\s*([0-9０-９]{1,3})\s*段/g, (m, d) => {
    const n = parseInt(d.replace(/[０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)), 10);
    return n >= 1 && n <= total ? link(n - 1, m) : m;
  });
  return html.replace(/「([^「」<>]{4,}?)」/g, (m, inner, pos) => {
    const plainQ = inner.replace(/&[a-z#0-9]+;/g, ' ');
    if (normQ(plainQ).length < 8) return m;        // 「配得幸福」這類提到的詞語不是引文,不對位
    const i = findQuotePara(plainQ);
    if (i < 0) return m;
    // 讀伴在引文前後已自己寫了「第N段」就不再重複標號
    const plain = s => s.replace(/<[^>]+>/g, '');
    const before = plain(html.slice(Math.max(0, pos - 120), pos)), after = plain(html.slice(pos + m.length, pos + m.length + 40));
    const said = new RegExp(`第\\s*${i + 1}\\s*段[^「]*$`).test(before) || new RegExp(`^[^「]{0,8}第\\s*${i + 1}\\s*段`).test(after);
    return `「${link(i, inner)}」` + (said ? '' : `<span class="q-no">第${i + 1}段</span>`);
  });
}

function nodePath(nodeId) {
  const path = [];
  let n = doc.nodes[nodeId];
  while (n) { path.unshift(n); n = n.parentId ? doc.nodes[n.parentId] : null; }
  return path;
}

function renderChat() {
  const node = doc.nodes[currentNodeId];

  // 路徑列:「對話／主幹」或「對話／主幹 › 分支:名稱」
  const crumbs = $('crumbs');
  crumbs.innerHTML = '<span class="c-sep">／</span>';
  nodePath(currentNodeId).forEach((n, i) => {
    if (i > 0) {
      const sep = document.createElement('span');
      sep.className = 'c-sep'; sep.textContent = '›';
      crumbs.appendChild(sep);
    }
    const label = n.type === 'trunk' ? trunkName(n) : '分支：' + n.title;
    if (n.id === currentNodeId) {
      const cur = document.createElement('span');
      cur.className = 'c-cur' + (n.type === 'branch' ? ' branch' : '');
      cur.textContent = label; cur.title = label;
      crumbs.appendChild(cur);
      if (n.type === 'trunk' && n.seg) { cur.classList.add('seg-c'); cur.style.setProperty('--seg', segColor(n)); }
    } else {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'c-link';
      b.textContent = label; b.title = label;
      b.onclick = () => switchNode(n.id);
      crumbs.appendChild(b);
    }
  });

  // 「保留分支」「收束回主幹」只在進行中的分支裡出現
  const isBranch = node.type === 'branch';
  $('branchActions').classList.toggle('on', isBranch && node.status === 'open');
  $('composerInput').placeholder = isBranch
    ? (node.messages.length === 0 ? '針對這個起點，你想追問什麼？' : '在這條分支上，把一個問題挖深…')
    : '寫下你的想法…';

  // 訊息
  const body = $('chatBody');
  body.innerHTML = '';
  if (isBranch && node.messages.length === 0 && !busy) {
    const hint = document.createElement('div');
    hint.className = 'branch-empty-hint';
    hint.innerHTML = (node.quote
      ? `這條分支從<span class="beh-q">「${esc(node.quote)}」</span>出發。`
      : '這是一條新的分支。')
      + '<br>寫下你真正想追問的問題，讀伴會帶著前面的對話脈絡，聚焦在這一點上回應。';
    body.appendChild(hint);
  }
  let seqUser = 0, seqAi = 0;
  const lastUserIdx = node.messages.map(m => m.role).lastIndexOf('user');
  node.messages.forEach((m, i) => {
    const seq = m.role === 'user' ? ++seqUser : (m.role === 'ai' ? ++seqAi : null);
    body.appendChild(renderMsg(m, node, i === node.messages.length - 1, seq, i === lastUserIdx));
  });
  if (busy) body.appendChild(typingEl());
  body.scrollTop = body.scrollHeight;
  // 最後一則是讀伴的回應時,捲到這則回應的開頭(回應很長時不必再往上拉);回應短就和捲到底一樣
  const lastMsg = node.messages[node.messages.length - 1];
  const lastEl = body.lastElementChild;
  if (!busy && lastMsg && lastMsg.role === 'ai' && lastEl) {
    const top = lastEl.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop - 12;
    body.scrollTop = Math.max(0, top);
  }
}

/** 從 AI 訊息中抽出編號選項（1. / 1、 / ①) */
function extractOptions(content) {
  const opts = [];
  content.split('\n').forEach(ln => {
    const m = ln.trim().match(/^(?:[1-9１-９][.、).)]|[①②③④⑤⑥])\s*(.+)$/);
    if (m && m[1].trim()) opts.push(m[1].trim());
  });
  return (opts.length >= 2 && opts.length <= 6) ? opts : [];
}

function renderMsg(m, node, isLast, seq, isLastUser) {
  if (m.role === 'merge') {
    const card = document.createElement('div');
    card.className = 'merge-card';
    card.innerHTML = `<div class="m-head">⤷ 分支收束 · ${esc(m.branchTitle || '')}${esc(mergeFrom(m))}</div>
      <div>${mdLite(m.content)}</div>
      <button class="m-link">查看這條分支</button>`;
    card.querySelector('.m-link').onclick = () => switchNode(m.branchId);
    return card;
  }
  const wrap = document.createElement('div');
  wrap.className = 'msg ' + (m.role === 'user' ? 'user' : 'ai');
  const quoteHtml = m.quote ? `<div class="msg-quote">${esc(m.quote)}</div>` : '';
  const finalTag = m.final ? '<div class="msg-final">— 你的結語，讀伴不回覆 —</div>' : '';
  // 最後一則 AI 訊息若含編號選項，泡泡內文抽掉選項行（避免與下方按鈕重複）
  let displayContent = m.content;
  const willShowOpts = (m.role === 'ai' && isLast && !busy) ? extractOptions(m.content) : [];
  if (willShowOpts.length) {
    const optLine = /^(?:[1-9１-９][.、).)]|[①②③④⑤⑥])\s*.+$/;
    displayContent = m.content.split('\n').filter(ln => !optLine.test(ln.trim())).join('\n')
      .replace(/\n{3,}/g, '\n\n').trim();
  }
  const pKey = m.role === 'ai' ? (m.persona || null) : null;
  const pInfo = pKey ? (PERSONA_INFO[pKey] || null) : null;
  const who = m.role === 'ai' ? `<span class="msg-who">${pInfo ? personaIcon(pKey) : ''}讀伴${pInfo ? '・' + pInfo.name : ''}</span>` : '';
  wrap.innerHTML = `
    ${who}${m.role === 'user' ? quoteHtml : ''}
    <div class="bubble">${m.role === 'ai' ? quoteHtml + quoteLinks(mdLite(displayContent)) : esc(m.content)}${finalTag}</div>
    <div class="msg-actions">
      ${m.role === 'user' && isLastUser && !m.final && !busy ? '<button type="button" data-act="edit" title="把這則放回輸入框修改後重新送出（讀伴對它的回覆會一併移除）">修改</button>' : ''}
      <button type="button" data-act="branch" title="從這則訊息開出一條分支">節外生枝</button>
      <button type="button" data-act="note" title="把這則訊息存成筆記">存成筆記</button>
    </div>`;

  // 最後一則 AI 訊息：抽出編號選項變成可點按鈕
  if (willShowOpts.length) {
    const opts = willShowOpts;
    {
      const box = document.createElement('div');
      box.className = 'quick-opts';
      opts.forEach((opt, i) => {
        const b = document.createElement('button');
        b.innerHTML = `<span class="opt-no">${i + 1}</span>${esc(opt)}`;
        b.onclick = () => {
          if (/自己提問|自行提問|我想自己/.test(opt)) {
            $('composerInput').focus();
            toast('好，把你的問題打進輸入框吧');
            return;
          }
          $('composerInput').value = opt;
          sendMessage();
        };
        box.appendChild(b);
      });
      wrap.insertBefore(box, wrap.querySelector('.msg-actions'));
    }
  }
  const editBtn = wrap.querySelector('[data-act=edit]');
  if (editBtn) editBtn.onclick = () => editLastMessage(node, m.id);
  wrap.querySelector('[data-act=branch]').onclick = () =>
    createBranch(node.id, trunc(m.quote || m.content, 60), m.id);
  wrap.querySelector('[data-act=note]').onclick = () => {
    const note = addNote((m.quote ? `「${m.quote}」\n` : '') + m.content,
      { nodeId: node.id, label: `${nodeLabel(node)} · ${m.role === 'user' ? '你' : '讀伴'}` });
    if (note) openNoteBubble(note.id, true);
  };
  return wrap;
}

function typingEl() {
  const wrap = document.createElement('div');
  wrap.className = 'msg ai';
  wrap.innerHTML = `<span class="msg-who">讀伴</span><div class="bubble"><span class="typing" aria-label="讀伴回覆中"><i></i><i></i><i></i></span></div>`;
  return wrap;
}

/* ---------------- 閱讀前導讀 ---------------- */

/** 切換閱讀階段:導讀(讀本|導讀|筆記)或對話(讀本|對話|對話樹+筆記) */
/* ---------------- 四個階段：導讀 → 閱讀 → 對話 → 回顧 ----------------
 * 電腦與平板：導讀、閱讀各是置中、背後暗色的全版畫面；對話沿用原本的三欄工作區。
 * 手機（≤760px 觸控）沿用手機版原有的畫面（見 mobile.js），不走這套。
 * 不另外複製元件：導讀區塊、讀本、筆記欄在切換階段時「搬」到對應畫面，原本的程式與事件都繼續有效。 */
const PHONE_MQ = '(max-width: 760px) and (pointer: coarse)';
const isPhoneLayout = () => window.matchMedia(PHONE_MQ).matches;
let stage = 'dialogue';                                   // 'guide' | 'read' | 'dialogue'
const MOVABLE = ['briefingView', 'panelReader', 'panelSide', 'btnBriefDone', 'briefGateHint'];
const HOMES = {};
function stashHomes() {
  MOVABLE.forEach(id => {
    const el = $(id);
    if (el && !HOMES[id]) { HOMES[id] = document.createComment('home:' + id); el.before(HOMES[id]); }
  });
}
function sendHome(id) { const el = $(id), m = HOMES[id]; if (el && m && m.parentNode) m.after(el); }

/** 工作區的階段旗標：舊程式用 stage-guide／briefing-mode 判斷「還沒開始對話」，導讀與閱讀都算 */
function applyStageFlags(pre) {
  const ws = $('workspace');
  ws.classList.toggle('stage-guide', pre);
  ws.classList.toggle('stage-dialogue', !pre);
  $('panelChat').classList.toggle('briefing-mode', pre);   // 舊程式以此判斷是否在導讀
  $('midTitle').textContent = pre ? '導讀' : '對話';
  $('midStripLabel').textContent = pre ? '導讀' : '對話';
  $('btnExpandMid').setAttribute('aria-label', pre ? '展開導讀' : '展開對話');
  $('sideStripLabel').textContent = pre ? '筆記' : '對話樹與筆記';
  $('jumpMid').textContent = pre ? '導讀' : '對話';               // 手機切換列跟著階段改名
  $('jumpSide').textContent = pre ? '筆記' : '對話樹・筆記';
  $('panelSide').setAttribute('aria-label', pre ? '筆記' : '對話樹與筆記');
}

/** 頂部「導讀｜閱讀｜對話｜回顧」的標示；回顧視窗開著時標示「回顧」 */
function syncStageTabs(reviewOn) {
  const has = !!doc;
  const mark = (id, on) => {
    const b = $(id), v = has && on && !reviewOn;
    b.classList.toggle('on', v);
    b.setAttribute('aria-pressed', String(v));
  };
  mark('btnBriefing', stage === 'guide');
  mark('btnReading', stage === 'read');
  mark('btnDialogue', stage === 'dialogue');
  $('btnSummary').classList.toggle('on', !!reviewOn);
  $('btnSummary').setAttribute('aria-pressed', String(!!reviewOn));
}

/** 切換階段。show=true 是導讀階段（舊呼叫），to 可直接指定 'guide' | 'read' | 'dialogue' */
function showBriefingView(show, to) {
  const target = to || (show ? 'guide' : 'dialogue');
  if (isPhoneLayout()) {                                   // 手機：維持原本的兩個畫面
    stage = target === 'dialogue' ? 'dialogue' : 'guide';
    applyStageFlags(stage === 'guide');
    syncStageTabs(false);
    applyLayout();
    if (stage === 'guide') {
      const done = doc && doc.briefing && doc.briefing.done;
      // 真的有對話內容才說「返回」；按過開始但對話還是空的（例如讀伴沒回應），就仍是「開始對話」
      const talked = done && Object.values(doc.nodes).some(n => n.messages.length > 0);
      $('btnBriefDone').textContent = '對話 →';
    }
    updateBriefGate();
    return;
  }
  enterStage(target);
}

function enterStage(target) {
  stashHomes();
  if (stage === 'read') savePos();                         // 離開閱讀頁前記下位置
  if (typeof closeNotePop === 'function') closeNotePop();
  if ($('lookupPanel').classList.contains('open')) $('btnLookupClose').onclick();   // 切換階段時查詢欄馬上收起，要用再按「簡易查詢」
  stage = target;
  document.body.dataset.stage = target;
  MOVABLE.forEach(sendHome);                               // 先全部放回原位，再依階段搬
  $('guideView').classList.toggle('hidden', target !== 'guide');
  $('readView').classList.toggle('hidden', target !== 'read');
  $('workspace').classList.toggle('stage-away', target !== 'dialogue');
  if (target === 'guide') {
    $('guideMain').appendChild($('briefingView'));
    $('gvTitle').textContent = doc ? `《${doc.title}》導讀` : '導讀';
    $('gvBody').scrollTop = 0;
  } else if (target === 'read') {
    $('readSlot').appendChild($('panelReader'));
    $('readNotesSlot').appendChild($('panelSide'));
    $('readGo').append($('btnBriefDone'));         // 「開始對話」放在上方列最右邊，和導讀頁的「開始閱讀」同一個位置
    setReadNotes(false);
  }
  applyStageFlags(target !== 'dialogue');
  if (doc) renderReader();                                 // 對話頁只顯示這一段、閱讀頁顯示全文
  if (target === 'dialogue') applyLayout();
  else { $('panelReader').style.flex = ''; $('panelSide').style.flex = ''; }
  syncStageTabs(false);
  updateBriefGate();
  if (doc) {
    if (target === 'read') { paintBookmarks(); restoreReadPos(); initReadToc(); }
    savePos(true);
  }
}

/** 對話門檻：電腦版是「放了書籤」（或已經開始過討論）；手機沿用「讀到文末」 */
function bmOk() { return !!(doc && doc.bm && doc.bm.head >= 0 && doc.bm.cur >= 0); }
function updateBriefGate() {
  if (!doc) return;
  const started = !!(doc.briefing && doc.briefing.done);
  const phone = isPhoneLayout();
  const locked = !started && (phone ? !readerReadDone : !bmOk());
  $('btnBriefDone').disabled = locked;
  $('briefGateHint').textContent = phone ? '請先放書籤，標出讀到哪裡，才能對話' : '先在「≡」選單的「目錄與書籤」選好這次讀完的範圍，才能對話';
  $('briefGateHint').style.display = locked ? '' : 'none';
  $('btnBriefDone').title = locked ? $('briefGateHint').textContent : '';
  updateBmStatus();
}

function checkReaderScroll() {
  if (!doc) return;
  const el = $('readerBody');
  if (!el) return;
  const shortEnough = el.scrollHeight <= el.clientHeight + 10;
  const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
  if (!isPhoneLayout()) {
    // 電腦：短文在閱讀頁捲到文末、還沒選範圍，就自動選全文，讓讀者可以直接進入討論(長文要讀者自己選)
    if (stage === 'read' && !shortEnough && atBottom && !bmOk()) {
      const n = splitParas(doc.text).length;
      if (n && segChars(0, n - 1) <= SEG_MAX_CHARS) { setBmRange(0, n - 1); toast('讀到文末了，已把全文標為這次對話的範圍'); }
    }
    return;
  }
  if (readerReadDone) return;
  if (shortEnough || atBottom) {
    readerReadDone = true;
    updateBriefGate();
    if (doc.briefing && !doc.briefing.done) toast('讀完整篇了，可以開始對話');
  }
}

/* ---------------- 書籤：這次讀完的範圍（分段共讀） ----------------
 * head 起點、cur 終點(段落索引,-1 = 沒有),在「目錄與書籤」以整節為單位選取;
 * 範圍怎麼變成一段對話見上方「分段共讀」的 nextSegRange/enterSegment。舊資料可能還有 from/talked,已不使用。 */
function bmData() {
  if (!doc.bm) doc.bm = { head: -1, cur: -1 };
  if (doc.bm.head == null) doc.bm.head = -1;               // head 讀者自己點的「起點」；cur 是「終點」
  return doc.bm;
}
/** 這次讀完的範圍(段落索引);-1, -1 = 清除 */
function setBmRange(start, end) {
  const b = bmData();
  b.head = start; b.cur = end;
  saveDocs();
  paintBookmarks();
  updateBriefGate();
  savePos();
}
/** 目錄的書籤圖示:第一次點=起點(單獨一節也算一個範圍),再點另一節=終點;再點同一節=取消 */
let bmPendingK = -1;
function tocBmClick(k) {
  const it = tocData[k], b = bmData();
  if (!it) return;
  if (bmPendingK >= 0 && bmPendingK !== k) {
    const a = Math.min(bmPendingK, k), z = Math.max(bmPendingK, k);
    bmPendingK = -1;                                       // 先清掉,範圍說明才不會還寫「已選起點」
    setBmRange(tocData[a].start, tocData[z].end);
  } else if (bmPendingK === k || (b.head === it.start && b.cur === it.end)) {
    bmPendingK = -1;
    setBmRange(-1, -1);
  } else {
    bmPendingK = k;
    setBmRange(it.start, it.end);
  }
}
function paintBookmarks() {
  updateTocState();                                        // 書籤只顯示在目錄裡，讀本的文字保持乾淨
}
function bmStatusText() {
  const b = doc && doc.bm;
  if (!b || b.head < 0 || b.cur < 0) return '';
  const r = nextSegRange(), txt = rangeName(r);
  if (bmPendingK >= 0) return `已選起點：${txt}。再點另一節的書籤作為終點，或直接開始對話`;
  return r.existing ? `回到之前對話過的範圍：${txt}` : `這次對話的範圍：${txt}（約 ${segChars(r.start, r.end).toLocaleString()} 字）`;
}
/** 目錄與書籤上方的範圍說明、筆記數 */
function updateBmStatus() {
  if (!doc) return;
  $('tocStatus').textContent = bmOk() ? bmStatusText() : '';
  $('tocStatus').title = bmOk() ? '點這一行可跳到範圍開頭' : '';
  if (!isPhoneLayout()) $('btnBriefDone').textContent = '開始對話';
  const nb = $('rvNoteCount');
  if (nb) nb.textContent = doc.notes.length ? String(doc.notes.length) : '';
}

/** 讀本左側留白（書籤符號所在）：y 落在哪一段、x 是否在留白區 */
function paraAtY(y) {
  const ps = $('readerBody').querySelectorAll('p.rp');
  let lo = 0, hi = ps.length - 1;
  while (lo <= hi) {                                       // 段落由上到下排列，二分搜尋
    const mid = (lo + hi) >> 1, r = ps[mid].getBoundingClientRect();
    if (y < r.top) hi = mid - 1;
    else if (y > r.bottom + 14) lo = mid + 1;
    else return ps[mid];
  }
  return null;
}
function inBmGutter(e, p) {
  const r = p.getBoundingClientRect();
  return e.clientX < r.left - 2 && e.clientX > r.left - 80;
}
/** 畫面上最後一段完整可見的段落（「讀到這裡」的預設位置） */
function lastVisiblePara() {
  const body = $('readerBody'), ps = body.querySelectorAll('p.rp');
  if (!ps.length) return null;
  const bottom = body.getBoundingClientRect().bottom;
  let idx = 0;
  for (let i = 0; i < ps.length; i++) { if (ps[i].getBoundingClientRect().bottom <= bottom + 2) idx = i; else break; }
  return idx;
}

/* ---------------- 閱讀位置暫存：重新整理、關機重開都回到原來的地方 ---------------- */
function loadPos(id) {
  try { return JSON.parse(localStorage.getItem('dupan.pos.' + id) || 'null'); } catch (e) { return null; }
}
function savePos(keepScroll) {
  if (!doc) return;
  const prev = loadPos(doc.id) || {};
  let para = prev.para || 0, frac = prev.frac || 0;
  if (stage === 'read' && !keepScroll) {          // keepScroll:剛切換階段、位置還沒還原時,只更新階段,不要用目前的(頂端)位置蓋掉記下的位置
    const body = $('readerBody'), top0 = body.getBoundingClientRect().top;
    const ps = body.querySelectorAll('p.rp');
    for (let i = 0; i < ps.length; i++) {
      const r = ps[i].getBoundingClientRect();
      if (r.bottom - top0 > 4) { para = i; frac = Math.max(0, Math.min(1, (top0 - r.top) / Math.max(1, r.height))); break; }
    }
  }
  try { localStorage.setItem('dupan.pos.' + doc.id, JSON.stringify({ stage, para, frac, t: Date.now() })); } catch (e) {}
}
function restoreReadPos() {
  const pos = loadPos(doc.id);
  const body = $('readerBody');
  const go = () => {
    const ps = body.querySelectorAll('p.rp');
    if (!ps.length) return;
    const i = pos && pos.para != null ? pos.para : (bmOk() ? doc.bm.cur : 0);
    const f = pos && pos.frac ? pos.frac : 0;
    const p = ps[Math.min(i, ps.length - 1)];
    body.scrollTop += p.getBoundingClientRect().top - body.getBoundingClientRect().top + f * p.offsetHeight;
  };
  go();                                            // 畫面已經顯示,量位置會直接排版,不必等下一個畫面
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(go);   // 網頁字型載入後行高可能微調,再對一次
}

/* ---------------- 筆記小視窗（閱讀頁）：選取文字存成筆記後，在文字旁邊跳出 ----------------
 * 筆記在按下「存成筆記」時就已存好；小視窗只是讓你順手補一句註記（選填）。 */
let notePopId = null;
/** 右側欄（筆記小卡、簡易查詢）：固定在右側。左側放目錄／使用流程，右側放這些，各自不重疊。 */
function placeDock(el) {
  el.style.left = el.style.right = el.style.width = el.style.bottom = '';
  el.style.top = '140px';
}
/** 右側欄開著時，讓導讀頁、閱讀頁的內容往左讓出位置（寬螢幕） */
function setDockSpace(on) { [$('readView'), $('guideView')].forEach(v => v.classList.toggle('lk-open', on)); }
function clearDock(el) { el.style.left = el.style.right = el.style.width = el.style.top = el.style.bottom = ''; }
function repositionDocks() {
  if (!$('notePop').hidden && $('notePop').classList.contains('np-dock')) placeDock($('notePop'));
  if ($('lookupPanel').classList.contains('lk-dock')) placeDock($('lookupPanel'));
}
function openNotePop(noteId, rect) {
  const n = doc.notes.find(x => x.id === noteId);
  if (!n) return;
  notePopId = noteId;
  const pop = $('notePop');
  const lk = $('lookupPanel');
  if (lk.classList.contains('lk-dock')) $('btnLookupClose').onclick();   // 右側欄一次只放一個
  if ($('readNotesSlot').classList.contains('open')) setReadNotes(false);   // 筆記總覽要先收起，才不會和小卡重疊
  if (typeof readerMenuOpen === 'function') readerMenuOpen(false);
  $('npQuote').value = n.content;
  const inp = $('npInput');
  inp.value = n.comment || '';
  pop.hidden = false;
  inp.style.height = 'auto'; inp.style.height = Math.min(inp.scrollHeight, 120) + 'px';
  pop.classList.add('np-dock');
  placeDock(pop);
  setDockSpace(true);
  setTimeout(() => inp.focus(), 30);
}
function closeNotePop() {
  $('notePop').hidden = true; notePopId = null; clearDock($('notePop'));
  if (!$('lookupPanel').classList.contains('lk-dock')) setDockSpace(false);
}
function saveNotePop() {
  const n = doc.notes.find(x => x.id === notePopId);
  if (!n) { closeNotePop(); return; }
  const q = $('npQuote').value.trim();
  if (q) n.content = q;                                    // 筆記內容也可以修改
  const v = $('npInput').value.trim();
  if (v) n.comment = v; else delete n.comment;
  saveDocs();
  renderNotes();
  closeNotePop();
  toast(v ? '已加上註記' : '筆記已存好');
}

/** 閱讀頁右側的筆記抽屜（不遮住文章；存筆記時自動打開） */
/* ---------------- 閱讀頁的目錄：列出篇章（沒有小標題就依段落分節），可跳轉，也可直接放書籤 ---------------- */
let tocMemo = null;
function tocItems() {
  if (tocMemo && tocMemo.text === doc.text) return tocMemo.items;
  const paras = splitParas(doc.text), n = paras.length;
  const heads = [];
  paras.forEach((p, i) => { const v = paraView(p); if (v.kind === 'h') heads.push({ i, level: v.level, text: v.text }); });
  const items = [];
  if (heads.length >= 2) {
    if (heads[0].i > 0) items.push({ title: '（開頭）', level: 1, start: 0, end: heads[0].i - 1, h: true });
    heads.forEach((hd, k) => items.push({ title: hd.text, level: hd.level, start: hd.i, end: (k + 1 < heads.length ? heads[k + 1].i : n) - 1, h: true }));
  } else {
    const size = Math.max(1, Math.ceil(n / 20));
    for (let a = 0; a < n; a += size) {
      const b = Math.min(n, a + size) - 1;
      const first = paraView(paras[a]).text.replace(/\s+/g, '');
      items.push({ title: trunc(first, 14), level: 1, start: a, end: b });
    }
  }
  tocMemo = { text: doc.text, items };
  return items;
}
let tocData = [];
function renderToc() {
  const list = $('tocList');
  if (!list || !doc) return;
  tocData = tocItems();
  list.innerHTML = tocData.map((it, k) => {
    const label = rangeLabel(it);
    return `<div class="toc-item lv${it.level}" data-k="${k}">
      <span class="toc-n">${k + 1}</span>
      <button type="button" class="toc-go" title="跳到這裡">${esc(it.title)}<small>${label}</small></button>
      <button type="button" class="toc-bm" aria-label="選為範圍的起點或終點" title="點一下選起點、再點另一節選終點；也可以按住拖曳"><svg width="16" height="16" viewBox="0 0 16 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 2h10v16l-5-4-5 4z"/></svg></button>
    </div>`;
  }).join('');
  updateTocState();
}
/** 目錄裡每一節的狀態：書籤在這一節結尾、落在讀的範圍內、目前正在讀 */
function updateTocState() {
  const list = $('tocList');
  if (!list || !doc) return;
  const b = doc.bm || {}, r = (b.head >= 0 && b.cur >= 0) ? nextSegRange() : null;
  const talked = trunksOf().filter(t => t.messages.some(m => m.role === 'user')).map(segRange);
  list.querySelectorAll('.toc-item').forEach(el => {
    const it = tocData[parseInt(el.dataset.k, 10)]; if (!it) return;
    const inR = !!r && it.end >= r.start && it.start <= r.end;
    el.classList.toggle('has-bm', inR);                   // 範圍內每一節的書籤圖示都塗滿
    el.classList.toggle('in-range', inR);
    el.classList.toggle('talked', talked.some(q => it.start >= q.start && it.end <= q.end));   // 已對話過的節:淡色標示
  });
}
function updateTocActive() {
  const list = $('tocList');
  if (!list || stage !== 'read') return;
  const body = $('readerBody'), top0 = body.getBoundingClientRect().top;
  const ps = body.querySelectorAll('p.rp');
  let para = 0;
  for (let i = 0; i < ps.length; i++) { if (ps[i].getBoundingClientRect().bottom - top0 > 4) { para = i; break; } }
  let act = null;
  list.querySelectorAll('.toc-item').forEach(el => {
    const it = tocData[parseInt(el.dataset.k, 10)];
    const on = it && para >= it.start && para <= it.end;
    el.classList.toggle('active', !!on);
    if (on) act = el;
  });
  if (act && $('readToc').classList.contains('open')) {
    const lr = list.getBoundingClientRect(), ar = act.getBoundingClientRect();
    if (ar.top < lr.top || ar.bottom > lr.bottom) act.scrollIntoView({ block: 'nearest' });
  }
}
function setReadToc(open, remember) {
  if (open && window.innerWidth < 1600 && $('readNotesSlot').classList.contains('open')) setReadNotes(false);   // 螢幕不夠寬時，目錄與筆記欄不同時打開，文章才有足夠寬度
  $('readToc').classList.toggle('open', open);
  $('readView').classList.toggle('toc-open', open);
  $('readToc').setAttribute('aria-hidden', String(!open));
  $('rmReadToc').setAttribute('aria-expanded', String(open));
  if (remember) { try { localStorage.setItem('dupan.readToc', open ? '1' : '0'); } catch (e) {} }
  if (open) updateTocActive();
}
/** 進入閱讀頁：寬螢幕預設展開目錄（記住上次的選擇），窄螢幕預設收起 */
function initReadToc() {
  let pref = null;
  try { pref = localStorage.getItem('dupan.readToc'); } catch (e) {}
  setReadToc(pref === null ? window.innerWidth >= 1240 : pref === '1', false);
}

function setReadNotes(open) {
  if (open && window.innerWidth < 1600 && $('readToc').classList.contains('open')) setReadToc(false, false);
  if (open) {                                              // 右側一次只放一個：打開筆記欄就收起筆記小卡與簡易查詢
    if (!$('notePop').hidden) closeNotePop();
    if ($('lookupPanel').classList.contains('lk-dock')) $('btnLookupClose').onclick();
  }
  const slot = $('readNotesSlot');
  slot.classList.toggle('open', open);
  $('readView').classList.toggle('notes-open', open);   // 寬螢幕:閱讀欄讓出右側位置,不被抽屜蓋住
  slot.setAttribute('aria-hidden', String(!open));
  $('btnReadNotes').setAttribute('aria-expanded', String(open));
}

/** 全站統一的計數單位:學生回應(使用者送出的訊息),不含讀伴的開場與回覆 */
function respCount(node) { return node.messages.filter(m => m.role === 'user').length; }
function docRespCount(d) { return Object.values(d.nodes || {}).reduce((s, n) => s + respCount(n), 0); }

/** 免費試用的瀏覽器代號(隨機、不含個資)與目前文本 id,讓後台把同一人同一篇的紀錄歸在一起 */
function guestIds() {
  let gid = '';
  try { gid = localStorage.getItem('dupan.gid') || ''; } catch (e) {}
  if (!gid) {
    gid = 'g' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
    try { localStorage.setItem('dupan.gid', gid); } catch (e) {}
  }
  return { gid, docId: doc ? doc.id : '',
    gstat: doc ? { branches: Object.values(doc.nodes).filter(n => n.type === 'branch').length, notes: doc.notes.length } : null };
}

/** 免費試用的階段一解析:啟動(伺服器依全文指紋去重,重複呼叫不會重跑)並回報狀態;解析內容不回傳到瀏覽器 */
async function guestAnalysisStatus() {
  try {
    const r = await fetch('guest_analyze.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'run', docTitle: doc.title, docText: doc.text, ...guestIds() }) });
    const d = await r.json().catch(() => ({}));
    return d.status || 'error';
  } catch (e) { return 'error'; }
}
/** 等解析完成(最多 5 分鐘),導讀才依閱讀地圖撰寫——與課程文本相同;太長、超過額度或失敗就不等 */
async function waitGuestAnalysis(d0) {
  const until = Date.now() + 5 * 60 * 1000;
  let status = await guestAnalysisStatus();
  while (status === 'running' && Date.now() < until && doc === d0) {
    $('briefSummary').innerHTML = '<span class="brief-loading">讀伴正在解析這篇文本，導讀會依解析撰寫（約 1–3 分鐘）⋯</span>';
    await new Promise(r => setTimeout(r, 6000));
    status = await guestAnalysisStatus();
  }
  return status;
}

/** 生成（或重看）導讀；force=true 強制重新生成 */
async function generateBriefing(force) {
  showBriefingView(true);
  $('btnBriefRetry').style.display = 'none';
  if (doc.courseTextId && course && course.courseId) return loadCourseBriefing(0);

  if (doc.briefing && doc.briefing.summary && !force) {
    guestAnalysisStatus();   // 舊紀錄:確保解析有啟動(對話時當閱讀地圖)
    renderBriefing(doc.briefing);
    return;
  }
  $('briefSummary').innerHTML = '<span class="brief-loading">讀伴整理中⋯</span>';
  $('briefBackground').innerHTML = '<span class="brief-loading">讀伴整理中⋯</span>';
  clearMindmapList();

  try {
    const d0 = doc;
    await waitGuestAnalysis(d0);
    if (doc !== d0) return;                       // 等待期間換了文本
    const reply = await callApi({
      mode: 'briefing',
      docTitle: doc.title,
      docText: doc.text,
      messages: [{ role: 'user', content: '請為這篇文本生成導讀 JSON。' }]
    });
    const clean = reply.replace(/```json|```/g, '').trim();
    const data = JSON.parse(clean);
    if (!data.summary || !data.background) throw new Error('導讀格式不完整');
    const wasDone = doc.briefing && doc.briefing.done;
    doc.briefing = {
      summary: String(data.summary),
      background: String(data.background),
      mindmap: data.mindmap || null,
      done: !!wasDone
    };
    saveDocs();
    renderBriefing(doc.briefing);
  } catch (e) {
    $('briefSummary').innerHTML = `<span class="brief-error">整理失敗：${esc(e.message)}</span>`;
    $('briefBackground').innerHTML = '<span class="brief-error">—</span>';
    clearMindmapList('<span class="brief-error">—</span>');
    $('btnBriefRetry').style.display = '';
  }
}

/**
 * 課程文本的導讀:全班共用一份,由伺服器依教師審定的解析生成,教師可以修改。
 * 以伺服器版本為準(舊版本機自行生成的導讀不再使用);第一個打開的學生會觸發生成,生成中每 5 秒再問一次。
 */
async function loadCourseBriefing(tries) {
  const d0 = doc;
  // 心智圖卡片跟「相關背景」顯示同一個狀態(整理中、⋯、—)
  const briefMsg = (sum, bg) => { $('briefSummary').innerHTML = sum; $('briefBackground').innerHTML = bg; clearMindmapList(bg); };
  if (doc.briefing && doc.briefing.v) renderBriefing(doc.briefing);
  else if (!tries) briefMsg('<span class="brief-loading">讀伴整理中⋯</span>', '<span class="brief-loading">讀伴整理中⋯</span>');
  let data;
  try {
    data = await courseApi({ action: 'briefing', courseId: course.courseId, sid: course.sid, textId: doc.courseTextId });
  } catch (e) {
    if (doc === d0 && !(doc.briefing && doc.briefing.v)) {
      briefMsg(`<span class="brief-error">載入失敗：${esc(e.message)}</span>`, '<span class="brief-error">—</span>');
      $('btnBriefRetry').style.display = '';
    }
    return;
  }
  if (doc !== d0) return;
  if (data.status === 'done' && data.briefing) {
    const b = data.briefing;
    if (!doc.briefing || doc.briefing.v !== b.v) {
      doc.briefing = { summary: b.summary, background: b.background, mindmap: b.mindmap, v: b.v, done: !!(doc.briefing && doc.briefing.done) };
      saveDocs();
    }
    renderBriefing(doc.briefing);
    return;
  }
  if (data.status === 'running') {
    briefMsg('<span class="brief-loading">導讀準備中，約需半分鐘⋯</span>', '<span class="brief-loading">⋯</span>');
    if (tries < 36) setTimeout(() => {
      if (doc === d0 && $('panelChat').classList.contains('briefing-mode')) loadCourseBriefing(tries + 1);
    }, 5000);
    return;
  }
  if (data.status === 'waiting') {
    briefMsg('<span class="brief-loading">老師還在準備這篇的導讀。你可以先直接閱讀左邊的全文，讀完就可以開始對話。</span>', '—');
    return;
  }
  briefMsg('<span class="brief-error">導讀暫時無法產生，請稍後再試。</span>', '<span class="brief-error">—</span>');
  $('btnBriefRetry').style.display = '';
}

/** 導讀頁的重點畫記：存文字片段，每次畫出導讀時重新套上 */
function applyBriefHl() {
  ['briefSummary', 'briefBackground'].forEach(id => {
    const el = $(id); if (!el) return;
    el.querySelectorAll('mark.bhl').forEach(m => m.replaceWith(document.createTextNode(m.textContent)));
    el.normalize();
    (doc && doc.briefHl || []).forEach(t => {
      const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = w.nextNode())) {
        const i = n.nodeValue.indexOf(t);
        if (i < 0) continue;
        const mid = n.splitText(i); mid.splitText(t.length);
        const m = document.createElement('mark'); m.className = 'bhl';
        mid.parentNode.insertBefore(m, mid); m.appendChild(mid);
        break;
      }
    });
  });
}
function toggleBriefHl(text) {
  if (!doc || !text) return;
  const list = doc.briefHl || (doc.briefHl = []);
  const i = list.indexOf(text);
  if (i >= 0) list.splice(i, 1); else list.push(text);
  saveDocs();
  applyBriefHl();
  toast(i >= 0 ? '已取消重點畫記' : '已加上重點畫記');
}
function renderBriefing(b) {
  $('briefSummary').textContent = b.summary;
  $('briefBackground').textContent = b.background;
  applyBriefHl();
  renderMindmapList(b.mindmap);
  // 自行上傳的文本可以重新生成導讀;課程文本的導讀由老師審定,只在載入失敗時才給重試
  $('btnBriefRetry').style.display = doc && !doc.courseTextId ? '' : 'none';
}

/** 心智圖的分支資料(卡片縮圖與放大版共用),點卡片開放大的完整圖 */
function mindmapBranches(mm) {
  return ((mm && mm.branches) || []).slice(0, 5).map(b => ({
    label: String(b.label || '').trim(),
    children: (b.children || []).slice(0, 4).map(c => String(c).trim()).filter(Boolean)
  })).filter(b => b.label);
}
function renderMindmapList(mm) {
  const wrap = $('briefMapWrap');
  const branches = mindmapBranches(mm);
  if (!branches.length) {
    // 沒有心智圖:自行上傳的文本仍保留卡片,「重新生成」才不會跟著消失;課程文本直接隱藏
    const canRetry = doc && !doc.courseTextId;
    $('briefMapCard').style.display = canRetry ? '' : 'none';
    wrap.innerHTML = canRetry ? '<span class="brief-loading">這次沒有產生心智圖，可以按「重新生成」再試一次。</span>' : '';
    return;
  }
  $('briefMapCard').style.display = '';
  // 和放大版畫同一張圖,只是縮放到卡片寬度
  wrap.innerHTML = '<svg class="mm-thumb" xmlns="http://www.w3.org/2000/svg" role="img"></svg>';
  const svg = wrap.firstChild;
  renderMindmap(svg, mm, 1);
  svg.removeAttribute('width');
  svg.removeAttribute('height');
  svg.setAttribute('aria-label', '心智圖：' + (mm.center || doc.title) + '，' + branches.map(b => b.label).join('、'));
}
/** 心智圖還沒有內容時的狀態文字(預設「整理中」,錯誤時由呼叫端傳入「—」等) */
function clearMindmapList(html) {
  $('briefMapCard').style.display = '';
  $('briefMapWrap').innerHTML = html || '<span class="brief-loading">讀伴整理中⋯</span>';
}

/** 心智圖：中央節點，分支左右交錯展開。
 *  依實際文字長度動態計算畫布尺寸——內容多就畫布大，
 *  由容器捲動呈現，絕不縮小字級。 */
function renderMindmap(svgEl, mm, scale) {
  scale = scale || 1;
  const colors = ['#7A2331', '#2E7D5B', '#B9822F', '#4A5FA5', '#6B4F8A'];
  const branches = mindmapBranches(mm).map(b => ({
    label: trunc(b.label, 8),
    children: b.children.map(c => trunc(c, 16))
  }));
  if (!branches.length) { svgEl.innerHTML = ''; return; }

  // 字級（需與 CSS .mm-* 同步）
  const F = { center: 16, branch: 13, leaf: 12.5 };
  const charW = f => f * 1.08;          // 中文字寬約等於字級
  const rowStep = 26;                    // 葉子行距

  const rightArr = branches.filter((_, i) => i % 2 === 0);
  const leftArr = branches.filter((_, i) => i % 2 === 1);
  const blockH = b => Math.max(b.children.length, 1) * rowStep + 22;
  const sideH = arr => arr.reduce((s, b) => s + blockH(b), 0);

  const centerLabel = trunc(mm.center || doc.title, 10);
  const cw = Math.ceil(centerLabel.length * charW(F.center)) + 34, chh = 42;

  // 每一側需要的水平空間：連線 + 分支膠囊 + 連線 + 最長葉子
  const sideExtent = arr => {
    let ext = 60;
    arr.forEach(b => {
      const pw = Math.ceil(b.label.length * charW(F.branch)) + 26;
      const leafMax = Math.max(0, ...b.children.map(c => c.length)) * charW(F.leaf);
      ext = Math.max(ext, 52 + pw + 36 + Math.ceil(leafMax) + 14);
    });
    return ext;
  };
  const extR = sideExtent(rightArr), extL = sideExtent(leftArr);
  const W = Math.ceil(extL + cw + extR + 28);
  const H = Math.max(sideH(rightArr), sideH(leftArr), 120) + 48;
  const cx = 14 + extL + cw / 2, cy = H / 2;

  svgEl.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svgEl.setAttribute('width', Math.round(W * scale));
  svgEl.setAttribute('height', Math.round(H * scale));

  let html = '';
  let colorIdx = 0;

  const drawSide = (arr, dir) => {
    let y = cy - sideH(arr) / 2;
    arr.forEach(b => {
      const bh = blockH(b);
      const by = y + bh / 2;
      const color = colors[colorIdx++ % colors.length];
      const pw = Math.ceil(b.label.length * charW(F.branch)) + 26, ph = 27;
      const px = dir === 1 ? cx + cw / 2 + 52 : cx - cw / 2 - 52 - pw;
      const sx = dir === 1 ? cx + cw / 2 : cx - cw / 2;
      const pxNear = dir === 1 ? px : px + pw;
      const pxFar = dir === 1 ? px + pw : px;
      html += `<path class="mm-edge" stroke="${color}" d="M ${sx} ${cy} C ${sx + dir * 30} ${cy}, ${pxNear - dir * 30} ${by}, ${pxNear} ${by}"/>`;
      html += `<g class="mm-branch"><rect x="${px}" y="${by - ph / 2}" width="${pw}" height="${ph}" rx="9" fill="${color}"></rect>`
            + `<text x="${px + pw / 2}" y="${by + 4.5}" text-anchor="middle">${esc(b.label)}</text></g>`;
      const lx = dir === 1 ? pxFar + 34 : pxFar - 34;
      b.children.forEach((c, j) => {
        const yy = by + (j - (b.children.length - 1) / 2) * rowStep;
        html += `<path class="mm-edge" stroke="${color}" d="M ${pxFar} ${by} C ${pxFar + dir * 16} ${by}, ${lx - dir * 10} ${yy}, ${lx - dir * 5} ${yy}"/>`;
        html += `<text class="mm-leaf" x="${lx}" y="${yy + 4}" text-anchor="${dir === 1 ? 'start' : 'end'}">${esc(c)}</text>`;
      });
      y += bh;
    });
  };
  drawSide(rightArr, 1);
  drawSide(leftArr, -1);
  html += `<g class="mm-center"><rect x="${cx - cw / 2}" y="${cy - chh / 2}" width="${cw}" height="${chh}" rx="11"></rect>`
        + `<text x="${cx}" y="${cy + 5.5}" text-anchor="middle">${esc(centerLabel)}</text></g>`;
  svgEl.innerHTML = html;
}

/* ---------------- 對話樹(縮排列表) ---------------- */

let openNodeMenuId = null;   // 目前展開「⋯」選單的節點

/** 主幹在第一層,分支縮排一層;每列:圓點、名稱、訊息數、「⋯」 */
function renderTree() {
  const list = $('treeList');
  if (!list || !doc) return;
  const rows = [];
  function walk(id, depth) {
    const n = doc.nodes[id];
    if (!n) return;
    rows.push({ n, depth });
    Object.values(doc.nodes)
      .filter(c => c.parentId === id)
      .sort((a, b) => a.id.localeCompare(b.id))
      .forEach(c => walk(c.id, depth + 1));
  }
  // 每一段對話一條主幹,依段落順序列出(新文本還沒開始對話的空主幹不列)
  // 只展開目前這一段(主幹＋分支);其他段摺成一行,點了切過去。只有一段時照常展開
  const tks = trunksOf().filter(t => t.seg || t.messages.length || t.id === doc.rootId);
  const curT = trunkOf(currentNodeId);
  tks.forEach(t => {
    if (tks.length === 1 || t === curT) walk(t.id, 0);
    else rows.push({ n: t, depth: 0, folded: true });
  });
  if (openNodeMenuId && !doc.nodes[openNodeMenuId]) openNodeMenuId = null;

  // 改名中途被重繪(例如讀伴回覆送到):記下輸入框目前的字與游標,重建後放回去
  const oldInp = list.querySelector('.tree-rename');
  const keep = (renamingNodeId && oldInp) ? {
    value: oldInp.value, start: oldInp.selectionStart, end: oldInp.selectionEnd,
    focused: document.activeElement === oldInp
  } : null;
  treeRebuilding = true;                 // 清空清單時輸入框失焦,不能當成「儲存」
  list.innerHTML = '';
  treeRebuilding = false;
  rows.forEach(({ n, depth, folded }) => {
    const isTrunk = n.type === 'trunk';
    const parked = n.status === 'kept' || n.status === 'merged';
    const bc = isTrunk ? branchCount(n) : 0;
    const meta = folded ? `${respCount(n)} 則${bc ? `・${bc} 條分支` : ''}`
      : isTrunk || n.status === 'open' ? `${respCount(n)} 則`
      : n.status === 'kept' ? '已保留' : '已收束';
    const dotCls = isTrunk ? (folded ? 'folded' : '') : n.status === 'kept' ? 'kept' : n.status === 'merged' ? 'merged' : 'branch';
    const label = isTrunk ? trunkName(n) : n.title;

    const item = document.createElement('div');
    item.className = 'tree-item';
    const row = document.createElement('div');
    row.className = 'tree-row' + (n.id === currentNodeId ? ' current' : '') + (parked ? ' parked' : '') + (folded ? ' folded' : '') + (isTrunk && n.seg ? ' seg-trunk' : '');
    row.style.marginLeft = (depth * 20) + 'px';
    if (isTrunk && n.seg) row.style.setProperty('--seg', segColor(n));
    row.dataset.nodeId = n.id;
    row.innerHTML = `
      <button type="button" class="tree-pick" role="treeitem" aria-current="${n.id === currentNodeId}" title="${esc(isTrunk ? trunkName(n) + ' · ' + doc.title : n.title)}">
        <span class="tree-dot ${dotCls}"></span>
        <span class="tree-label">${esc(label)}</span>
        <span class="tree-meta">${meta}</span>
      </button>
      <button type="button" class="icon-btn tree-more" aria-label="節點選項" aria-expanded="${openNodeMenuId === n.id}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
      </button>`;
    if (n.id === renamingNodeId) {
      // 就地改名:名稱換成輸入框,Enter/失焦儲存、Esc 取消
      row.classList.add('renaming');
      row.innerHTML = `<span class="tree-dot ${dotCls}"></span>
        <input type="text" class="tree-rename" maxlength="40" aria-label="${isTrunk ? '主幹名稱（即文本標題）' : '分支名稱'}">`;
      const inp = row.querySelector('input');
      inp.value = keep ? keep.value : (isTrunk ? doc.title : n.title);
      inp.addEventListener('keydown', (e) => {
        e.stopPropagation();
        if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); finishRenameNode(n.id, inp.value, true); }
        else if (e.key === 'Escape') { e.preventDefault(); finishRenameNode(n.id, '', false); }
      });
      inp.addEventListener('blur', () => { if (!treeRebuilding) finishRenameNode(n.id, inp.value, true); });
      item.appendChild(row);
      list.appendChild(item);
      if (keep) {
        if (keep.focused) { inp.focus(); try { inp.setSelectionRange(keep.start, keep.end); } catch (e) {} }
      } else { inp.focus(); inp.select(); }
      return;
    }
    item.appendChild(row);
    bindTreeRow(row, n);
    row.querySelector('.tree-more').onclick = () => toggleNodeMenu(n.id);
    if (openNodeMenuId === n.id) item.appendChild(buildNodeMenu(n, depth));
    list.appendChild(item);
  });

  drawTreeEdges();

  // 一次性提示:只有主幹時(還沒長出分支)最需要,關掉後不再出現
  let tipOff = false;
  try { tipOff = localStorage.getItem('dupan.tip.tree') === 'off'; } catch (e) {}
  $('treeTip').style.display = tipOff ? 'none' : '';
}

/** 主幹與分支之間的連線:從父節點圓點往下、再轉向子節點圓點。
 *  進行中、已保留的分支用實線;已收束回主幹的分支用虛線(沿用舊版樹狀圖的線條語意) */
function drawTreeEdges() {
  const list = $('treeList');
  if (!list || !doc) return;
  const old = list.querySelector('svg.tree-edges');
  if (old) old.remove();
  if (!list.offsetParent) return;                    // 對話樹折疊或在導讀階段:不畫,展開時再畫
  const lr = list.getBoundingClientRect();
  const dotAt = (id) => {
    const dot = list.querySelector(`.tree-row[data-node-id="${id}"] .tree-dot`);
    if (!dot) return null;
    const r = dot.getBoundingClientRect();
    return { x: r.left - lr.left + list.scrollLeft + r.width / 2, y: r.top - lr.top + list.scrollTop + r.height / 2, rad: r.width / 2 };
  };
  let paths = '';
  Object.values(doc.nodes).forEach(n => {
    if (!n.parentId) return;
    const p = dotAt(n.parentId), c = dotAt(n.id);
    if (!p || !c) return;
    const top = p.y + p.rad + 3, end = c.x - c.rad - 3, r = 5;
    if (c.y - top < r || end - p.x < r) return;
    paths += `<path class="${n.status === 'merged' ? 'merged' : ''}" d="M ${p.x} ${top} V ${c.y - r} Q ${p.x} ${c.y} ${p.x + r} ${c.y} H ${end}"/>`;
  });
  if (!paths) return;
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'tree-edges');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('width', list.scrollWidth);
  svg.setAttribute('height', list.scrollHeight);
  svg.innerHTML = paths;
  list.prepend(svg);
}

function toggleNodeMenu(id) {
  openNodeMenuId = openNodeMenuId === id ? null : id;
  renderTree();
}

/** 節點選項:重新命名、收束到指定節點…、升一層、設為主幹、刪除分支 */
function buildNodeMenu(n, depth) {
  const menu = document.createElement('div');
  menu.className = 'node-menu';
  menu.setAttribute('role', 'menu');
  menu.style.marginLeft = (depth * 20 + 28) + 'px';
  const add = (label, fn, opt = {}) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.setAttribute('role', 'menuitem');
    b.textContent = label;
    if (opt.danger) b.classList.add('danger');
    if (opt.disabled) { b.disabled = true; if (opt.why) b.title = opt.why; }
    else b.onclick = () => { openNodeMenuId = null; renderTree(); fn(); };
    menu.appendChild(b);
  };
  if (n.type === 'trunk' && n !== trunkOf(currentNodeId)) {
    // 其他段落:不離開目前這一段,把那一段的討論帶過來連結
    add('引用這段的討論到目前對話', () => quoteFromSegment(n),
        { disabled: !n.messages.length, why: '這一段還沒有對話內容' });
    add('切換到這一段', () => switchNode(n.id));
    return menu;
  }
  if (!(n.type === 'trunk' && n.seg)) add('重新命名', () => renameNode(n.id));   // 分段主幹以範圍為名,不改名
  if (n.type === 'branch') {
    add('收束到指定節點…', () => openPickTarget(n.id),
        { disabled: !n.messages.some(m => m.role === 'user'), why: '這條分支還沒有對話內容' });
    const parent = doc.nodes[n.parentId];
    add('升一層', () => promoteBranch(n.id),
        { disabled: !parent || !parent.parentId, why: '已經在最上層的分支了' });
    add('設為主幹', () => makeTrunk(n.id));
    add('刪除分支', () => deleteBranch(n.id), { danger: true });
  } else {
    add('從主幹節外生枝', () => createBranch(n.id, '', null));
  }
  return menu;
}

/* ---- 節點互動:點擊切換、雙擊改名、右鍵開選單、拖曳到另一節點底下(進階捷徑) ---- */

let drag = null;
let lastTreeClick = null;   // 自訂雙擊偵測(點擊會重繪列表,原生 dblclick 不可靠)

function bindTreeRow(row, n) {
  const pick = row.querySelector('.tree-pick');
  pick.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    drag = { id: n.id, startX: e.clientX, startY: e.clientY, moved: false, mouse: e.pointerType === 'mouse' };
    if (drag.mouse) pick.setPointerCapture(e.pointerId);
  });
  pick.addEventListener('pointermove', (e) => {
    if (!drag || drag.id !== n.id || !drag.mouse) return;
    if (!drag.moved && Math.hypot(e.clientX - drag.startX, e.clientY - drag.startY) > 8) {
      if (n.type === 'trunk') { drag = null; return; }   // 主幹不可拖
      drag.moved = true;
      row.classList.add('dragging');
    }
    if (drag.moved) {
      document.querySelectorAll('.tree-row.drop-target').forEach(el => el.classList.remove('drop-target'));
      const el = document.elementFromPoint(e.clientX, e.clientY);
      const tg = el && el.closest ? el.closest('.tree-row') : null;
      if (tg && tg.dataset.nodeId !== n.id && trunkOf(tg.dataset.nodeId) === trunkOf(n.id)) tg.classList.add('drop-target');
    }
  });
  pick.addEventListener('pointerup', (e) => {
    if (!drag || drag.id !== n.id) return;
    const wasDrag = drag.moved;
    drag = null;
    row.classList.remove('dragging');
    document.querySelectorAll('.tree-row.drop-target').forEach(el => el.classList.remove('drop-target'));
    if (!wasDrag) {
      const now = Date.now();
      if (lastTreeClick && lastTreeClick.id === n.id && now - lastTreeClick.t < 420) {
        lastTreeClick = null;
        renameNode(n.id);           // 420ms 內點同一節點兩次 = 改名
        return;
      }
      lastTreeClick = { id: n.id, t: now };
      openNodeMenuId = null;
      if (n.id !== currentNodeId) switchNode(n.id); else renderTree();
      return;
    }
    const el = document.elementFromPoint(e.clientX, e.clientY);
    const tg = el && el.closest ? el.closest('.tree-row') : null;
    if (tg && tg.dataset.nodeId && tg.dataset.nodeId !== n.id) reparent(n.id, tg.dataset.nodeId);
  });
  pick.addEventListener('pointercancel', () => {
    drag = null;
    row.classList.remove('dragging');
    document.querySelectorAll('.tree-row.drop-target').forEach(el => el.classList.remove('drop-target'));
  });
  // 鍵盤:Enter/空白鍵 切換節點(pointer 事件不會由鍵盤觸發)
  pick.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); switchNode(n.id); }
  });
  row.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    openNodeMenuId = n.id;
    renderTree();
  });
}

/** 選擇收束插入的目標節點 */
function openPickTarget(branchId) {
  const list = $('pickList');
  list.innerHTML = '';
  const forbidden = subtreeIds(branchId);
  Object.values(doc.nodes)
    .filter(n => !forbidden.includes(n.id))
    .forEach(n => {
      const row = document.createElement('div');
      row.className = 'saved-doc';
      row.innerHTML = `<button class="sd-title">${n.type === 'trunk' ? esc(trunkName(n)) + ' · ' + esc(doc.title) : '分支：' + esc(n.title)}</button>`;
      row.querySelector('.sd-title').onclick = () => {
        $('pickOverlay').classList.add('hidden');
        mergeBranchInto(branchId, n.id);
      };
      list.appendChild(row);
    });
  $('pickOverlay').classList.remove('hidden');
}

/* ---------------- 筆記 ---------------- */

/** 筆記從哪裡來:分類(讀本/對話/手寫/查詢/閱讀回顧)+ 細節(第幾段、哪條分支) */
const NOTE_KINDS = [
  { key: 'guide',  label: '導讀' },
  { key: 'reader', label: '讀本' },
  { key: 'chat',   label: '對話' },
  { key: 'hand',   label: '手寫' },
  { key: 'lookup', label: '簡易查詢' },
  { key: 'review', label: '閱讀回顧' },
];
function noteSourceInfo(src) {
  if (!src || src.label === '手寫') return { kind: 'hand', detail: '' };
  if (src.para !== undefined && src.para !== null) return { kind: 'reader', detail: `第 ${src.para + 1} 段` };
  if (src.nodeId) {
    const n = doc.nodes[src.nodeId];
    return { kind: 'chat', detail: !n ? '分支已刪除' : n.type === 'trunk' ? trunkName(n) : `分支：${n.title}` };
  }
  if (src.label === '查詢') return { kind: 'lookup', detail: '' };
  if (src.label === '導讀') return { kind: 'guide', detail: '' };
  if (src.label === '閱讀回顧') return { kind: 'review', detail: '' };
  return { kind: 'hand', detail: src.label || '' };
}

/* 手寫筆記:在筆記區最上方直接展開輸入框(取代瀏覽器的 prompt 視窗),草稿在重繪時保留 */
/* 標題與內容同一個框裡一起填;編輯既有的手寫筆記也用同一個框(editId 為該筆記) */
let noteDraftOpen = false;
let noteDraft = { title: '', text: '', editId: null };

function openNoteDraft(editNote) {
  if (!doc) return;
  // 另一個輸入框還有沒存的內容:先請使用者存或取消,不直接丟掉
  const target = editNote ? editNote.id : null;
  if (noteDraftOpen && noteDraft.dirty && noteDraft.editId !== target) {
    toast('先儲存或取消正在寫的筆記', true);
    const open = $('notesArea').querySelector('.note-new textarea');
    if (open) open.focus();
    return;
  }
  focusPanel('notes');
  if (!editNote && noteFilter !== 'all' && noteFilter !== 'hand') noteFilter = 'all';
  noteDraftOpen = true;
  noteDraft = editNote
    ? { title: editNote.title || '', text: editNote.content, editId: editNote.id }
    : (noteDraft.editId ? { title: '', text: '', editId: null } : noteDraft);   // 新筆記沿用尚未存的草稿
  renderNotes();
  const box = $('notesArea').querySelector('.note-new');
  if (!box) return;
  const field = editNote || !noteDraft.title ? box.querySelector(editNote ? 'textarea' : 'input') : box.querySelector('textarea');
  field.focus();
  if (field.setSelectionRange) field.setSelectionRange(field.value.length, field.value.length);
  box.scrollIntoView({ block: 'nearest' });
}
function closeNoteDraft(save) {
  if (save) {
    const text = noteDraft.text.trim(), title = trunc(noteDraft.title.trim(), 30);
    if (!text) { toast('先寫點內容再儲存', true); return; }
    const editId = noteDraft.editId;
    noteDraft = { title: '', text: '', editId: null };
    noteDraftOpen = false;
    if (editId) {
      const n = doc.notes.find(x => x.id === editId);
      if (n) {
        n.content = text.slice(0, 4000);
        if (title) n.title = title; else delete n.title;
        saveDocs();
      }
      renderNotes();
      toast('筆記已更新');
      return;
    }
    const note = addNote(text.slice(0, 4000), { label: '手寫' });   // addNote 會重繪筆記區
    if (note && title) { note.title = title; saveDocs(); renderNotes(); }
    return;
  }
  noteDraftOpen = false;
  noteDraft = { title: '', text: '', editId: null };
  renderNotes();
}
function buildNoteDraft() {
  const box = document.createElement('div');
  box.className = 'note-new';
  box.innerHTML = `<label class="sr-only" for="noteDraftTitle">筆記標題</label>
    <input type="text" id="noteDraftTitle" class="nn-title" maxlength="30" placeholder="標題（選填）">
    <label class="sr-only" for="noteDraftInput">筆記內容</label>
    <textarea id="noteDraftInput" rows="4" placeholder="寫下你的想法、疑問或聯想…"></textarea>
    <div class="nn-row">
      <span class="nn-hint">Ctrl + Enter 儲存・Esc 取消</span>
      <button type="button" class="btn small" data-a="cancel">取消</button>
      <button type="button" class="btn small primary" data-a="save">儲存</button>
    </div>`;
  const ti = box.querySelector('input'), ta = box.querySelector('textarea');
  ti.value = noteDraft.title;
  ta.value = noteDraft.text;
  const fit = () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 260) + 'px'; };
  ti.addEventListener('input', () => { noteDraft.title = ti.value; noteDraft.dirty = true; });
  ta.addEventListener('input', () => { noteDraft.text = ta.value; noteDraft.dirty = true; fit(); });
  const keys = (e) => {
    e.stopPropagation();                                   // 不觸發全域的 Esc(關視窗)
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); closeNoteDraft(true); }
    else if (e.key === 'Escape') { e.preventDefault(); closeNoteDraft(false); $('btnAddNote').focus(); }
    else if (e.key === 'Enter' && e.target === ti && !e.isComposing) { e.preventDefault(); ta.focus(); }   // 標題按 Enter 跳到內容
  };
  ti.addEventListener('keydown', keys);
  ta.addEventListener('keydown', keys);
  box.addEventListener('click', (e) => {
    e.stopPropagation();
    const a = e.target.closest('[data-a]');
    if (a) closeNoteDraft(a.dataset.a === 'save');
  });
  requestAnimationFrame(fit);
  return box;
}

/* 筆記清單:依來源篩選(全部/讀本/對話/手寫/查詢/閱讀回顧),拖曳卡片右上角的握把排序 */
let noteFilter = 'all';
try { noteFilter = localStorage.getItem('dupan.noteFilter') || 'all'; } catch (e) {}

function renderNoteFilters() {
  const box = $('noteFilters');
  const counts = {};
  doc.notes.forEach(n => { const k = noteSourceInfo(n.source).kind; counts[k] = (counts[k] || 0) + 1; });
  const kinds = NOTE_KINDS.filter(k => counts[k.key]);
  if (noteFilter !== 'all' && !counts[noteFilter]) noteFilter = 'all';   // 該分類的筆記都刪光了
  // 只有一種來源時不顯示篩選,免得多一排用不到的按鈕
  if (kinds.length < 2) { box.style.display = 'none'; box.innerHTML = ''; noteFilter = 'all'; return; }
  box.style.display = '';
  box.innerHTML = '';
  [{ key: 'all', label: '全部' }, ...kinds].forEach(k => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'nf-chip' + (noteFilter === k.key ? ' on' : '');
    b.setAttribute('aria-pressed', String(noteFilter === k.key));
    b.innerHTML = `${esc(k.label)}<span class="nf-count">${k.key === 'all' ? doc.notes.length : counts[k.key]}</span>`;
    b.onclick = () => {
      noteFilter = k.key;
      try { localStorage.setItem('dupan.noteFilter', noteFilter); } catch (e) {}
      renderNotes();
    };
    box.appendChild(b);
  });
}

function renderNotes() {
  const area = $('notesArea');
  area.innerHTML = '';
  $('noteCount').textContent = doc.notes.length ? String(doc.notes.length) : '';
  updateBmStatus();
  $('btnAddNote').setAttribute('aria-expanded', String(noteDraftOpen));
  renderNoteFilters();
  if (noteDraftOpen && !noteDraft.editId) area.appendChild(buildNoteDraft());
  if (!doc.notes.length) {
    if (!noteDraftOpen) area.innerHTML = '<div class="notes-empty">還沒有筆記。在讀本選取文字按「存成筆記」，或按「＋ 手寫筆記」自己寫下想法。</div>';
    return;
  }
  const shown = doc.notes.filter(n => noteFilter === 'all' || noteSourceInfo(n.source).kind === noteFilter);
  shown.forEach((note) => {
    if (noteDraftOpen && noteDraft.editId === note.id) { area.appendChild(buildNoteDraft()); return; }   // 正在編輯:原地換成輸入框
    const card = document.createElement('div');
    card.className = 'note-card';
    card.dataset.noteId = note.id;
    card.setAttribute('role', 'button');
    card.tabIndex = 0;
    const src = note.source;
    const info = noteSourceInfo(src);
    const handwritten = info.kind === 'hand';
    // 手寫筆記只有內文;摘錄的筆記:摘錄用引文字體,自己的註記放內文
    const quote = handwritten ? '' : note.content;
    const text = handwritten ? note.content : (note.comment || '');
    const kindLabel = (NOTE_KINDS.find(k => k.key === info.kind) || {}).label || '';
    const canJump = src && (src.nodeId || (src.para !== undefined && src.para !== null));
    card.innerHTML = `
      <span class="n-head">
        <button type="button" class="n-grip" aria-label="拖曳排序（也可用方向鍵上下移動）" title="拖曳排序">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
        </button>
        <span class="n-kind k-${info.kind}">${esc(kindLabel)}</span>
        ${info.detail ? `<span class="n-detail" title="${esc(info.detail)}">${esc(info.detail)}</span>` : ''}
        <button type="button" class="n-del" data-t="del" aria-label="刪除這則筆記" title="刪除">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
      </span>
      ${note.title ? `<span class="n-title">${esc(note.title)}</span>` : ''}
      ${quote ? `<span class="n-quote">${esc(quote)}</span>` : ''}
      ${text ? `<span class="n-text">${esc(text)}</span>` : ''}
      ${handwritten && note.comment ? `<span class="n-text">${esc(note.comment)}</span>` : ''}
      <span class="n-tools">
        ${canJump ? '<button type="button" data-t="jump" title="跳到這則筆記的出處">來源</button>' : ''}
        ${handwritten ? '<button type="button" data-t="edit">編輯</button>'
          : `<button type="button" data-t="rename">重新命名</button>
             <button type="button" data-t="comment">${note.comment ? '修改註記' : '註記'}</button>`}
      </span>`;
    card.onclick = (e) => {
      if (e.target.closest('.n-edit') || e.target.closest('.n-grip')) return;   // 註記框、拖曳握把不收合卡片
      const act = e.target.closest('[data-t]') && e.target.closest('[data-t]').dataset.t;
      if (act === 'comment') { editNoteComment(note.id, card); return; }
      if (act === 'del') {
        if (!confirm('刪除這則筆記？')) return;
        doc.notes = doc.notes.filter(n => n.id !== note.id);
        doc.highlights = (doc.highlights || []).filter(h => h.noteId !== note.id);
        saveDocs(); renderNotes(); renderReader(); return;
      }
      if (act === 'jump') { jumpToNoteSource(src); return; }
      if (act === 'rename') { renameNote(note.id); return; }
      if (act === 'edit') { openNoteDraft(note); return; }
      card.classList.toggle('open');
    };
    card.addEventListener('keydown', (e) => {
      if ((e.key === 'Enter' || e.key === ' ') && e.target === card) { e.preventDefault(); card.classList.toggle('open'); }
    });
    card.addEventListener('contextmenu', (e) => { if (!handwritten) { e.preventDefault(); renameNote(note.id); } });
    bindNoteGrip(card.querySelector('.n-grip'), card, note.id);
    area.appendChild(card);
  });
  if (!shown.length && !noteDraftOpen) area.innerHTML = '<div class="notes-empty">這個分類目前沒有筆記。</div>';
}

/** 把筆記 id 移到 beforeId 之前(beforeId 為 null:放到 afterId 之後,或最後) */
function moveNote(id, beforeId, afterId) {
  const arr = doc.notes;
  const from = arr.findIndex(n => n.id === id);
  if (from < 0) return false;
  const [note] = arr.splice(from, 1);
  let to;
  if (beforeId) to = arr.findIndex(n => n.id === beforeId);
  else if (afterId) to = arr.findIndex(n => n.id === afterId) + 1;
  else to = arr.length;
  if (to < 0) to = arr.length;
  arr.splice(to, 0, note);
  const changed = to !== from;
  if (changed) saveDocs();
  return changed;
}

/** 拖曳握把排序:滑鼠與觸控都用 pointer 事件;鍵盤用上下鍵 */
function bindNoteGrip(grip, card, id) {
  grip.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    e.preventDefault(); e.stopPropagation();
    const area = $('notesArea');
    const others = [...area.querySelectorAll('.note-card')].filter(c => c !== card);
    const y0 = e.clientY;
    let beforeId = null, moved = false, scrollTimer = null;
    grip.setPointerCapture(e.pointerId);
    card.classList.add('dragging');
    const mark = (y) => {
      others.forEach(c => c.classList.remove('drop-before', 'drop-after'));
      const target = others.find(c => { const r = c.getBoundingClientRect(); return y < r.top + r.height / 2; });
      beforeId = target ? target.dataset.noteId : null;
      if (target) target.classList.add('drop-before');
      else if (others.length) others[others.length - 1].classList.add('drop-after');
    };
    const move = (ev) => {
      const dy = ev.clientY - y0;
      if (!moved && Math.abs(dy) < 4) return;
      moved = true;
      card.style.transform = `translateY(${dy}px)`;
      mark(ev.clientY);
      // 拖到筆記區上下緣時自動捲動
      const ar = area.getBoundingClientRect();
      clearInterval(scrollTimer);
      const edge = ev.clientY < ar.top + 30 ? -8 : ev.clientY > ar.bottom - 30 ? 8 : 0;
      if (edge) scrollTimer = setInterval(() => { area.scrollTop += edge; }, 16);
    };
    const end = () => {
      grip.removeEventListener('pointermove', move);
      grip.removeEventListener('pointerup', end);
      grip.removeEventListener('pointercancel', end);
      clearInterval(scrollTimer);
      card.classList.remove('dragging');
      card.style.transform = '';
      others.forEach(c => c.classList.remove('drop-before', 'drop-after'));
      if (!moved) return;
      const lastId = others.length ? others[others.length - 1].dataset.noteId : null;
      if (moveNote(id, beforeId, beforeId ? null : lastId)) { renderNotes(); toast('已調整筆記順序'); }
    };
    grip.addEventListener('pointermove', move);
    grip.addEventListener('pointerup', end);
    grip.addEventListener('pointercancel', end);
  });
  grip.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
    e.preventDefault(); e.stopPropagation();
    const ids = [...$('notesArea').querySelectorAll('.note-card')].map(c => c.dataset.noteId);
    const i = ids.indexOf(id);
    const j = e.key === 'ArrowUp' ? i - 1 : i + 1;
    if (j < 0 || j >= ids.length) return;
    const changed = e.key === 'ArrowUp' ? moveNote(id, ids[j], null) : moveNote(id, null, ids[j]);
    if (changed) {
      renderNotes();
      const g = $('notesArea').querySelector(`.note-card[data-note-id="${id}"] .n-grip`);
      if (g) g.focus();
    }
  });
}

/** 在筆記卡片裡展開「個人註記」輸入框;存進 note.comment,課程模式會隨閱讀歷程同步到教師後台 */
function editNoteComment(noteId, card) {
  const note = doc.notes.find(n => n.id === noteId);
  if (!note) return;
  const old = card.querySelector('.n-edit');
  if (old) { old.querySelector('textarea').focus(); return; }
  card.classList.add('open', 'editing');
  const box = document.createElement('div');
  box.className = 'n-edit';
  box.innerHTML = `<textarea rows="3" placeholder="寫下你的想法…" aria-label="這則筆記的註記（Ctrl + Enter 儲存，Esc 取消）"></textarea>`;
  const ta = box.querySelector('textarea');
  ta.value = note.comment || '';
  const save = () => {
    const v = ta.value.trim().slice(0, 2000);
    if (v) { note.comment = v; note.commentTs = Date.now(); } else { delete note.comment; delete note.commentTs; }
    saveDocs(); renderNotes();
    toast(v ? '已儲存' : '已移除註記');
  };
  box.addEventListener('click', (e) => e.stopPropagation());
  box.addEventListener('keydown', (e) => {
    e.stopPropagation();
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); }
    else if (e.key === 'Escape') { e.preventDefault(); renderNotes(); }
  });
  // 按鈕併成一列:來源、重新命名、儲存(刪除在卡片右上角的 ✕)
  const tools = card.querySelector('.n-tools');
  const saveBtn = document.createElement('button');
  saveBtn.type = 'button';
  saveBtn.className = 'n-save';
  saveBtn.textContent = '儲存';
  saveBtn.onclick = (e) => { e.stopPropagation(); save(); };
  // 寫註記時「註記」換成「儲存」,按鈕列維持「來源｜重新命名｜儲存」
  const cBtn = tools.querySelector('[data-t=comment]');
  if (cBtn) cBtn.replaceWith(saveBtn); else tools.appendChild(saveBtn);
  card.insertBefore(box, tools);
  ta.focus();
}

/** 給 AI 整理閱讀回顧/摘要用的筆記文字:摘錄＋讀者自己的註記 */
function notesForAI() {
  return doc.notes.map(n => n.content + (n.comment ? `（我的註記：${n.comment}）` : '')).join(' / ');
}

function addNote(content, source) {
  content = String(content || '').trim();
  if (!content) return;
  const note = { id: uid(), content, source: source || null, ts: Date.now() };
  doc.notes.push(note);
  // 篩選中的分類看不到新筆記時,切回「全部」
  if (noteFilter !== 'all' && noteSourceInfo(note.source).kind !== noteFilter) noteFilter = 'all';
  saveDocs();
  renderNotes();
  toast('已存成筆記');
  return note;
}

/** 打開某則筆記(withComment=直接展開「個人註記」輸入框);右欄或筆記區折疊時先展開 */
function openNoteBubble(noteId, withComment) {
  if (stage === 'read') setReadNotes(true);   // 閱讀頁:筆記在右側抽屜,先滑出來
  focusPanel('notes');
  let b = $('notesArea').querySelector(`.note-card[data-note-id="${noteId}"]`);
  if (!b && noteFilter !== 'all') {
    // 正在篩選別的分類,看不到這則:切回「全部」再打開
    noteFilter = 'all';
    try { localStorage.setItem('dupan.noteFilter', noteFilter); } catch (e) {}
    renderNotes();
    b = $('notesArea').querySelector(`.note-card[data-note-id="${noteId}"]`);
  }
  if (!b) return;
  if (withComment) editNoteComment(noteId, b); else b.classList.add('open');
  b.classList.add('flash');
  setTimeout(() => b.classList.remove('flash'), 1200);
  b.scrollIntoView({ block: 'nearest', behavior: scrollMotion() });
}

/** 目前節點的顯示名稱 */
function nodeLabel(n) {
  return n.type === 'trunk' ? trunkName(n) : '分支：' + n.title;
}



/** 角色圖示(取自角色切換按鈕上的同一個圖,只維護一份) */
function personaIcon(key) {
  const svg = document.querySelector(`#personaPills [data-p="${key}"] svg`);
  return svg ? svg.outerHTML.replace(/width="15" height="15"/, 'width="13" height="13"') : '';
}

const PERSONA_INFO = {
  guide:    { name: '引導者', short: '引' },
  critic:   { name: '批判者', short: '批' },
  explorer: { name: '探索者', short: '探' },
};

/** 筆記重新命名:在卡片標題位置就地輸入(不跳出瀏覽器視窗);Enter/失焦儲存、Esc 取消 */
function renameNote(noteId) {
  const note = doc.notes.find(n => n.id === noteId);
  if (!note) return;
  focusPanel('notes');
  const card = $('notesArea').querySelector(`.note-card[data-note-id="${noteId}"]`);
  if (!card || card.querySelector('.n-title-input')) return;
  const inp = document.createElement('input');
  inp.type = 'text';
  inp.className = 'n-title-input';
  inp.maxLength = 30;
  inp.placeholder = '筆記標題（留空則不顯示）';
  inp.setAttribute('aria-label', '筆記標題');
  inp.value = note.title || '';
  const oldTitle = card.querySelector('.n-title');
  if (oldTitle) oldTitle.replaceWith(inp); else card.querySelector('.n-head').after(inp);
  let done = false;
  const finish = (save) => {
    if (done) return;
    done = true;
    if (save) {
      const t = trunc(inp.value.trim(), 30);
      if (t !== (note.title || '')) {
        if (t) note.title = t; else delete note.title;
        saveDocs();
        toast(t ? `筆記已命名為「${t}」` : '已移除筆記標題');
      }
    }
    renderNotes();
  };
  inp.addEventListener('click', (e) => e.stopPropagation());
  inp.addEventListener('keydown', (e) => {
    e.stopPropagation();
    if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); finish(true); }
    else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
  });
  inp.addEventListener('blur', () => finish(true));
  inp.focus(); inp.select();
}

/** 改名:在對話樹上把名稱換成輸入框,就地修改(不跳出瀏覽器視窗) */
let renamingNodeId = null;
let treeRebuilding = false;   // renderTree 清空清單的那一刻,忽略改名框的失焦
function renameNode(nodeId) {
  const n0 = doc.nodes[nodeId];
  if (!n0 || (n0.type === 'trunk' && n0.seg)) return;
  showBriefingView(false);          // 對話樹只在對話階段顯示
  focusPanel('tree');
  openNodeMenuId = null;
  renamingNodeId = nodeId;
  renderTree();
}
/** 儲存或取消就地改名(save=false 為取消) */
function finishRenameNode(nodeId, value, save) {
  if (renamingNodeId !== nodeId) return;   // Enter 與失焦可能各觸發一次,只處理第一次
  renamingNodeId = null;
  const n = doc.nodes[nodeId];
  if (!save || !n) { renderTree(); return; }
  const isTrunk = n.type === 'trunk';
  const name = trunc(String(value).trim(), 40);
  if (!name) { toast('名稱不能空白', true); renderTree(); return; }
  if (name === (isTrunk ? doc.title : n.title)) { renderTree(); return; }
  if (isTrunk) {
    doc.title = name;
    $('topDocTitle').textContent = `《${name}》`;
    syncDocSwitchTitle();
    renderReader();
  } else {
    n.title = name;
    Object.values(doc.nodes).forEach(o => o.messages.forEach(m => {
      if (m.role === 'merge' && m.branchId === n.id) m.branchTitle = name;
    }));
  }
  saveDocs();
  renderChat(); renderTree();
  toast(isTrunk ? '已更名文本' : `分支已更名為「${name}」`);
}

/** 讀本捲到某個元素置中。直接算 scrollTop,不用 scrollIntoView——它會被同時發生的焦點移動(例如下拉選單收起)取消 */
function scrollReaderTo(el) {
  const rb = $('readerBody');
  const r = rb.getBoundingClientRect(), e = el.getBoundingClientRect();
  const top = rb.scrollTop + (e.top - r.top) - (rb.clientHeight - e.height) / 2;
  rb.scrollTo({ top: Math.max(0, top), behavior: scrollMotion() });
}

function jumpToNoteSource(src) {
  if (!src) return;
  if (src.nodeId && doc.nodes[src.nodeId]) {
    switchNode(src.nodeId);
    focusPanel('chat');
    return;
  }
  if (src.nodeId && !doc.nodes[src.nodeId]) { toast('來源分支已被刪除', true); return; }
  if (src.para !== undefined && src.para !== null) {
    focusPanel('reader');
    const p = document.querySelector(`#readerBody p.rp[data-para="${src.para}"]`);
    if (p) {
      scrollReaderTo(p);
      p.style.transition = 'background .3s';
      p.style.background = 'var(--tip)';
      setTimeout(() => { p.style.background = ''; }, 1400);
    }
  }
}

/* ---------------- 閱讀歷程同步(課程模式) ---------------- */

const syncTimers = {};                               // 各篇各自的延遲送出(換到別篇不會取消上一篇)
const syncedAt = {};                                 // 各篇最後一次成功存到伺服器的時間
function syncBody(d) {
  return JSON.stringify({ courseId: course.courseId, sid: course.sid, snapshot: {
    docId: d.id, title: d.title, textId: d.courseTextId,
    nodes: d.nodes, notes: d.notes, highlights: d.highlights || [],
    // 接續閱讀用:書籤範圍、目前所在的段落、導讀是否看過
    state: { rootId: d.rootId, bm: d.bm || null, briefingDone: !!(d.briefing && d.briefing.done), persona: d.persona, createdAt: d.createdAt },
  } });
}
function syncDoc(d) {
  clearTimeout(syncTimers[d.id]); delete syncTimers[d.id];
  return fetch('sync.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: syncBody(d) })
    .then(r => { if (r.ok) syncedAt[d.id] = Date.now(); return r.ok; }).catch(() => false);
}
function syncTranscript(now) {
  if (!course || !course.courseId || !doc || !doc.courseTextId) return Promise.resolve(true);
  const d = doc;
  if (now) return syncDoc(d);
  clearTimeout(syncTimers[d.id]);
  syncTimers[d.id] = setTimeout(() => syncDoc(d), 2500);
  return Promise.resolve(true);
}
/** 登出前:這台電腦上所有課程文本都存一次;回傳是否全部成功 */
async function syncAllCourseDocs() {
  if (!course || !course.courseId) return true;
  const res = await Promise.all(docs.filter(d => d.courseTextId && d.courseId === course.courseId).map(syncDoc));   // 別門課的文本不能用這門課的學號送
  return res.every(Boolean);
}
/* 關分頁或離開頁面時,還在等待送出的進度用 sendBeacon 補送(本機也還留著,下次開啟會再存) */
window.addEventListener('pagehide', () => {
  if (!course || !course.courseId || !navigator.sendBeacon) return;
  Object.keys(syncTimers).forEach(id => {
    const d = docs.find(x => x.id === id);
    if (d) navigator.sendBeacon('sync.php', syncBody(d));   // 超過 64KB 會送不出去;本機仍留著,下次開啟會再存
    clearTimeout(syncTimers[id]); delete syncTimers[id];
  });
});

/** 登出:清除這台電腦上的所有閱讀紀錄,下次重新開始(課程學生的紀錄已同步到教師後台) */
async function logoutAll() {
  const isCourse = !!(course && course.courseId);
  const msg = isCourse
    ? '確定要登出嗎?\n\n你的閱讀進度（對話、筆記、重點畫記與書籤）已存到伺服器，下次登入、打開同一篇文本就能接著讀。\n這台電腦上的副本會清除。'
    : '確定要登出嗎?\n\n登出後，這台電腦上的對話內容、筆記與重點畫記都會清除，下次會重新開始。';
  if (!confirm(msg)) return;
  if (isCourse && !(await syncAllCourseDocs())
      && !confirm('有些閱讀進度沒有存到伺服器（可能是網路不穩）。\n\n現在登出，這些進度會遺失。建議按「取消」，等網路恢復後再登出。\n\n仍要登出嗎？')) return;
  await courseApi({ action: 'logout' }).catch(() => {});   // 一併清掉伺服器的自動登入 cookie
  ['dupan.course', 'dupan.audience', 'dupan.nickname', 'dupan.acct', LS_DOCS, LS_CURRENT].forEach(k => {
    try { localStorage.removeItem(k); } catch (e) {}
  });
  try { indexedDB.deleteDatabase('dupan-orig'); } catch (e) {}   // 免費試用存在瀏覽器裡的原檔
  location.reload();
}

/* ---------------- 對話核心 ---------------- */

function switchNode(id) {
  if (!doc.nodes[id]) return;
  const oldTrunk = trunkOf(currentNodeId);
  currentNodeId = id;
  const t = trunkOf(id);
  if (t !== oldTrunk) { doc.rootId = t.id; segShowAll = false; saveDocs(); syncTranscript(true); renderReader(); }   // 換段落:立即存一次
  pendingQuote = ''; pendingQuoteMarks = [];
  updatePendingQuote();
  showBriefingView(false);
  renderChat();
  renderTree();
}

function createBranch(parentId, quote, originMsgId) {
  // 主幹要先有討論，才能節外生枝
  const root = doc.nodes[doc.rootId];
  if (!root.messages.some(m => m.role === 'user')) {
    if (quote) { pendingQuote = quote; updatePendingQuote(); }
    if ($('panelChat').classList.contains('briefing-mode')) {
      // 還在導讀:讀完才能開始對話;已讀完就直接進入對話
      if ($('btnBriefDone').disabled) {
        toast('選好範圍、開始對話，就能節外生枝——已幫你把這段放進引用', true);
        return null;
      }
      $('btnBriefDone').click();
    }
    focusPanel('chat');
    $('composerInput').focus();
    toast('先在主幹聊出一個開頭，之後隨時可以節外生枝——已幫你把這段放進引用', true);
    return null;
  }
  const id = uid();
  doc.nodes[id] = {
    id, parentId, type: 'branch',
    title: trunc(quote || '新的分支', 14),
    quote: quote || '',
    originMsgId: originMsgId || null,
    status: 'open',
    messages: []
  };
  saveDocs();
  switchNode(id);
  toast('已節外生枝——直接輸入你想追問的問題');
  setTimeout(() => $('composerInput').focus(), 50);
  return id;
}

/** 組出送給 API 的上下文：祖先鏈（到分支起點為止）+ 本節點訊息 */
function buildContext(node) {
  const msgs = [];
  const path = nodePath(node.id);
  path.forEach((n, i) => {
    let list = n.messages;
    // 若下一層是從某則訊息生枝，祖先只取到那則訊息為止
    if (i < path.length - 1) {
      const child = path[i + 1];
      if (child.originMsgId) {
        const idx = n.messages.findIndex(m => m.id === child.originMsgId);
        if (idx >= 0) list = n.messages.slice(0, idx + 1);
      }
      list = list.slice(-8);   // 祖先脈絡各取最近 8 則即可
    }
    list.forEach(m => {
      if (m.role === 'merge') {
        msgs.push({ role: 'user', content: m.fromSeg
          ? `（讀者在${m.fromSeg}的一條分支「${m.branchTitle}」收束到這裡，摘要：${m.content}）`
          : `（一條分支「${m.branchTitle}」收束回主幹，摘要：${m.content})` });
      } else {
        const content = m.quote ? `引用文本：「${m.quote}」\n${m.content}` : m.content;
        msgs.push({ role: m.role === 'ai' ? 'assistant' : 'user', content });
      }
    });
  });
  return msgs;
}

async function callApi(payload) {
  if (course) payload.student = { courseId: course.courseId, sid: course.sid };
  if (course && doc && doc.courseTextId) payload.courseTextId = doc.courseTextId;
  // 只有課程學生送稱呼(加入課程時填的名字);免費測試已沒有暱稱欄,不送以前存下的舊暱稱
  if (nickname && course && course.courseId) payload.nickname = nickname;
  payload.audience = (course && course.courseId) ? 'course' : 'guest';
  if (payload.audience === 'guest') Object.assign(payload, guestIds());
  const res = await fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) {
    const err = new Error(data.error || 'API 呼叫失敗');
    err.limit = data.limit || '';            // 免費測試的輪數/IP 上限
    throw err;
  }
  return data.reply;
}

async function requestReply(node) {
  if (busy) return;
  busy = true;
  renderChat();
  try {
    const reply = await callApi({
      mode: 'chat',
      docTitle: doc.title,
      docText: doc.text,
      isBranch: node.type === 'branch',
      branchQuote: node.quote,
      persona: doc.persona || 'guide',
      messages: buildContext(node),
      ...segPayload(node)
    });
    node.messages.push({ id: uid(), role: 'ai', content: reply,
      persona: doc.persona || 'guide', ts: Date.now() });
    saveDocs();
  } catch (e) {
    // 被上限擋下:剛送出的那則不算數,放回輸入框
    const last = node.messages[node.messages.length - 1];
    if (e.limit && last && last.role === 'user') {
      node.messages.pop(); saveDocs();
      if (currentNodeId === node.id && !$('composerInput').value) $('composerInput').value = last.content;
    }
    toast(e.message, true);
  } finally {
    busy = false;
    if (currentNodeId === node.id) renderChat();
    renderTree();
  }
}

/**
 * 修改最後一則使用者訊息:把它(與讀伴對它的回覆)移出對話,文字與引用放回輸入框,改完重新送出。
 * 只限每個節點的最後一則;若其後的回覆已經開出分支、或有分支收束進來,就不能改(否則分支會失去起點)。
 */
function editLastMessage(node, msgId) {
  if (busy) return;
  const idx = node.messages.findIndex(x => x.id === msgId);
  if (idx < 0) return;
  const tail = node.messages.slice(idx);
  if (tail.slice(1).some(x => x.role !== 'ai')) { toast('這則之後還有其他內容，無法修改', true); return; }
  const ids = tail.map(x => x.id);
  if (Object.values(doc.nodes).some(n => ids.includes(n.originMsgId))) {
    toast('這段對話已經開出分支，無法修改', true); return;
  }
  const m = node.messages[idx];
  node.messages.splice(idx);
  // 引用的底線跟著這則訊息:移除,並放回待送出的引用
  const qm = (doc.highlights || []).filter(h => h.msgId === m.id);
  doc.highlights = (doc.highlights || []).filter(h => h.msgId !== m.id);
  pendingQuote = m.quote || '';
  pendingQuoteMarks = qm.map(h => ({ para: h.para, start: h.start, end: h.end, text: h.text }));
  const input = $('composerInput');
  input.value = m.quote && m.content === QUOTE_ONLY_TEXT ? '' : m.content;
  input.dispatchEvent(new Event('input'));
  updatePendingQuote();
  saveDocs(); renderChat(); renderTree(); renderReader();
  input.focus();
  toast('已放回輸入框，修改後重新送出');
}

const QUOTE_ONLY_TEXT = '想請你解讀這一句的意思——它在整篇文字裡有什麼作用?';

function sendMessage() {
  if (busy) return;
  const input = $('composerInput');
  const text = input.value.trim();
  if (!text && !pendingQuote) return;
  const node = doc.nodes[currentNodeId];
  const isFree = !doc.courseTextId;
  const used = isFree ? docRespCount(doc) : 0;
  if (isFree && used >= FREE_MAX_TURNS) {
    toast(`免費測試每篇文章最多 ${FREE_MAX_TURNS} 輪對話，這篇已經用完了。可以先「匯出筆記」保存紀錄，或換一篇文章再試。`, true);
    return;
  }
  const left = FREE_MAX_TURNS - used - 1;
  if (isFree && left <= 5) toast(left > 0 ? `免費測試：這篇還可以再對話 ${left} 輪` : '免費測試：這是這篇的最後一輪對話');
  const msgId = uid();
  node.messages.push({
    id: msgId, role: 'user',
    content: text || QUOTE_ONLY_TEXT,
    quote: pendingQuote, ts: Date.now()
  });
  input.value = ''; input.style.height = 'auto';
  if (pendingQuote && pendingQuoteMarks.length) { addMarks(pendingQuoteMarks, 'quote', { nodeId: node.id, msgId }); renderReader(); }
  pendingQuote = ''; pendingQuoteMarks = []; updatePendingQuote();
  saveDocs();
  renderChat(); renderTree();
  requestReply(node);
}

/* ---------------- 分支收束 / 保留 / 結構操作 ---------------- */

/** 把 branchId 的摘要收束並插入 targetId 節點 */
async function mergeBranchInto(branchId, targetId) {
  const node = doc.nodes[branchId];
  const target = doc.nodes[targetId];
  if (!node || !target || node.type !== 'branch' || busy) return;
  if (!node.messages.some(m => m.role === 'user')) {
    toast('這條分支還沒有對話內容，先聊幾句吧', true); return;
  }
  busy = true; renderChat();
  try {
    const summary = await callApi({
      mode: 'branch_summary',
      docTitle: doc.title,
      docText: '',
      messages: buildContext(node)
    });
    const from = trunkOf(node.id), to = trunkOf(target.id), cross = from !== to && !!(from.seg || to.seg);
    node.status = 'merged';
    if (cross) node.mergedSeg = trunkName(to); else delete node.mergedSeg;
    target.messages.push({
      id: uid(), role: 'merge',
      content: summary, branchId: node.id, branchTitle: node.title, ts: Date.now(),
      ...(cross ? { fromSeg: trunkName(from) } : {})
    });
    saveDocs();
    busy = false;
    switchNode(target.id);
    toast(cross ? `分支已收束，摘要放進「${trunkName(to)}」${target.type === 'trunk' ? '' : `的「${target.title}」`}`
      : target.type === 'trunk' ? '分支已收束，摘要放回主幹' : `分支已收束，摘要插入「${target.title}」`);
  } catch (e) {
    busy = false; renderChat();
    toast(e.message, true);
  }
}

function mergeBranch() {
  const node = doc.nodes[currentNodeId];
  if (node.type !== 'branch') return;
  // 「收束回主幹」一律收回主幹；要收到其他層請用右鍵「收束並插入至⋯」
  mergeBranchInto(node.id, trunkOf(node.id).id);
}

function keepBranch() {
  const node = doc.nodes[currentNodeId];
  if (node.type !== 'branch') return;
  node.status = 'kept';
  saveDocs();
  renderChat(); renderTree();
  toast('已保留為獨立分支，不放入主幹');
}

/** 取得 nodeId 及其所有子孫的 id */
function subtreeIds(nodeId) {
  const ids = [nodeId];
  let queue = [nodeId];
  while (queue.length) {
    const cur = queue.shift();
    Object.values(doc.nodes).filter(n => n.parentId === cur).forEach(c => {
      ids.push(c.id); queue.push(c.id);
    });
  }
  return ids;
}

function deleteBranch(nodeId) {
  const node = doc.nodes[nodeId];
  if (!node || node.type !== 'branch') return;
  const ids = subtreeIds(nodeId);
  const extra = ids.length > 1 ? `（含 ${ids.length - 1} 條子分支）` : '';
  if (!confirm(`刪除分支「${node.title}」${extra}?其中的對話將一併刪除。`)) return;
  ids.forEach(id => delete doc.nodes[id]);
  // 清掉指向被刪分支的收束卡片連結（保留摘要文字）
  Object.values(doc.nodes).forEach(n => {
    n.messages.forEach(m => { if (m.role === 'merge' && ids.includes(m.branchId)) m.branchId = null; });
  });
  if (ids.includes(currentNodeId)) currentNodeId = doc.rootId;
  saveDocs();
  renderChat(); renderTree();
  toast('分支已刪除');
}

/** 拖曳層級/升降：把 nodeId 移到 newParentId 底下 */
function reparent(nodeId, newParentId) {
  const node = doc.nodes[nodeId];
  const np = doc.nodes[newParentId];
  if (!node || !np || node.type === 'trunk') return false;
  if (nodeId === newParentId) return false;
  if (subtreeIds(nodeId).includes(newParentId)) {
    toast('不能把分支移到自己的子分支底下', true); return false;
  }
  if (node.parentId === newParentId) return false;
  if (trunkOf(newParentId) !== trunkOf(nodeId)) {
    toast('分支只能移到同一段的主幹或分支底下。要把這條分支的想法帶到別段，請用「⋯」選單的「收束到指定節點…」', true);
    return false;
  }
  node.parentId = newParentId;
  node.originMsgId = null;   // 換了脈絡，起點訊息不再適用
  saveDocs();
  renderTree(); renderChat();
  toast(`「${node.title}」已移到「${np.type === 'trunk' ? trunkName(np) : np.title}」底下`);
  return true;
}

function promoteBranch(nodeId) {
  const node = doc.nodes[nodeId];
  if (!node || node.type === 'trunk') return;
  const parent = doc.nodes[node.parentId];
  if (!parent || !parent.parentId) { toast('已經在最上層的分支了', true); return; }
  reparent(nodeId, parent.parentId);
}

/** 把分支設為主幹：以該節點為根，反轉沿途的父子關係（重新定根） */
function makeTrunk(nodeId) {
  const n = doc.nodes[nodeId];
  if (!n || n.type === 'trunk') return;
  if (!confirm(`把分支「${n.title}」設為主幹？\n整棵對話樹會以它為根重新組織，原本的主幹會變成它底下的一條分支。`)) return;

  const path = nodePath(nodeId);            // [原主幹， ..., n]
  // 反轉路徑上的每條邊：祖先變成自己孩子的孩子
  for (let i = 0; i < path.length - 1; i++) {
    path[i].parentId = path[i + 1].id;
    path[i].originMsgId = null;
  }
  const oldRoot = path[0];

  n.parentId = null;
  n.originMsgId = null;
  n.type = 'trunk';
  n.status = 'open';
  if (path[0].seg) { n.seg = path[0].seg; delete path[0].seg; delete path[0].summary; }   // 分段共讀:範圍跟著新主幹

  if (oldRoot.id !== n.id) {
    oldRoot.type = 'branch';
    if (!oldRoot.title || oldRoot.title === '主幹') oldRoot.title = '原主幹';
    if (oldRoot.status !== 'kept' && oldRoot.status !== 'merged') oldRoot.status = 'open';
  }

  doc.rootId = n.id;
  saveDocs();
  switchNode(n.id);
  toast(`「${n.title}」現在是主幹`);
}

/* ---------------- 選取文字工具列 ---------------- */

let selContext = null; // { text, from, para, ranges }
const isCoarse = window.matchMedia && matchMedia('(pointer: coarse)').matches;  // 觸控裝置

function handleSelection() {
  const sel = window.getSelection();
  const tb = $('selToolbar');
  if (!sel || sel.isCollapsed || !sel.toString().trim()) { tb.classList.remove('show'); selContext = null; return; }
  const anchor = sel.anchorNode && sel.anchorNode.parentElement;
  if (!anchor) return;
  const inReader = !!anchor.closest('#readerBody');
  const inChat = !!anchor.closest('#chatBody');
  const inGuide = stage === 'guide' && !!anchor.closest(isPhoneLayout() ? '#briefingView' : '#guideMain') && sel.anchorNode === sel.focusNode;   // 導讀頁：同一段文字內選取
  if (!inReader && !inChat && !inGuide) { tb.classList.remove('show'); selContext = null; return; }

  const paraEl = inReader ? anchor.closest('p.rp') : null;
  selContext = {
    text: trunc(sel.toString(), 300),
    from: inReader ? 'reader' : inGuide ? 'guide' : 'chat',
    gtext: sel.toString().trim(),
    para: paraEl ? parseInt(paraEl.dataset.para, 10) : null,
    ranges: inReader ? selRanges(sel.getRangeAt(0)) : []
  };
  // 導讀時還沒開始對話:只留存成筆記、重點畫記
  // 對話時:引至對話、重點畫記只適用讀本;在對話訊息裡選取時只留節外生枝、存成筆記
  const guide = $('panelChat').classList.contains('briefing-mode');
  $('selQuote').style.display = (inReader && !guide) ? '' : 'none';
  $('selBranch').style.display = guide ? 'none' : '';
  $('selHighlight').style.display = ((inReader && selContext.ranges.length > 0) || inGuide) ? '' : 'none';
  $('selLookup').style.display = ((inReader && stage === 'read') || inGuide) ? '' : 'none';   // 簡易查詢:閱讀頁選取文字後可查
  // 選取的文字已經全部畫過重點時,同一顆按鈕變成「取消畫記」
  const hlOn = inReader ? rangesAllHighlighted(selContext.ranges) : (inGuide && (doc.briefHl || []).includes(selContext.gtext));
  $('selHlLabel').textContent = hlOn ? '取消畫記' : '重點畫記';
  $('selHighlight').classList.toggle('st-hl-off', hlOn);
  if (isCoarse) {
    // 觸控裝置：固定在螢幕底部，系統選取選單蓋不到
    tb.classList.add('show', 'docked');
    tb.style.left = ''; tb.style.top = '';
  } else {
    tb.classList.remove('docked');
    tb.classList.add('show');
    placeSelToolbar();
  }
}

/** 桌機:工具列放在選取文字下方(下方放不下才放上方) */
function placeSelToolbar() {
  const tb = $('selToolbar');
  const sel = window.getSelection();
  if (!sel || !sel.rangeCount || tb.classList.contains('docked')) return;
  const rect = sel.getRangeAt(0).getBoundingClientRect();
  const tw = tb.offsetWidth || 260, th = tb.offsetHeight || 46;
  let left = rect.left;
  left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
  let top = rect.bottom + 8;
  if (top + th > window.innerHeight - 8) top = rect.top - th - 6;
  tb.style.left = left + 'px';
  tb.style.top = top + 'px';
}

function updatePendingQuote() {
  const box = $('pendingQuote');
  if (pendingQuote) {
    box.style.display = 'flex';
    $('pendingQuoteText').textContent = `「${pendingQuote}」`;
  } else box.style.display = 'none';
}

/* ---------------- 匯出 ---------------- */

/** 導讀的心智圖資料(沒有就是 null) */
function exportMindmap() {
  const mm = doc && doc.briefing && doc.briefing.mindmap;
  return mm && mindmapBranches(mm).length ? mm : null;
}
/** 心智圖的文字版(縮排清單),給純文字與 Word 用 */
function mindmapOutline(mm) {
  return [String(mm.center || doc.title), ...mindmapBranches(mm).flatMap(b => [
    '  - ' + b.label, ...b.children.map(c => '      - ' + c)])].join('\n');
}
/** 心智圖的向量圖(與畫面上放大的那張相同),給 HTML／PDF 用 */
function mindmapSvgMarkup(mm) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
  renderMindmap(svg, mm, 1);
  svg.removeAttribute('width'); svg.removeAttribute('height');
  svg.setAttribute('class', 'mm');
  return svg.outerHTML;
}
/** 匯出視窗開啟時:沒有心智圖就把這個選項停用並說明 */
function syncExportMapOption() {
  const has = !!exportMindmap(), cb = $('exMap');
  cb.disabled = !has; if (!has) cb.checked = false;
  $('exMapNote').textContent = has ? '' : '（這篇還沒有導讀心智圖）';
  $('exMapRow').classList.toggle('is-off', !has);
}

function exportMarkdown() {
  const parts = [`# 《${doc.title}》讀伴筆記`, '', `> 匯出時間：${new Date().toLocaleString('zh-TW')}`, ''];
  const fmtMsgs = (n) => n.messages.map(m => {
    if (m.role === 'merge') return `> ⤷ **分支收束「${m.branchTitle}」${mergeFrom(m)}**:${m.content}`;
    const who = m.role === 'user' ? '**你**'
      : `**讀伴${m.persona && PERSONA_INFO[m.persona] ? '(' + PERSONA_INFO[m.persona].name + ')' : ''}**`;
    const q = m.quote ? `\n> 引用：「${m.quote}」` : '';
    return `${who}:${q}\n${m.content}`;
  }).join('\n\n');

  if ($('exMain').checked) {
    trunksOf().filter(t => t.messages.length).forEach(t => parts.push(`## ${t.seg ? trunkName(t) + '的對話' : '主幹對話'}`, '', fmtMsgs(t), ''));
  }
  if ($('exBranches').checked) {
    const branches = branchesBySeg();
    if (branches.length) {
      parts.push('## 分支對話', '');
      branches.forEach(b => {
        const st = branchStatus(b);
        parts.push(`### 🌿 ${b.title}${segTag(b)}（${st}）`, b.quote ? `> 起點：「${b.quote}」` : '', '', fmtMsgs(b), '');
      });
    }
  }
  if ($('exNotes').checked && doc.notes.length) {
    parts.push('## 筆記', '', ...doc.notes.map(n => `- ${n.title ? '**' + n.title + '**:' : ''}${n.content.replace(/\n/g, ' ')}${n.source && n.source.label ? '(來源:' + n.source.label + (n.source.place ? ' · ' + n.source.place : '') + ')' : ''}${n.comment ? '\n  - 我的註記:' + n.comment.replace(/\n/g, ' ') : ''}`), '');
  }
  if ($('exHl') && $('exHl').checked && hlMarks().length) {
    parts.push('## 重點畫記', '', ...hlMarks().map(h => `- 第 ${h.para + 1} 段：「${h.text}」`), '');
  }
  if ($('exText').checked) {
    parts.push('## 全文', '', doc.text, '');
  }
  downloadFile(`${doc.title}-讀伴筆記.md`, parts.join('\n'), 'text/markdown;charset=utf-8');
  $('exportOverlay').classList.add('hidden');
  toast('已下載筆記');
}

/** 下載一個文字檔 */
function downloadFile(name, content, type) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([content], { type }));
  a.download = name;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ---------------- 匯出 HTML / PDF(原文保留閱讀標記) ---------------- */

/** 依勾選項目組出完整的閱讀紀錄網頁;HTML 直接下載,PDF 交給瀏覽器列印存檔 */
function buildRecordHtml() {
  const nl = (s) => esc(s).replace(/\n/g, '<br>');
  const stOf = branchStatus;
  const msgsHtml = (n) => n.messages.map(m => {
    if (m.role === 'merge') return `<div class="merge">⤷ 分支收束「${esc(m.branchTitle || '')}」${esc(mergeFrom(m))}：${nl(m.content)}</div>`;
    const who = m.role === 'user' ? '你'
      : '讀伴' + (m.persona && PERSONA_INFO[m.persona] ? '・' + PERSONA_INFO[m.persona].name : '');
    const q = m.quote ? `<div class="q">引用：「${esc(m.quote)}」</div>` : '';
    return `<div class="msg ${m.role === 'user' ? 'me' : 'ai'}"><div class="who">${who}</div>${q}${nl(m.content)}</div>`;
  }).join('') || '<p class="none">（沒有對話）</p>';

  const sec = [];
  if ($('exMap').checked && exportMindmap()) {
    let mmHtml;
    try { mmHtml = mindmapSvgMarkup(exportMindmap()); }
    catch (e) { mmHtml = '<pre>' + esc(mindmapOutline(exportMindmap())) + '</pre>'; }   // 圖畫不出來時改附文字版，匯出不會因此失敗
    sec.push(`<h2>導讀心智圖</h2><div class="mmwrap">${mmHtml}</div>`);
  }
  if ($('exText').checked) {
    const paras = splitParas(doc.text).map((p, i) => `<p class="rp"><span class="no">${i + 1}</span>${paraWithMarks(p, i)}</p>`).join('');
    sec.push(`<h2>全文與閱讀標記</h2>
      <p class="legend"><span class="mk mk-quote">引用</span><span class="mk mk-branch">分支</span><span class="mk mk-note">筆記</span><span class="mk mk-hl">重點</span></p>
      <div class="text">${paras}</div>`);
  }
  if ($('exMain').checked) trunksOf().filter(t => t.messages.length).forEach(t => sec.push(`<h2>${t.seg ? esc(trunkName(t)) + '的對話' : '主幹對話'}</h2>${msgsHtml(t)}`));
  if ($('exBranches').checked) {
    const branches = branchesBySeg();
    if (branches.length) sec.push('<h2>分支對話</h2>' + branches.map(b =>
      `<h3>🌿 ${esc(b.title)}${esc(segTag(b))}（${stOf(b)}）</h3>${b.quote ? `<div class="q">起點：「${esc(b.quote)}」</div>` : ''}${msgsHtml(b)}`).join(''));
  }
  if ($('exNotes').checked && doc.notes.length) {
    sec.push('<h2>筆記</h2><ol class="notes">' + doc.notes.map(n => {
      const src = n.source && n.source.label ? `<span class="src">${esc(n.source.label + (n.source.place ? ' · ' + n.source.place : ''))}</span>` : '';
      return `<li>${n.title ? `<b>${esc(n.title)}</b><br>` : ''}${nl(n.content)}${src}${n.comment ? `<div class="cmt"><b>我的註記</b>${nl(n.comment)}</div>` : ''}</li>`;
    }).join('') + '</ol>');
  }
  if ($('exHl').checked && hlMarks().length) {
    sec.push('<h2>重點畫記</h2><ul>' + hlMarks().map(h => `<li>第 ${h.para + 1} 段：「${esc(h.text)}」</li>`).join('') + '</ul>');
  }

  const html = `<!DOCTYPE html>
<html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(doc.title)}・閱讀紀錄</title>
<style>
:root{--ink:#26303A;--soft:#5C6670;--faint:#8A929A;--line:#DBDED3;--paper:#FCFCFA;--verm:#A63A2B;--hl:rgba(246,214,92,.5);--note:#3E8A5A}
body{margin:0;background:#EDEFE9;color:var(--ink);font-family:"Noto Serif TC","Source Han Serif TC","PMingLiU",serif;line-height:1.95}
main{max-width:780px;margin:24px auto;background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:32px 40px}
h1{font-size:24px;letter-spacing:2px;margin:0 0 4px} .meta{color:var(--faint);font-size:13px;font-family:sans-serif}
h2{font-size:17px;border-bottom:1px solid var(--line);padding-bottom:4px;margin:34px 0 12px;color:var(--verm)} h3{font-size:15px;margin:20px 0 6px}
.text p.rp{margin:0 0 14px;font-size:17px;position:relative} .no{position:absolute;left:-26px;color:var(--faint);font-size:11px;font-family:sans-serif;top:6px}
.mk{border-radius:2px} .mk-hl{background-color:var(--hl)} .mk-note{background-image:linear-gradient(to right,var(--note) 60%,transparent 60%);background-size:7px 2px;background-repeat:repeat-x;background-position:0 100%;border-radius:0}
.mk-quote{text-decoration:underline;text-decoration-color:var(--ink);text-decoration-thickness:1.5px;text-underline-offset:4px} .mk-branch{border-bottom:2px solid var(--verm);padding-bottom:1px}
.legend{font-size:13px;font-family:sans-serif;color:var(--soft)} .legend .mk{margin-right:12px;padding:0 3px}
.msg{margin:10px 0;padding:10px 14px;border-radius:10px;font-size:15px} .msg.me{background:#26303A;color:#fff;margin-left:12%} .msg.ai{background:#FBF4F1;border:1px solid #EACFC7;margin-right:12%}
.who{font-size:12px;font-family:sans-serif;opacity:.7;margin-bottom:2px} .q{font-size:13px;color:var(--verm);margin:2px 0 4px} .msg.me .q{color:#F3C9BE}
.merge{color:var(--verm);font-size:14px;margin:10px 0} .none{color:var(--faint)}
.notes li{margin-bottom:12px} .src{display:block;color:var(--faint);font-size:12px;font-family:sans-serif} .cmt{margin-top:4px;padding:4px 10px;border-left:3px solid var(--verm);background:#F5E6E1}
.cmt b{display:block;font-size:12px;color:var(--verm)}
.mmwrap{overflow-x:auto;text-align:center;break-inside:avoid} svg.mm{max-width:100%;height:auto;font-family:"Noto Sans TC","Microsoft JhengHei",sans-serif}
.mm-center rect{fill:#7A2331} .mm-center text{fill:#fff;font-weight:700;font-size:16px} .mm-branch text{fill:#fff;font-size:13px;font-weight:600}
.mm-leaf{font-size:12.5px;fill:#26303A} .mm-edge{fill:none;stroke-width:1.6;opacity:.55}
@media (max-width:700px){main{margin:0;border-radius:0;padding:20px 18px 20px 30px}}
@page{margin:16mm 14mm}
@media print{body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}main{border:0;border-radius:0;margin:0;max-width:none;padding:0 0 0 28px}
h2,h3{break-after:avoid}.msg,.notes li,.text p.rp{break-inside:avoid}}
</style></head><body><main>
<h1>《${esc(doc.title)}》閱讀紀錄</h1>
<div class="meta">由「讀伴・樹狀閱讀」匯出 · ${new Date().toLocaleString('zh-TW')}</div>
${sec.join('\n') || '<p class="none">沒有勾選任何內容。</p>'}
</main></body></html>`;
  return html;
}

function exportHtml() {
  downloadFile(`${doc.title}-閱讀紀錄.html`, buildRecordHtml(), 'text/html;charset=utf-8');
  $('exportOverlay').classList.add('hidden');
  toast('已下載 HTML 閱讀紀錄');
}

/** PDF:把閱讀紀錄放進隱藏 iframe 開啟列印,讀者在列印視窗選「儲存為 PDF」;不需額外套件,中文字型也正確 */
function exportPdf() {
  const old = document.getElementById('pdfFrame');
  if (old) old.remove();
  const frame = document.createElement('iframe');
  frame.id = 'pdfFrame';
  frame.setAttribute('sandbox', 'allow-same-origin allow-modals');   // 多一層保險:框架裡即使混進 script 也不會執行
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
  document.body.appendChild(frame);
  const fdoc = frame.contentWindow.document;
  fdoc.open(); fdoc.write(buildRecordHtml()); fdoc.close();
  $('exportOverlay').classList.add('hidden');
  toast('請在列印視窗的「目的地」選擇「儲存為 PDF」');
  // 等網頁字型與版面就緒再列印,否則可能印出空白或缺字
  const go = () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { toast('無法開啟列印視窗，請改用「下載 .html」後再從瀏覽器列印', true); } };
  const ready = fdoc.fonts && fdoc.fonts.ready ? fdoc.fonts.ready : Promise.resolve();
  ready.then(() => setTimeout(go, 300));
}

/* ---------------- 匯出 Word（含 AI 摘要） ---------------- */

function collectAllMsgs() {
  const msgs = [];
  const collect = (n, label) => n.messages.forEach(m => {
    if (m.role === 'merge') return;
    const pTag = (m.role === 'ai' && m.persona && PERSONA_INFO[m.persona])
      ? `(${PERSONA_INFO[m.persona].name})` : '';
    msgs.push({ role: m.role === 'ai' ? 'assistant' : 'user',
      content: `[${label}${pTag}] ` + (m.quote ? `引用：「${m.quote}」 ` : '') + m.content });
  });
  trunksOf().forEach(t => collect(t, trunkName(t)));
  branchesBySeg().forEach(b => collect(b, '分支：' + b.title + segTag(b)));
  if (doc.notes.length) msgs.push({ role: 'user', content: '[讀者筆記] ' + notesForAI() });
  return msgs;
}

async function exportWord() {
  const btn = $('btnDoExportWord');
  btn.disabled = true;
  toast('正在請讀伴整理 AI 摘要，約需十秒⋯');
  let summary = '';
  try {
    summary = await callApi({ mode: 'doc_summary', kind: 'summary', docTitle: doc.title, docText: '', messages: collectAllMsgs().slice(-30) });
  } catch (e) {
    summary = '(AI 摘要生成失敗：' + e.message + ')';
  }
  const nl = (s) => esc(s).replace(/\n/g, '<br>');
  const msgsHtml = (n) => n.messages.map(m => {
    if (m.role === 'merge') return `<p style="color:#A63A2B;">⤷ 分支收束「${esc(m.branchTitle || '')}」${esc(mergeFrom(m))}:${nl(m.content)}</p>`;
    const who = m.role === 'user' ? '你'
      : ('讀伴' + (m.persona && PERSONA_INFO[m.persona] ? '(' + PERSONA_INFO[m.persona].name + ')' : ''));
    const q = m.quote ? `<p style="color:#A63A2B;margin:2pt 0 2pt 14pt;">引用：「${esc(m.quote)}」</p>` : '';
    return `<p><b>${who}:</b>${q}${nl(m.content)}</p>`;
  }).join('');

  const branches = branchesBySeg();
  let branchesHtml = branches.length ? '' : '<p>（這次閱讀沒有開分支）</p>';
  branches.forEach(b => {
    const st = branchStatus(b);
    const userSays = b.messages.filter(m => m.role === 'user').map(m => `<li>${nl(m.content)}</li>`).join('');
    branchesHtml += `<h3>🌿 ${esc(b.title)}${esc(segTag(b))}（${st}）</h3>`
      + (b.quote ? `<p style="color:#A63A2B;">起點：「${esc(b.quote)}」</p>` : '')
      + (userSays ? `<p>你在這條分支上的思考：</p><ul>${userSays}</ul>` : '<p>（尚未展開對話）</p>');
  });

  let notesHtml = '';
  if (doc.notes.length || hlMarks().length) {
    notesHtml = '<hr><h2>筆記與重點畫記</h2>';
    if (doc.notes.length) notesHtml += '<ul>' + doc.notes.map(n =>
      `<li>${nl(n.content)}${n.source && n.source.label ? `<span style="color:#888;">（來源：${esc(n.source.label)})</span>` : ''}${n.comment ? `<br><span style="color:#A63A2B;">我的註記：${nl(n.comment)}</span>` : ''}</li>`).join('') + '</ul>';
    if (hlMarks().length) notesHtml += '<p><b>重點畫記：</b></p><ul>' + hlMarks().map(h =>
      `<li>第 ${h.para + 1} 段：「${esc(h.text)}」</li>`).join('') + '</ul>';
  }

  const fullHtml = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
<head><meta charset="utf-8"><title>${esc(doc.title)} 閱讀紀錄</title>
<style>body{font-family:"Noto Serif TC","標楷體",serif;line-height:1.8;} h1{font-size:20pt;} h2{font-size:14pt;border-bottom:1px solid #999;padding-bottom:4pt;} h3{font-size:12pt;} p,li{font-size:11pt;}</style>
</head><body>
<h1>《${esc(doc.title)}》閱讀紀錄</h1>
<p style="color:#888;">由「讀伴・樹狀閱讀」匯出 · ${new Date().toLocaleString('zh-TW')}</p>
<hr><h2>AI 摘要</h2><p>${nl(summary)}</p>
${$('exMap').checked && exportMindmap() ? `<hr><h2>導讀心智圖</h2><pre style="font-family:'Noto Sans TC','Microsoft JhengHei',sans-serif;font-size:11pt;line-height:1.7;">${esc(mindmapOutline(exportMindmap()))}</pre>` : ''}
<hr><h2>你的思考分支</h2>${branchesHtml}
<hr><h2>完整對話</h2>${trunksOf().map(t => (t.messages.length ? `<h3>${esc(trunkName(t))}</h3>${msgsHtml(t)}` : '')
  + branches.filter(b => trunkOf(b.id) === t).map(b => `<h3>🌿 ${esc(b.title)}${esc(segTag(b))}</h3>` + msgsHtml(b)).join('')).join('')}
${notesHtml}
</body></html>`;

  downloadFile(`${doc.title}-閱讀紀錄.doc`, '\ufeff' + fullHtml, 'application/msword;charset=utf-8');
  btn.disabled = false;
  $('exportOverlay').classList.add('hidden');
  toast('已下載 Word 閱讀紀錄');
}

/** 複製文字到剪貼簿;剪貼簿 API 不可用時改用舊方法 */
async function copyText(t) {
  try {
    await navigator.clipboard.writeText(t);
  } catch (e) {
    const ta = document.createElement('textarea');
    ta.value = t; document.body.appendChild(ta);
    ta.select(); document.execCommand('copy'); ta.remove();
  }
  toast('已複製到剪貼簿');
}

/* ---------------- 閱讀回顧 ---------------- */

/** 頂部「導讀｜閱讀｜對話｜回顧」:回顧視窗開著時標示「回顧」,關掉後回到原本所在的階段 */
function markReviewTab(on) { syncStageTabs(on); }

async function docSummary() {
  $('summaryOverlay').classList.remove('hidden');
  markReviewTab(true);
  const body = $('summaryBody');
  const btn = $('btnSummaryToNote');
  const copyBtn = $('btnSummaryCopy');
  body.textContent = '整理中⋯';
  btn.disabled = true;
  copyBtn.disabled = true;
  const msgs = collectAllMsgs();   // 所有節點的對話＋讀者筆記(含個人註記)

  try {
    const reply = await callApi({ mode: 'doc_summary', kind: 'note', docTitle: doc.title, docText: '', messages: msgs.slice(-30) });
    body.innerHTML = mdLite(reply);
    btn.disabled = false;
    btn.onclick = () => { addNote('【閱讀回顧】\n' + reply, { label: '閱讀回顧' }); btn.disabled = true; };
    // 複製畫面上看到的文字(已去掉 Markdown 記號),方便貼到作業或其他地方
    copyBtn.disabled = false;
    copyBtn.onclick = () => copyText(body.innerText.trim());
  } catch (e) {
    body.textContent = '整理失敗：' + e.message;
  }
}

/* ---------------- 上傳層 ---------------- */

const SAMPLE_TITLE = '荷塘月色（節選）';
const SAMPLE_TEXT = `這幾天心裡頗不寧靜。今晚在院子裡坐著乘涼，忽然想起日日走過的荷塘，在這滿月的光裡，總該另有一番樣子吧。月亮漸漸地升高了，牆外馬路上孩子們的歡笑，已經聽不見了；妻在屋裡拍著閏兒，迷迷糊糊地哼著眠歌。我悄悄地披了大衫，帶上門出去。

沿著荷塘，是一條曲折的小煤屑路。這是一條幽僻的路；白天也少人走，夜晚更加寂寞。荷塘四面，長著許多樹，蓊蓊鬱鬱的。路的一旁，是些楊柳，和一些不知道名字的樹。沒有月光的晚上，這路上陰森森的，有些怕人。今晚卻很好，雖然月光也還是淡淡的。

路上只我一個人，背著手踱著。這一片天地好像是我的；我也像超出了平常的自己，到了另一世界裡。我愛熱鬧，也愛冷靜；愛群居，也愛獨處。像今晚上，一個人在這蒼茫的月下，什麼都可以想，什麼都可以不想，便覺是個自由的人。白天裡一定要做的事，一定要說的話，現在都可不理。這是獨處的妙處，我且受用這無邊的荷香月色好了。

曲曲折折的荷塘上面，彌望的是田田的葉子。葉子出水很高，像亭亭的舞女的裙。層層的葉子中間，零星地點綴著些白花，有裊娜地開著的，有羞澀地打著朵兒的；正如一粒粒的明珠，又如碧天裡的星星，又如剛出浴的美人。微風過處，送來縷縷清香，彷彿遠處高樓上渺茫的歌聲似的。`;

/* ---------------- 我的書架:點讀本標題後的主畫面 ---------------- */

/** 書封顏色:依標題固定挑一色,同一本書每次看到都一樣 */
const SHELF_COLORS = ['#7A2331', '#3E5C76', '#4F6B4A', '#8A5A2B', '#5B4B7A', '#2F6D6A', '#9A4B3C', '#4A4E57'];
function shelfColor(title) {
  let h = 0;
  for (const ch of String(title)) h = (h * 31 + ch.codePointAt(0)) >>> 0;
  return SHELF_COLORS[h % SHELF_COLORS.length];
}
/** 「今天／昨天／N 天前／日期」 */
function shelfWhen(ts) {
  if (!ts) return '';
  const day = (t) => { const d = new Date(t); d.setHours(0, 0, 0, 0); return d.getTime(); };
  const diff = Math.round((day(Date.now()) - day(ts)) / 86400000);
  if (diff <= 0) return '今天';
  if (diff === 1) return '昨天';
  if (diff < 30) return `${diff} 天前`;
  const d = new Date(ts);
  return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`;
}
/** 書架只放自由試用的文本(課程文本由課程的文本清單管理) */
function shelfDocs() { return docs.filter(d => !d.courseTextId); }

function openShelf() {
  $('shelfSearch').value = '';
  $('btnShelfBack').style.display = doc ? '' : 'none';
  renderShelf();
  $('shelfView').classList.remove('hidden');
}
function closeShelf() { $('shelfView').classList.add('hidden'); }

function renderShelf() {
  const all = shelfDocs();
  const q = $('shelfSearch').value.trim().toLowerCase();
  const list = all
    .filter(d => !q || d.title.toLowerCase().includes(q))
    .sort((a, b) => (b.openedAt || b.createdAt || 0) - (a.openedAt || a.createdAt || 0));
  $('shelfSub').textContent = all.length
    ? `你在這台裝置上讀過 ${all.length} 篇文本，點書封繼續讀。`
    : '還沒有閱讀紀錄，從一篇文本開始吧。';
  const grid = $('shelfGrid');
  grid.innerHTML = '';

  if (!q) {
    const add = document.createElement('div');
    add.className = 'book-item';
    add.innerHTML = `<button type="button" class="book new">
        <span class="book-cover"><span class="bn-plus"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span>
        <span class="bn-text">開始新文本</span></span>
        <span class="book-info"><span class="book-name">上傳或貼上</span><span class="book-meta">.txt、.md、.docx、.pdf</span></span>
      </button>`;
    add.querySelector('.book').onclick = () => openUploadOverlay(true);
    grid.appendChild(add);
  }

  list.forEach(d => {
    const isCur = d === doc;
    const trunks = Object.values(d.nodes).filter(n => n.type === 'trunk');   // 分段共讀:每一段一條主幹,全部加總
    const turns = trunks.reduce((sum, t) => sum + respCount(t), 0);
    const branches = Object.values(d.nodes).filter(n => n.type === 'branch').length;
    const segN = trunks.filter(t => t.seg && t.messages.length).length;
    const started = (d.briefing && d.briefing.done) || turns > 0;
    const state = started
      ? `對話 ${segN > 1 ? segN + ' 段、' : ''}${turns} 則${branches ? `・分支 ${branches}` : ''}${d.notes.length ? `・筆記 ${d.notes.length}` : ''}`
      : '導讀中';
    const when = shelfWhen(d.openedAt || d.createdAt);
    const item = document.createElement('div');
    item.className = 'book-item';
    item.innerHTML = `<button type="button" class="book" aria-label="${isCur ? '回到' : '繼續閱讀'}《${esc(d.title)}》">
        <span class="book-cover" style="--cv:${shelfColor(d.title)}">
          ${isCur ? '<span class="bc-badge">閱讀中</span>' : ''}
          <span class="bc-rule" aria-hidden="true"></span>
          <span class="bc-title">${esc(d.title)}</span>
          <span class="bc-mark" aria-hidden="true">讀伴</span>
        </span>
        <span class="book-info"><span class="book-name">《${esc(d.title)}》</span>
          <span class="book-meta">${state}${when ? `<br>${when}閱讀` : ''}</span></span>
      </button>
      ${isCur ? '' : `<button type="button" class="book-del" title="刪除這篇與其所有對話" aria-label="刪除《${esc(d.title)}》">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg></button>`}`;
    item.querySelector('.book').onclick = () => { if (isCur) closeShelf(); else openDoc(d); };
    if (!isCur) item.querySelector('.book-del').onclick = () => {
      if (!confirm(`刪除《${d.title}》與其所有對話？`)) return;
      docs = docs.filter(x => x.id !== d.id);
      if (d.localOrig) origDel(d.id);   // 免費試用的原檔存在瀏覽器裡,一併清掉
      saveDocs(); renderShelf();
    };
    grid.appendChild(item);
  });

  if (q && !list.length) {
    grid.innerHTML = `<div class="shelf-empty">找不到書名含「${esc(q)}」的文本。</div>`;
  }
}

/** 「開始新文本」的兩種方式:上傳檔案|貼上文字(一次只顯示一種) */
function setUploadMode(mode) {
  $('upModeUpload').hidden = mode !== 'upload';
  $('upModeUpload').style.display = mode === 'upload' ? '' : 'none';
  $('upModePaste').hidden = mode !== 'paste';
  document.querySelectorAll('#upModeSeg button').forEach(b => {
    b.classList.toggle('on', b.dataset.mode === mode);
    b.setAttribute('aria-pressed', String(b.dataset.mode === mode));
  });
}

/* ---------------- 段落重排（修 PDF 斷行破碎） ---------------- */

/** 把被硬切開的斷行接回句子：行尾沒有句末標點就與下一行接合。
 *  標題(# 開頭)自成一段;表格列(含「 | 」)不接合,連續幾列合成一段、一列一行 */
function reflowParagraphs(text) {
  const TERMINAL = /[。！？!?…」』”）)〕】；;：:]\s*$/;   // 句末標點(含全形)
  const lines = text.split('\n');
  const paras = [];
  let buf = '', rows = [];
  const push = () => {
    if (buf.trim()) paras.push(buf.trim()); buf = '';
    if (rows.length) paras.push(rows.join('\n')); rows = [];
  };
  for (const raw of lines) {
    const line = raw.trim();
    if (!line) { push(); continue; }               // 空行：必定分段
    if (/^#{1,3} /.test(line)) { push(); paras.push(line); continue; }
    if (line.includes(' | ')) { if (buf.trim()) { paras.push(buf.trim()); buf = ''; } rows.push(line); continue; }
    if (rows.length) push();
    // 接合：中日文之間直接相連，拉丁字之間補空格
    if (buf && /[A-Za-z0-9]$/.test(buf) && /^[A-Za-z0-9]/.test(line)) buf += ' ' + line;
    else buf += line;
    if (TERMINAL.test(line)) push();               // 句子結束：分段
  }
  push();
  return paras.join('\n\n');
}

/* ---------------- 檔案上傳解析 ---------------- */

function readFileAsText(file, encoding) {
  return new Promise((res, rej) => {
    const r = new FileReader();
    r.onload = () => res(r.result);
    r.onerror = () => rej(new Error('讀取檔案失敗'));
    r.readAsText(file, encoding || 'utf-8');
  });
}
function readFileAsBuffer(file) {
  return new Promise((res, rej) => {
    const r = new FileReader();
    r.onload = () => res(r.result);
    r.onerror = () => rej(new Error('讀取檔案失敗'));
    r.readAsArrayBuffer(file);
  });
}

async function extractTextFromFile(file) {
  const name = file.name.toLowerCase();

  if (name.endsWith('.txt') || name.endsWith('.md')) {
    let text = await readFileAsText(file, 'utf-8');
    // 台灣常見 Big5 編碼：若出現大量替代字元，改用 Big5 重讀
    const bad = (text.match(/\uFFFD/g) || []).length;
    if (bad > Math.max(3, text.length * 0.01)) {
      try { text = await readFileAsText(file, 'big5'); } catch (e) { /* 保留 utf-8 結果 */ }
    }
    return text;
  }

  if (name.endsWith('.docx')) {
    if (typeof mammoth === 'undefined') throw new Error('docx 解析函式庫尚未載入，請檢查網路');
    const buf = await readFileAsBuffer(file);
    // 保留 Word 的標題樣式(「# 」開頭);圖片略過
    const result = await mammoth.convertToHtml({ arrayBuffer: buf }, {
      styleMap: ['p[style-name="Title"] => h1:fresh', 'p[style-name="Subtitle"] => h2:fresh'],
      convertImage: mammoth.images.imgElement(() => Promise.resolve({ src: '' }))
    });
    return docxHtmlToText(result.value);
  }

  if (name.endsWith('.pdf')) {
    if (typeof pdfjsLib === 'undefined') throw new Error('PDF 解析函式庫尚未載入，請檢查網路');
    pdfjsLib.GlobalWorkerOptions.workerSrc =
      'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const buf = await readFileAsBuffer(file);
    const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
    const pages = [], heights = [];
    for (let i = 1; i <= pdf.numPages; i++) {
      const page = await pdf.getPage(i);
      pages.push(pdfPageLines((await page.getTextContent()).items, heights));
    }
    const text = pdfLinesToText(pages, heights);
    if (!text) throw new Error('這份 PDF 沒有可擷取的文字（可能是掃描圖檔），請改用「貼上文字」');
    return text;
  }

  throw new Error('不支援的檔案格式，請使用 .txt / .md / .docx / .pdf');
}

/** PDF 一頁的文字項目 → 一行一行(依 y 座標分行);同一行裡相隔很遠的兩塊(表格的欄)以「 | 」隔開。
 *  heights 收集全文字級,用來推測標題。與 teacher.php 的同名函式相同 */
function pdfPageLines(items, heights) {
  const lines = [];
  let lastY = null, t = '', h = 0, lastEnd = null;
  const flush = () => { if (t.trim()) lines.push({ t: t.trim(), h }); t = ''; h = 0; lastEnd = null; };
  items.forEach(it => {
    const y = Math.round(it.transform[5]), x = it.transform[4], ih = it.height || 0;
    if (lastY !== null && Math.abs(y - lastY) > 4) flush();
    lastY = y;
    if (!it.str.trim()) { if (it.str && (it.width || 0) < 2 * (h || 12)) t += ' '; return; }   // 很寬的空白是表格欄距,不當空格
    if (lastEnd !== null && x - lastEnd > Math.max(18, 2 * (ih || 12))) t = t.replace(/\s+$/, '') + ' | ';
    t += it.str;
    lastEnd = x + (it.width || 0);
    h = Math.max(h, ih);
    for (let q = 0; q < it.str.length; q += 4) heights.push(ih);
  });
  flush();
  return lines;
}
/** 各頁的行 → 文字;字級明顯大於全文中位數的短行當標題(大 1.3 倍以上「# 」、略大「## 」) */
function pdfLinesToText(pages, heights) {
  heights.sort((a, b) => a - b);
  const medH = heights.length ? heights[Math.floor(heights.length / 2)] : 0;
  const head = l => {
    if (!medH || l.t.includes(' | ') || l.t.length < 2 || /[。，,；;：:、]$/.test(l.t) || /^[\d\s.\-–—]+$/.test(l.t)) return '';
    if (l.h >= medH * 1.3 && l.t.length <= 60) return '# ';
    if (l.h >= medH * 1.07 && l.t.length <= 30) return '## ';
    return '';
  };
  return pages.map(ls => ls.map(l => head(l) + l.t).join('\n')).join('\n\n').trim();
}

/** docx(mammoth 的 HTML)→ 讀伴文本格式;與 teacher.php 的同名函式相同 */
function docxHtmlToText(html) {
  const d = new DOMParser().parseFromString(html, 'text/html'), out = [];
  let seenTitle = false;
  const txt = el => el.textContent.replace(/\s+/g, ' ').trim();
  // 沒套標題樣式、用「整行粗體」手動做的標題:短、整段粗體、句尾不是逗號句號,表/圖的標題除外;第一個當大標,「5.1」這類當小標
  const boldHead = (el, t) => {
    if (t.length > 40 || /[。，、；：,;]$/.test(t) || /^[表圖]\s*[一-十\d]+[：:]/.test(t)) return '';
    const plain = el.textContent.replace(/\s+/g, '');
    const bold = [...el.querySelectorAll('strong,b')].map(x => x.textContent.replace(/\s+/g, '')).join('');
    if (!plain || bold.length < plain.length) return '';
    if (!seenTitle) { seenTitle = true; return '#'; }
    return /^\d+\.\d+/.test(t) ? '###' : '##';
  };
  [...d.body.children].forEach(el => {
    const tag = el.tagName.toLowerCase(), m = tag.match(/^h([1-6])$/);
    if (m) { const t = txt(el); if (t) { out.push('#'.repeat(Math.min(+m[1], 3)) + ' ' + t); seenTitle = true; } return; }
    if (tag === 'ul' || tag === 'ol') {
      const items = [...el.querySelectorAll('li')].map(li => '- ' + txt(li)).filter(s => s !== '- ');
      if (items.length) out.push(items.join('\n')); return;
    }
    if (tag === 'table') {
      const rows = [...el.querySelectorAll('tr')].map(tr => [...tr.querySelectorAll('td,th')].map(txt).join(' | ')).filter(Boolean);
      if (rows.length) out.push(rows.join('\n')); return;
    }
    const t = txt(el);
    if (!t) return;
    const bh = boldHead(el, t);
    out.push(bh ? bh + ' ' + t : t);
  });
  return out.join('\n\n');
}

/** 上傳頁即時字數:超過免費版上限時轉紅色提示 */
function updateUpCount() {
  const n = $('upText').value.trim().length;
  const over = n > FREE_MAX_CHARS;
  $('upLimit').classList.toggle('over', over);
  $('upCount').classList.toggle('over', over);
  $('upCount').textContent = n ? `目前 ${n.toLocaleString()} 字${over ? '，已超過上限' : ''}` : '';
}

let upOrig = null;                                  // 上傳框剛載入的 PDF/docx:{file, text}
async function handleFileUpload(file) {
  const status = $('upFileStatus');
  status.classList.remove('err');
  status.textContent = `解析中：${file.name}⋯`;
  try {
    if (file.size > FREE_MAX_BYTES) throw new Error(`檔案過大：這份 ${(file.size / 1048576).toFixed(1)} MB，免費試用上限 ${FREE_MAX_BYTES / 1048576} MB。可以複製文字後改用「貼上文字」`);
    let text = (await extractTextFromFile(file)).replace(/\r\n/g, '\n').trim();
    if (!text) throw new Error('檔案中沒有讀到文字');
    // 超過文本框架上限時截斷並提示
    $('upText').value = text;
    upOrig = /\.(pdf|docx)$/i.test(file.name) ? { file, text } : null;   // 原檔(建立文本時存進這個瀏覽器)
    // 標題:空白或先前自動帶入的檔名,就換成這個檔的檔名(以前只在空白時才填,換檔後會沿用上一個檔的標題)
    if (!$('upTitle').value.trim() || $('upTitle').dataset.auto === '1') {
      $('upTitle').value = file.name.replace(/\.[^.]+$/, '');
      $('upTitle').dataset.auto = '1';
    }
    // PDF 擷取的文字最容易斷行破碎：自動勾選重排
    if (file.name.toLowerCase().endsWith('.pdf')) $('upReflow').checked = true;
    status.textContent = `已載入：${file.name}（${text.length.toLocaleString()} 字），可以按「開始導讀」，或切到「貼上文字」檢查內容`;
    updateUpCount();
  } catch (e) {
    // 錯誤留在上傳框裡（toast 在畫面底部一閃即逝，容易看漏）
    status.textContent = e.message;
    status.classList.add('err');
    toast(e.message, true);
  }
}

/* ---------------- Toast ---------------- */

let toastTimer = null;
function toast(msg, isErr) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.toggle('err', !!isErr);
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

/* ---------------- 事件繫結 ---------------- */

/* ---------------- 電腦版:讀本的控制項搬到頂列 ----------------
 * 左欄只留原文。文本標題、篇章選單、搜尋、字級、標記圖例、看原檔、收合鈕都搬到頂列的 #tbReader;
 * 手機版(mobile.css/mobile.js 生效的條件)維持原本位置,條件改變時搬回去 */
const RC_MQ = window.matchMedia('(max-width: 760px) and (pointer: coarse)');
const rcMoved = [];   // [{el, parent, next}] 搬走的節點與原位置,依序還原
function placeReaderControls() {
  const toTop = !RC_MQ.matches;
  if (document.body.classList.contains('rc-top') === toTop) return;
  document.body.classList.toggle('rc-top', toTop);
  const bar = $('tbReader'), wrap = $('rmenuWrap');
  const move = (el, target, before) => { if (!el) return; rcMoved.push({ el, parent: el.parentNode, next: el.nextSibling }); target.insertBefore(el, before || null); };
  if (toTop) {
    move($('btnChangeDoc'), bar, wrap);            // 文本標題留在頂列看得到
    move($('readerFind'), bar, wrap);              // 搜尋列打開時出現在標題旁
    move($('btnCollapseReader'), $('panelReader'), $('readerBody'));   // 收合鈕:讀本區右上角的小符號
    move($('btnFontMinus'), $('rmFont'));          // 其餘收進「≡」選單
    move($('btnFontPlus'), $('rmFont'));
    move(document.querySelector('.reader-legend'), $('rmLegend'));
    bar.hidden = false;
  } else {
    bar.hidden = true;
    readerMenuOpen(false);
    while (rcMoved.length) {
      const { el, parent, next } = rcMoved.pop();
      parent.insertBefore(el, next && next.parentNode === parent ? next : null);
    }
  }
}
function rmChaptersOpen(on) {
  $('rmChapterList').hidden = !on;
  $('rmChToggle').setAttribute('aria-expanded', String(on));
}
function readerMenuOpen(on) {
  $('readerMenu').classList.toggle('hidden', !on);
  $('btnReaderMenu').setAttribute('aria-expanded', String(on));
  if (!on) return;
  rmChaptersOpen(false);                             // 篇章每次打開選單都先收起
  // 不適用的項目保留位置、變灰:看原檔只有課程文本且老師保存了原檔才能用
  const inCourse = !!(doc && doc.courseTextId && course && course.courseId);   // 課程文本才存到伺服器;免費試用不適用
  $('rmSave').disabled = !inCourse;
  $('rmSaveNote').textContent = !inCourse ? '（限課程學生）'
    : syncedAt[doc.id] ? `（已自動儲存 ${new Date(syncedAt[doc.id]).toTimeString().slice(0, 5)}）` : '（會自動儲存）';
  const kind = origKind();
  $('rmOrig').disabled = !kind;
  $('rmOrigNote').textContent = !kind ? (doc && doc.courseTextId ? '（老師沒有附原檔）' : '（上傳 PDF 或 Word 檔才有）') : kind === 'local' ? '（僅存於此次使用的瀏覽器）' : '';
  $('rmOrig').title = kind === 'local' ? '另開分頁顯示你上傳的原始檔案；原檔只存在這個瀏覽器，清除瀏覽器資料或登出後就沒有了'
    : '另開分頁顯示老師上傳的原始檔案（PDF 或 Word）';
}

function bind() {
  placeReaderControls();
  if (RC_MQ.addEventListener) RC_MQ.addEventListener('change', placeReaderControls); else RC_MQ.addListener(placeReaderControls);
  $('btnReaderMenu').onclick = (e) => { e.stopPropagation(); readerMenuOpen($('readerMenu').classList.contains('hidden')); };
  document.addEventListener('click', (e) => { if (!e.target.closest('#rmenuWrap')) readerMenuOpen(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') readerMenuOpen(false); });
  // 選單項目:按了就關選單(字級與圖例那兩列除外,可以連按)
  $('rmFind').onclick = () => { readerMenuOpen(false); rfOpen(); };
  $('rmChToggle').onclick = () => rmChaptersOpen($('rmChapterList').hidden);
  $('rmReadToc').onclick = () => { readerMenuOpen(false); setReadToc(!$('readToc').classList.contains('open'), true); };   // 閱讀頁:左側目錄與書籤
  $('rmChapterList').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-para]');
    if (!b) return;
    readerMenuOpen(false);
    jumpToNoteSource({ para: parseInt(b.dataset.para, 10) });
  });
  $('rmOrig').onclick = () => { readerMenuOpen(false); openOrig(); };
  $('rmSave').onclick = async () => {
    readerMenuOpen(false);
    if (!doc || !doc.courseTextId) return;
    saveDocs();
    const ok = await syncDoc(doc);
    toast(ok ? `進度已儲存（${new Date().toTimeString().slice(0, 5)}）；登出後再登入，打開這篇就能接著讀`
             : '儲存失敗，請檢查網路後再試一次（進度仍留在這台電腦上）', !ok);
  };

  // 讀本搜尋
  $('btnReaderFind').onclick = () => ($('readerFind').hidden ? rfOpen() : rfClose());
  $('rfClose').onclick = rfClose;
  $('rfPrev').onclick = () => rfGo(rf.cur - 1);
  $('rfNext').onclick = () => rfGo(rf.cur + 1);
  let rfTimer = null;
  $('rfInput').addEventListener('input', () => { clearTimeout(rfTimer); rfTimer = setTimeout(rfRun, 200); });
  $('rfInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); if ($('rfInput').value.trim() !== rf.q) rfRun(); else rfGo(rf.cur + (e.shiftKey ? -1 : 1)); }
    if (e.key === 'Escape') rfClose();
  });
  // 篇章下拉選單
  $('chapterSel').addEventListener('change', () => {
    const v = $('chapterSel').value;
    if (v !== '') jumpToNoteSource({ para: parseInt(v, 10) });
    $('chapterSel').value = '';
  });
  // 對話裡的引文、「第N段」→ 讀本捲到該段
  $('chatBody').addEventListener('click', (e) => {
    const a = e.target.closest('a.q-link');
    if (!a) return;
    e.preventDefault();
    jumpToNoteSource({ para: parseInt(a.dataset.para, 10) });
  });

  // 送出
  $('btnSend').onclick = () => sendMessage();
  const input = $('composerInput');
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  });

  // 讀伴角色(旁邊一行字說明目前角色的作用)
  document.querySelectorAll('#personaPills button').forEach(b => {
    b.onclick = () => {
      if (!doc) return;
      doc.persona = b.dataset.p;
      saveDocs();
      syncPersonaPills();
    };
  });

  // 收合與字級:每欄標題右側的收合鈕,收合後點直排標籤展開
  $('readerBody').addEventListener('scroll', checkReaderScroll);
  $('btnCollapseReader').onclick = () => setColCollapsed('reader', true);
  $('btnExpandReader').onclick = () => setColCollapsed('reader', false);
  $('btnCollapseMid').onclick = () => setColCollapsed('mid', true);
  $('btnExpandMid').onclick = () => setColCollapsed('mid', false);
  document.querySelectorAll('[data-collapse-side]').forEach(b => { b.onclick = () => setColCollapsed('side', true); });
  $('btnExpandSide').onclick = () => setColCollapsed('side', false);
  // 右欄的對話樹、筆記可各自折疊,另一個自動填滿
  $('btnToggleTree').onclick = () => setSecFolded('tree', !layout.treeC);
  $('btnToggleNotes').onclick = () => setSecFolded('notes', !layout.notesC);
  $('btnCloseTip').onclick = () => {
    try { localStorage.setItem('dupan.tip.tree', 'off'); } catch (e) {}
    $('treeTip').style.display = 'none';
  };
  $('btnFontPlus').onclick = () => adjustReaderFont(1);
  $('btnFontMinus').onclick = () => adjustReaderFont(-1);

  // 頂列「⋯」選單:匯出筆記|讀伴介紹、功能說明|登出
  const menu = $('topMenu');
  const setMenu = (open) => {
    menu.classList.toggle('hidden', !open);
    $('btnMenu').setAttribute('aria-expanded', String(open));
    if (open) { const first = menu.querySelector('button'); if (first) first.focus(); }
  };
  $('btnMenu').onclick = (e) => { e.stopPropagation(); setMenu(menu.classList.contains('hidden')); };
  menu.addEventListener('click', (e) => { if (e.target.closest('button')) setMenu(false); });
  document.addEventListener('click', (e) => {
    if (!menu.classList.contains('hidden') && !e.target.closest('#topMenu, #btnMenu')) setMenu(false);
    // 對話樹的節點選單:點到別處就收起
    if (openNodeMenuId && !e.target.closest('.node-menu, .tree-more, .tree-row')) { openNodeMenuId = null; renderTree(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (!menu.classList.contains('hidden')) { setMenu(false); $('btnMenu').focus(); return; }
    if (openNodeMenuId) { openNodeMenuId = null; renderTree(); return; }
    // 關掉最上層的彈出視窗(上傳畫面只有在可以取消時才關)
    const open = [...document.querySelectorAll('.overlay:not(.hidden)')].pop();
    if (open && (open.id !== 'uploadOverlay' || $('btnCancelUpload').style.display !== 'none')) {
      open.classList.add('hidden');
      if (open.id === 'summaryOverlay') markReviewTab(false);
    } else if (!open && doc && !$('shelfView').classList.contains('hidden')) {
      closeShelf();   // 書架:有文本開著時,Esc 回到閱讀
    }
  });

  // 讀伴介紹與功能說明:同一種版面——左側目錄、右側一次一節、上一節/下一節,目錄最下面可切到另一個
  const pagedDialog = (overlayId, navId, prevId, nextId, crossLabel, crossTo) => {
    const ov = $(overlayId), nav = $(navId);
    const secs = [...ov.querySelectorAll('.help-sec')];
    let idx = 0;
    const show = (i) => {
      idx = Math.max(0, Math.min(secs.length - 1, i));
      secs.forEach((s, j) => s.classList.toggle('on', j === idx));
      [...nav.querySelectorAll('.help-item')].forEach((b, j) => { b.classList.toggle('on', j === idx); b.setAttribute('aria-current', String(j === idx)); });
      $(prevId).classList.toggle('invisible', idx === 0);
      $(nextId).classList.toggle('invisible', idx === secs.length - 1);
      const main = ov.querySelector('.help-main');
      if (main) main.scrollTop = 0;
    };
    secs.forEach((s, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'help-item';
      b.textContent = s.dataset.title;
      b.onclick = () => show(i);
      nav.appendChild(b);
    });
    const cross = document.createElement('button');
    cross.type = 'button';
    cross.className = 'help-back';
    cross.textContent = crossLabel;
    cross.onclick = () => { ov.classList.add('hidden'); crossTo(); };
    nav.appendChild(cross);
    $(prevId).onclick = () => show(idx - 1);
    $(nextId).onclick = () => show(idx + 1);
    return () => { show(0); ov.classList.remove('hidden'); };
  };
  // 讀伴介紹是一頁式(全部內容依序往下捲),底部的「功能說明 ›」切到功能說明
  const openAbout = () => {
    $('aboutOverlay').classList.remove('hidden');
    const main = document.querySelector('#aboutOverlay .help-main');
    if (main) main.scrollTop = 0;
  };
  const openHelp = pagedDialog('featuresOverlay', 'helpNav', 'btnHelpPrev', 'btnHelpNext', '‹ 讀伴介紹', openAbout);
  $('btnAboutToHelp').onclick = () => { $('aboutOverlay').classList.add('hidden'); openHelp(); };

  // 導讀
  $('btnBriefDone').onclick = () => {
    if (!doc) return;
    const r = nextSegRange();                         // 範圍太長:先提醒讀者縮小範圍
    if (!r.existing && segChars(r.start, r.end) > SEG_MAX_CHARS) { warnLongSeg(r, startDialogue); return; }
    startDialogue();
  };
  function startDialogue() {
    if (doc.briefing) doc.briefing.done = true;
    else doc.briefing = { summary: '', background: '', mindmap: null, done: true };
    const t = enterSegment();                    // 這次讀完的範圍＝一條主幹;已對話過的範圍就回到那一段
    bmPendingK = -1;
    saveDocs(); syncTranscript(true);             // 切換對話範圍:前一段的對話立即存到伺服器(課程文本)
    showBriefingView(false);
    renderReader(); renderTree(); renderChat();
    if (t.messages.length === 0) summarizeOtherSegments(t).then(() => { if (!t.messages.length) requestReply(t); });
  }
  $('btnBriefing').onclick = () => {
    if (!doc) return;
    if (isPhoneLayout()) {
      if ($('panelChat').classList.contains('briefing-mode')) { focusPanel('chat'); return; }   // 已經在導讀
      generateBriefing(false);
      focusPanel('chat');
      return;
    }
    if (stage !== 'guide') generateBriefing(false);   // 電腦:回到導讀頁(已有導讀就直接顯示)
  };
  $('btnAbout').onclick = () => openAbout();
  $('btnFeatures').onclick = openHelp;
  $('btnDialogue').onclick = () => {
    if (!doc) return;
    if (!$('panelChat').classList.contains('briefing-mode')) { focusPanel('chat'); return; }   // 已經在對話
    if ($('btnBriefDone').disabled) {
      if (isPhoneLayout()) {
        const loading = $('briefSummary').querySelector('.brief-loading');   // 導讀還在整理中
        toast(loading ? '讀伴還在整理導讀，可以先閱讀讀本；放好書籤就能對話'
          : '請先放書籤，標出讀到哪裡，才能對話', true);
      } else {
        toast('先到「閱讀」，在「≡」選單的「目錄與書籤」選好這次讀完的範圍，才能對話', true);
        if (stage !== 'read') showBriefingView(true, 'read');
      }
      return;
    }
    $('btnBriefDone').click();
  };

  // 課程版:學生帳號登入、加入課程、我的課程、修改密碼
  /** 送出表單時鎖住按鈕,錯誤顯示在 errId */
  const busy = async (btn, label, errId, fn) => {
    const old = btn.textContent;
    btn.disabled = true; btn.textContent = label; $(errId).textContent = '';
    try { await fn(); } catch (e) { $(errId).textContent = e.message; }
    finally { btn.disabled = false; if (btn.textContent === label) btn.textContent = old; }
  };
  $('cgLoginForm').onsubmit = (e) => {
    e.preventDefault();
    busy($('btnCgLogin'), '登入中⋯', 'cgErr', async () => {
      try {
        afterAuth(await courseApi({ action: 'login', email: $('cgEmail').value.trim(), pwd: $('cgPwd').value }));
      } catch (e) {
        // 第一次來的學生常先在這裡試登入;提示改走邀請碼(不透露這個 email 有沒有帳號)
        if (e.status === 401) e.message += '。還沒有帳號嗎？請點下方「請輸入課程教師所提供的邀請碼」建立帳號。';
        throw e;
      }
      $('cgPwd').value = '';
    });
  };
  $('lnkCgJoin').onclick = (e) => { e.preventDefault(); openJoin(); };
  // 加入課程:一次送出。這個 email 已有帳號就驗密碼,沒有就用這組密碼建立帳號
  $('cgJoinForm').onsubmit = (e) => {
    e.preventDefault();
    busy($('btnCgJoin'), '加入中⋯', 'cgJErr', async () => {
      const code = $('cgCode').value.trim().toUpperCase(), sid = $('cgSid').value.trim();
      if (!code || !sid) throw new Error('請填寫邀請碼與學號');
      if (!me && (!$('cgJEmail').value.trim() || !$('cgJPwd').value)) throw new Error('請填寫 Email 與密碼');
      const r = await courseApi({ action: 'join', code, sid, name: $('cgName').value.trim(),
        email: $('cgJEmail').value.trim(), pwd: $('cgJPwd').value });
      $('cgJPwd').value = ''; $('cgCode').value = '';
      afterAuth(r, r.courseId);
    });
  };
  $('btnCgJoinBack').onclick = () => {
    if (me) { renderMyCourses(); showCourseView('courses'); } else showCourseView('login');
  };
  $('cgPwdForm').onsubmit = (e) => {
    e.preventDefault();
    busy(e.submitter || $('cgPwdForm').querySelector('button'), '儲存中⋯', 'cgPErr', async () => {
      const r = await courseApi({ action: 'change_pwd', cur: $('cgPCur').value, p1: $('cgP1').value, p2: $('cgP2').value });
      toast('密碼已更新');
      if (pwdForced) { afterAuth(r); return; }
      me = r; renderMyCourses(); showCourseView('courses');
    });
  };
  $('btnCgPwdBack').onclick = () => { renderMyCourses(); showCourseView('courses'); };
  $('btnCgMyCourses').onclick = async () => {
    try { me = await courseApi({ action: 'me' }); } catch (e) { if (e.status === 401) { loginExpired(); return; } }
    renderMyCourses(); showCourseView('courses');
  };
  $('btnCgTextsBack').onclick = () => showCourseGate(false);          // 回到正在讀的文本
  $('btnCgCoursesBack').onclick = () => backToCourseList();            // 回到目前這門課的文本清單
  $('btnCgJoinMore').onclick = openJoin;
  $('btnCgPwdOpen').onclick = () => openPwd(false);
  $('btnCgLogout').onclick = courseLogout;
  $('btnCgLogout2').onclick = courseLogout;
  // 已登入課程(例如從清單按「回上一步」再點課程):直接回清單
  $('btnEntryCourse').onclick = () => {
    if (course && course.courseId) { backToCourseList(); return; }
    showCourseView('login');
    setTimeout(() => $('cgEmail').focus(), 50);
  };
  $('btnEntryGuest').onclick = enterGuest;
  $('btnEntryTeacher').onclick = () => {
    showCourseView('teacher');
    setTimeout(() => $('cgTeacherLogin').focus(), 50);
  };
  $('btnCgBackT').onclick = () => showCourseView('choose');
  $('btnCgBack').onclick = () => showCourseView('choose');

  // 底部 banner:浮動式——滑鼠靠近畫面底部才浮出(觸控裝置改為常駐,見 CSS)
  const banner = $('siteBanner');
  const PEEK_ZONE = 6;                        // 距底部多少 px 內算「接近」:貼到最底邊才浮出,才不會擋到按鈕
  let bannerPeek = false;
  const setBannerPeek = (on) => {
    if (on === bannerPeek) return;
    bannerPeek = on;
    banner.classList.toggle('peek', on);
  };
  document.addEventListener('mousemove', (e) => {
    const near = e.clientY >= window.innerHeight - PEEK_ZONE;
    // 簡要查詢面板開著時不打擾(只在接近底部時才去查,省下每次移動的 DOM 查詢)
    setBannerPeek(near && !$('lookupPanel').classList.contains('open'));
  }, { passive: true });
  document.addEventListener('mouseleave', () => setBannerPeek(false));

  // 簡要查詢
  const lkPanel = $('lookupPanel');
  let lkBusy = false;
  const clearLkFloat = () => { lkPanel.classList.remove('lk-dock'); clearDock(lkPanel); if ($('notePop').hidden) setDockSpace(false); $('lookupQ').hidden = true; };
  $('btnLookup').onclick = () => {
    clearLkFloat();
    const opening = !lkPanel.classList.contains('open');
    lkPanel.classList.toggle('open');
    $('btnLookup').setAttribute('aria-expanded', String(opening));
    if (!opening) return;
    // 每次打開都從空白開始,不留上次的問題與答案;只帶入正在輸入的文字,或剛在讀本/對話圈選的片段
    $('lookupInput').value = '';
    $('lookupAnswer').style.display = 'none'; $('lookupAnswer').textContent = ''; $('lookupTools').style.display = 'none';
    const typed = $('composerInput').value.trim();
    const picked = (selContext && selContext.text) ? selContext.text.trim() : '';
    const seed = typed || picked;
    if (seed) $('lookupInput').value = trunc(seed, 100);
    $('lookupInput').focus();
    $('lookupInput').select();
  };
  $('btnLookupClose').onclick = () => { clearLkFloat(); lkPanel.classList.remove('open'); $('btnLookup').setAttribute('aria-expanded', 'false'); };
  const doLookup = async () => {
    if (lkBusy) return;
    const q = $('lookupInput').value.trim();
    if (!q) return;
    lkBusy = true;
    const ans = $('lookupAnswer');
    ans.style.display = '';
    ans.textContent = '查詢中⋯';
    $('lookupTools').style.display = 'none';
    try {
      const res = await fetch('lookup.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ q })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || '查詢失敗');
      ans.textContent = data.answer;
      $('lookupTools').style.display = 'flex';
    } catch (e) {
      ans.textContent = '查詢失敗:' + e.message;
    } finally {
      lkBusy = false;
    }
  };
  $('btnLookupGo').onclick = doLookup;
  $('lookupInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); doLookup(); }
  });
  $('btnLookupCopy').onclick = () => copyText($('lookupAnswer').textContent);
  $('btnLookupNote').onclick = () => {
    if (!doc) { toast('先開啟一份文本,才能存筆記', true); return; }
    addNote('【簡易查詢】' + $('lookupInput').value.trim() + '\n' + $('lookupAnswer').textContent,
      { label: '查詢' });
  };

  $('btnBriefRetry').onclick = () => { if (doc) generateBriefing(true); };
  const openMap = () => {
    if (!doc || !doc.briefing || !doc.briefing.mindmap) return;
    renderMindmap($('briefMapBig'), doc.briefing.mindmap, 1.3);
    $('mapOverlay').classList.remove('hidden');
  };
  $('briefMapWrap').onclick = openMap;
  $('briefMapWrap').addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openMap(); } });

  // 分支操作
  $('btnMergeBranch').onclick = mergeBranch;
  $('btnKeepBranch').onclick = keepBranch;
  $('btnClearQuote').onclick = () => { pendingQuote = ''; pendingQuoteMarks = []; updatePendingQuote(); };

  // 選取工具列
  document.addEventListener('selectionchange', () => {
    clearTimeout(window.__selT);
    window.__selT = setTimeout(handleSelection, 150);
  });
  document.addEventListener('mousedown', (e) => {
    if (!e.target.closest('.sel-toolbar')) $('selToolbar').classList.remove('show', 'docked');
  });
  window.addEventListener('scroll', () => {
    $('selToolbar').classList.remove('show', 'docked');
  }, true);
  // pointerdown 搶在觸控清除選取之前觸發；動作後統一收工具列與選取
  const bindSelBtn = (id, fn) => {
    $(id).addEventListener('pointerdown', (e) => {
      e.preventDefault();
      if (!selContext) return;
      fn();
      $('selToolbar').classList.remove('show', 'docked');
      try { window.getSelection().removeAllRanges(); } catch (err) {}
    });
  };
  bindSelBtn('selHighlight', () => {
    if (selContext.from === 'guide') { toggleBriefHl(selContext.gtext); return; }
    if (!selContext.ranges.length) return;
    if (rangesAllHighlighted(selContext.ranges)) {
      removeHlInRanges(selContext.ranges);
      saveDocs(); renderReader();
      toast('已取消這段重點畫記');
      return;
    }
    addMarks(selContext.ranges, 'hl');
    saveDocs(); renderReader();
    toast('已加上重點畫記');
  });
  bindSelBtn('selNote', () => {
    // 重畫讀本後選取範圍會消失，先記下文字的位置（閱讀頁的小視窗要貼在它旁邊）
    let anchor = null;
    try { const sr = window.getSelection(); if (sr && sr.rangeCount) anchor = sr.getRangeAt(0).getBoundingClientRect(); } catch (e) {}
    const src = selContext.from === 'guide' ? { label: '導讀', place: '導讀' }
      : selContext.from === 'reader'
      ? { para: selContext.para,
          label: `文本${selContext.para !== null ? (selContext.para + 1) : ''}`, place: '讀本' }
      : { nodeId: currentNodeId, label: '對話', place: nodeLabel(doc.nodes[currentNodeId]) };
    const note = addNote(selContext.text, src);
    if (!note) return;
    if (selContext.ranges.length) { addMarks(selContext.ranges, 'note', { noteId: note.id }); saveDocs(); renderReader(); }
    if ((stage === 'read' || stage === 'guide') && anchor) openNotePop(note.id, anchor);   // 閱讀頁、導讀頁：右側欄的小卡片   // 閱讀頁:文字旁邊的小視窗
    else openNoteBubble(note.id, true);              // 直接展開「我的註記」,不需要可以取消
  });
  bindSelBtn('selQuote', () => {
    pendingQuote = selContext.text;
    pendingQuoteMarks = selContext.ranges;           // 真的送出時才畫底線
    updatePendingQuote();
    if ($('panelChat').classList.contains('briefing-mode')) {
      if ($('btnBriefDone').disabled) { toast('已放進引用；選好範圍、開始對話後就能送出', false); return; }
      $('btnBriefDone').click();
    }
    focusPanel('chat');
    $('composerInput').focus();
  });
  bindSelBtn('selBranch', () => {
    const ranges = selContext.ranges;
    const id = createBranch(currentNodeId, selContext.text, null);
    if (id) {
      addMarks(ranges, 'branch', { nodeId: id }); saveDocs(); renderReader();
      focusPanel('chat');                            // 對話欄收合或在手機下方時,把新分支帶到眼前
    }
    else pendingQuoteMarks = ranges;                 // 主幹還沒開始:先放進引用
  });

  $('btnLogout').onclick = logoutAll;

  window.addEventListener('resize', repositionDocks);
  // ---- 筆記小視窗 ----
  $('npSave').onclick = saveNotePop;
  $('npClose').onclick = closeNotePop;
  $('npQuote').addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeNotePop(); } });
  $('npInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); saveNotePop(); }
    else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeNotePop(); }
  });
  $('npInput').addEventListener('input', () => { const t = $('npInput'); t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 120) + 'px'; });
  document.addEventListener('mousedown', (e) => {
    if ($('notePop').hidden) return;
    if (stage !== 'read' && stage !== 'guide' && !e.target.closest('#notePop') && !e.target.closest('#selToolbar')) closeNotePop();
  });
  // （筆記小卡固定在右側，捲動讀本時不會自動關閉——目錄展開、版面重排造成的捲動事件也不會誤關它）

  // ---- 四個階段：導讀 → 閱讀 → 對話 → 回顧（電腦與平板）----
  $('btnReading').onclick = () => { if (doc && stage !== 'read') showBriefingView(true, 'read'); };
  $('btnGuideStart').onclick = () => showBriefingView(true, 'read');
  // 導讀頁：滑鼠在兩側空白處滾動，也帶動中間的內容往下拉
  $('guideView').addEventListener('wheel', (e) => {
    if (e.target.closest('.gv-scroll, .rv-toc, .note-pop, .lookup-panel')) return;
    $('gvBody').scrollTop += e.deltaY;
  }, { passive: true });
  // 目錄與書籤上方的範圍說明:點了跳到範圍開頭
  $('tocStatus').onclick = () => {
    if (!bmOk()) return;
    const p = $('readerBody').querySelector(`p.rp[data-para="${nextSegRange().start}"]`);
    if (p) p.scrollIntoView({ block: 'center', behavior: scrollMotion() });
  };
  $('btnReadTocClose').onclick = () => setReadToc(false, true);
  $('tocList').addEventListener('click', (e) => {
    const row = e.target.closest('.toc-item'); if (!row) return;
    const it = tocData[parseInt(row.dataset.k, 10)]; if (!it) return;
    if (e.target.closest('.toc-bm')) { if (e.detail === 0) tocBmClick(parseInt(row.dataset.k, 10)); return; }   // 滑鼠由 pointer 事件處理;鍵盤(detail 0)在這裡
    const p = $('readerBody').querySelector(`p.rp[data-para="${it.start}"]`);
    if (p) {
      const rb = $('readerBody');
      rb.scrollTo({ top: Math.max(0, rb.scrollTop + p.getBoundingClientRect().top - rb.getBoundingClientRect().top - 24), behavior: scrollMotion() });
    }
  });
  // 書籤:按住書籤圖示往上或往下拖,選取連續幾節;沒拖就當作點一下(起點/終點)。
  // 拖到清單上緣或下緣(各 48px 內)會自動捲動,範圍跟著延伸,長文本的目錄也能一路選到底
  let tocDrag = null;
  const tocDragAt = (y) => {                          // 指標高度上的那一節 → 範圍(落在節與節的空隙或清單邊緣時,取最接近的一節)
    const items = [...$('tocList').querySelectorAll('.toc-item')];
    let row = null, best = Infinity;
    for (const el of items) {
      const r = el.getBoundingClientRect(), d = y < r.top ? r.top - y : y > r.bottom ? y - r.bottom : 0;
      if (d < best) { best = d; row = el; }
      if (!d) break;
    }
    if (!row) return;
    const k = parseInt(row.dataset.k, 10);
    if (k === tocDrag.k1 || (tocDrag.k1 < 0 && k === tocDrag.k0)) return;
    tocDrag.k1 = k;
    const a = Math.min(tocDrag.k0, k), z = Math.max(tocDrag.k0, k);
    bmPendingK = -1;
    setBmRange(tocData[a].start, tocData[z].end);
  };
  const tocDragScroll = () => {                       // 靠近邊緣時每一格畫面捲一點,越靠邊越快
    if (!tocDrag) return;
    const list = $('tocList'), r = list.getBoundingClientRect(), EDGE = 48;
    const y = tocDrag.y, v = y > r.bottom - EDGE ? (y - (r.bottom - EDGE)) : y < r.top + EDGE ? -((r.top + EDGE) - y) : 0;
    if (v) {
      list.scrollTop += Math.max(-24, Math.min(24, v / 2));
      tocDragAt(Math.max(r.top, Math.min(r.bottom, y)));   // 指標在清單外時,以清單邊緣那一節為準
    }
    tocDrag.raf = requestAnimationFrame(tocDragScroll);
  };
  const tocDragEnd = () => { if (tocDrag) cancelAnimationFrame(tocDrag.raf); const d = tocDrag; tocDrag = null; return d; };
  $('tocList').addEventListener('pointerdown', (e) => {
    const bm = e.target.closest('.toc-bm');
    if (!bm || e.button !== 0) return;
    e.preventDefault();
    tocDrag = { k0: parseInt(bm.closest('.toc-item').dataset.k, 10), k1: -1, y: e.clientY };
    tocDrag.raf = requestAnimationFrame(tocDragScroll);
  });
  document.addEventListener('pointermove', (e) => {
    if (!tocDrag) return;
    tocDrag.y = e.clientY;
    tocDragAt(e.clientY);
  });
  document.addEventListener('pointercancel', tocDragEnd);
  document.addEventListener('pointerup', () => {
    const d = tocDragEnd();
    if (d && d.k1 < 0) tocBmClick(d.k0);
  });
  $('btnReadNotes').onclick = () => setReadNotes(!$('readNotesSlot').classList.contains('open'));
  $('btnReadNotesClose').onclick = () => setReadNotes(false);
  // 簡易查詢（閱讀頁）：在選取的文字旁邊跳出，不擋住上方按鈕
  bindSelBtn('selLookup', () => {
    let a = null;
    try { const sr = window.getSelection(); if (sr && sr.rangeCount) a = sr.getRangeAt(0).getBoundingClientRect(); } catch (e) {}
      closeNotePop();
    if ($('readNotesSlot').classList.contains('open')) setReadNotes(false);   // 筆記總覽要先收起，才不會和查詢欄重疊
    if (typeof readerMenuOpen === 'function') readerMenuOpen(false);
    const q = trunc(selContext.text, 100);
    if (!lkPanel.classList.contains('open')) $('btnLookup').onclick();
    $('lookupInput').value = q;
    $('lookupAnswer').style.display = 'none'; $('lookupAnswer').textContent = ''; $('lookupTools').style.display = 'none';
    lkPanel.classList.add('lk-dock');
    placeDock(lkPanel);
    setDockSpace(true);
    $('lookupInput').focus(); $('lookupInput').select();   // 帶入選取的文字，等使用者確認或修改後再按「查詢」
  });
  document.addEventListener('mousedown', (e) => {
    if (lkPanel.classList.contains('lk-dock') && ((stage === 'read' && e.target.closest('#readerBody')) || (stage === 'guide' && e.target.closest('#guideMain')))) $('btnLookupClose').onclick();
  });
  const rb = $('readerBody');
  // 位置暫存：捲動停下來就記；離開頁面、切到別的視窗時立刻記
  let posT = 0;
  rb.addEventListener('scroll', () => {
    if (stage !== 'read') return;
    clearTimeout(posT);
    posT = setTimeout(() => { savePos(); updateTocActive(); }, 400);
  }, { passive: true });
  document.addEventListener('visibilitychange', () => { if (document.hidden && stage === 'read') savePos(); });
  window.addEventListener('pagehide', () => { if (stage === 'read') savePos(); });
  // 保險：閱讀頁開著時每 3 秒檢查一次，捲動位置有變就存（捲動事件偶爾漏接、電腦突然關機時，最多只損失幾秒）
  let lastTop = -1;
  setInterval(() => {
    if (stage !== 'read' || document.hidden || !doc) return;
    const t = $('readerBody').scrollTop;
    if (t !== lastTop) { lastTop = t; savePos(); }
  }, 3000);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && stage === 'read' && $('readNotesSlot').classList.contains('open')
        && !document.querySelector('.overlay:not(.hidden)') && !$('lookupPanel').classList.contains('open')) setReadNotes(false);
  });


  // 筆記
  $('btnAddNote').onclick = () => {
    if (noteDraftOpen) { const ta = $('noteDraftInput'); if (ta) ta.focus(); return; }
    openNoteDraft();
  };

  // 上傳
  $('btnSample').onclick = () => {
    $('upTitle').value = SAMPLE_TITLE; $('upText').value = SAMPLE_TEXT; $('upFileStatus').textContent = '';
    setUploadMode('paste');
    updateUpCount();
  };
  document.querySelectorAll('#upModeSeg button').forEach(b => { b.onclick = () => setUploadMode(b.dataset.mode); });
  $('btnUploadFile').onclick = () => $('upFile').click();
  $('upFile').addEventListener('change', () => {
    const f = $('upFile').files[0];
    if (f) handleFileUpload(f);
    $('upFile').value = '';   // 允許重選同一檔案
  });
  // 把檔案拖進虛線框
  const dz = $('upModeUpload');
  ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add('over'); }));
  ['dragleave', 'dragend'].forEach(ev => dz.addEventListener(ev, () => dz.classList.remove('over')));
  dz.addEventListener('drop', (e) => {
    e.preventDefault();
    dz.classList.remove('over');
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) handleFileUpload(f);
  });
  $('btnTerms').onclick = () => {
    const t = $('upTermsText');
    t.hidden = !t.hidden;
    $('btnTerms').textContent = t.hidden ? '查看資料保存與使用規範' : '收起使用規範';
    $('btnTerms').setAttribute('aria-expanded', String(!t.hidden));
  };
  $('btnUpClose').onclick = () => $('uploadOverlay').classList.add('hidden');
  $('btnStart').onclick = () => {
    const title = $('upTitle').value.trim() || '未命名文本';
    let text = $('upText').value.trim();
    if (!text) { toast('請先貼上或上傳文本', true); return; }
    if ($('upReflow').checked) text = reflowParagraphs(text);
    if (text.length > FREE_MAX_CHARS) {
      toast(`這篇共 ${text.length.toLocaleString()} 字，超過免費測試上限 ${FREE_MAX_CHARS.toLocaleString()} 字；請刪減，或只保留想閱讀的章節`, true);
      return;
    }
    const d = createDoc(title, text);
    // 原檔:內容沒被改成別的文字才存(換成貼上的文字就不算)
    if (upOrig && $('upText').value.trim() === upOrig.text) {
      const f = upOrig.file;
      d.localOrig = { name: f.name, type: f.type || '' };
      origPut(d.id, { name: f.name, type: f.type || '', blob: f }).catch(() => { delete d.localOrig; saveDocs(); });
    }
    upOrig = null;
    openDoc(d);
    $('upTitle').value = ''; $('upText').value = ''; $('upFileStatus').textContent = ''; $('upReflow').checked = false;
    updateUpCount();
  };
  $('upText').addEventListener('input', updateUpCount);
  $('upTitle').addEventListener('input', () => { $('upTitle').dataset.auto = ''; });   // 自己打的標題,換檔時不覆蓋
  $('btnChangeDoc').onclick = () => {
    if (course && course.courseId) { backToCourseList(); return; }
    openShelf();
  };
  $('btnShelfBack').onclick = closeShelf;
  $('shelfSearch').addEventListener('input', renderShelf);
  $('btnUpExit').onclick = () => {
    // 有閱讀紀錄或課程登入:走完整登出(確認後清除);什麼都沒有:直接回首頁
    if (docs.length || (course && course.courseId)) { logoutAll(); return; }
    ['dupan.audience', 'dupan.nickname'].forEach(k => { try { localStorage.removeItem(k); } catch (e) {} });
    location.reload();
  };
  $('btnBackEntry').onclick = () => {
    $('uploadOverlay').classList.add('hidden');
    audience = '';
    localStorage.removeItem('dupan.audience');
    showCourseGate(true);
    showCourseView('choose');
  };
  $('btnCancelUpload').onclick = () => $('uploadOverlay').classList.add('hidden');

  // 匯出 / 回顧
  // 匯出筆記:入口在「回顧」視窗裡(匯出視窗疊在回顧視窗上面,關掉後回到回顧)
  $('btnSummaryExport').onclick = () => {
    if (!doc) { toast('先開啟一篇文本', true); return; }
    syncExportMapOption();
    $('exportOverlay').classList.remove('hidden');
  };
  $('btnDoExport').onclick = exportMarkdown;
  // 純文字版不含心智圖：滑到（或鍵盤移到）這個格式時，「導讀心智圖」暫時變灰、不能勾
  const mdBtn = $('btnDoExport');
  const mapOff = (off) => {
    if (off) { $('exMap').disabled = true; $('exMapRow').classList.add('is-off'); $('exMapNote').textContent = '（純文字版不含心智圖）'; }
    else syncExportMapOption();
  };
  ['mouseenter', 'focus'].forEach(ev => mdBtn.addEventListener(ev, () => mapOff(true)));
  ['mouseleave', 'blur'].forEach(ev => mdBtn.addEventListener(ev, () => mapOff(false)));
  $('btnDoExportWord').onclick = exportWord;
  $('btnDoExportHtml').onclick = exportHtml;
  $('btnDoExportPdf').onclick = exportPdf;
  $('btnSummary').onclick = () => {
    if (!doc) { toast('先開啟一篇文本', true); return; }
    docSummary();
  };
  document.querySelectorAll('[data-close]').forEach(b => {
    b.onclick = () => {
      $(b.dataset.close).classList.add('hidden');
      if (b.dataset.close === 'summaryOverlay') markReviewTab(false);
    };
  });

  window.addEventListener('resize', applyLayout);
}

function syncPersonaPills() {
  const p = (doc && doc.persona) || 'guide';
  document.querySelectorAll('#personaPills button').forEach(b => {
    b.classList.toggle('on', b.dataset.p === p);
    b.setAttribute('aria-pressed', String(b.dataset.p === p));
  });
}

function adjustReaderFont(dir) {
  const cur = parseFloat(localStorage.getItem('dupan.readerSize') || '17');
  const next = Math.max(13, Math.min(24, cur + dir * 1.5));
  localStorage.setItem('dupan.readerSize', next);
  document.documentElement.style.setProperty('--reader-size', next + 'px');
}


/* ---------------- 版面:欄寬、收合、對話樹/筆記折疊(使用者的調整記在 localStorage) ---------------- */

const LAYOUT_KEY = 'dupan.layout.v2';
const LAYOUT_DEF = { readerW: 480, sideW: 300, treeH: 260, readerC: false, midC: false, sideC: false, treeC: false, notesC: false };
const LAYOUT_LIM = { readerW: [300, 760], sideW: [240, 520], treeH: [120, 560] };
const COL_STRIP = 40, COL_DIV = 9, MID_MIN = 320;
const layout = Object.assign({}, LAYOUT_DEF);
const isNarrow = () => window.matchMedia('(max-width: 980px)').matches;

function loadLayout() {
  try {
    const s = JSON.parse(localStorage.getItem(LAYOUT_KEY) || '{}');
    Object.keys(LAYOUT_DEF).forEach(k => {
      if (typeof s[k] === typeof LAYOUT_DEF[k]) layout[k] = s[k];
    });
    Object.entries(LAYOUT_LIM).forEach(([k, [lo, hi]]) => { layout[k] = Math.max(lo, Math.min(hi, layout[k])); });
    if (layout.readerC && layout.midC && layout.sideC) layout.midC = false;   // 不讓三欄全部收起
  } catch (e) {}
}
function saveLayout() {
  try { localStorage.setItem(LAYOUT_KEY, JSON.stringify(layout)); } catch (e) {}
}

/** 依目前狀態套用版面:讀本與右欄固定寬度、中欄填滿;中欄收合時讀本填滿 */
function applyLayout() {
  const ws = $('workspace');
  if (!ws) return;
  if (document.body.dataset.stage && document.body.dataset.stage !== 'dialogue') return;   // 導讀、閱讀畫面不用三欄版面
  const rOpen = !layout.readerC, mOpen = !layout.midC, sOpen = !layout.sideC;
  ws.classList.toggle('reader-c', !rOpen);
  ws.classList.toggle('mid-c', !mOpen);
  ws.classList.toggle('side-c', !sOpen);
  const divL = rOpen && (mOpen || sOpen), divR = sOpen && (mOpen || rOpen);
  $('colSplitL').classList.toggle('off', !divL);
  $('colSplitR').classList.toggle('off', !divR);

  const reader = $('panelReader'), mid = $('panelChat'), side = $('panelSide');
  if (isNarrow()) {
    reader.style.flex = mid.style.flex = side.style.flex = '';
  } else {
    const total = ws.clientWidth;
    const fixed = (rOpen ? 0 : COL_STRIP) + (mOpen ? 0 : COL_STRIP) + (sOpen ? 0 : COL_STRIP)
      + (divL ? COL_DIV : 0) + (divR ? COL_DIV : 0);
    let rw = rOpen ? layout.readerW : 0, sw = sOpen ? layout.sideW : 0;
    // 視窗太窄、存下的寬度放不下時,依比例縮小(不改存下的設定)
    const room = total - fixed - (mOpen ? MID_MIN : (rOpen && sOpen ? 300 : 0));
    if (rw + sw > room && rw + sw > 0) {
      const k = Math.max(room, 0) / (rw + sw);
      rw = Math.round(rw * k); sw = Math.round(sw * k);
    }
    if (mOpen) {
      reader.style.flex = `0 0 ${rw}px`;
      side.style.flex = `0 0 ${sw}px`;
      mid.style.flex = '1 1 0';
    } else if (rOpen) {
      reader.style.flex = '1 1 0';                  // 中欄收合時,讀本自動填滿
      side.style.flex = `0 0 ${sw}px`;
    } else {
      side.style.flex = '1 1 0';
    }
  }

  // 右欄:對話樹與筆記各自折疊
  side.style.setProperty('--tree-h', layout.treeH + 'px');
  side.classList.toggle('tree-folded', layout.treeC);
  side.classList.toggle('notes-folded', layout.notesC);
  $('treeSec').classList.toggle('folded', layout.treeC);
  $('notesSec').classList.toggle('folded', layout.notesC);
  $('btnToggleTree').setAttribute('aria-expanded', String(!layout.treeC));
  $('btnToggleNotes').setAttribute('aria-expanded', String(!layout.notesC));
  drawTreeEdges();   // 對話樹剛展開或寬度改變時,連線位置要重算
}

const COL_KEY = { reader: 'readerC', mid: 'midC', side: 'sideC' };
function setColCollapsed(which, on) {
  const key = COL_KEY[which];
  if (on && Object.values(COL_KEY).filter(k => k !== key).every(k => layout[k])) {
    toast('至少要留一欄開著', true);
    return;
  }
  layout[key] = on;
  saveLayout();
  applyLayout();
  // 鍵盤使用者:收合後焦點移到直排標籤,展開後回到收合鈕
  const strip = { reader: 'btnExpandReader', mid: 'btnExpandMid', side: 'btnExpandSide' }[which];
  const btn = { reader: 'btnCollapseReader', mid: 'btnCollapseMid', side: null }[which];
  if (on && $(strip)) $(strip).focus();
  else if (!on && btn && $(btn)) $(btn).focus();
}

function setSecFolded(which, on) {
  layout[which === 'tree' ? 'treeC' : 'notesC'] = on;
  saveLayout();
  applyLayout();
}

/** 把某一欄帶到眼前:收合的展開、折疊的打開;窄螢幕(上下堆疊)時捲到那一欄 */
function focusPanel(which) {
  let changed = false;
  if (which === 'reader' && layout.readerC) { layout.readerC = false; changed = true; }
  if (which === 'chat' && layout.midC) { layout.midC = false; changed = true; }
  if (which === 'notes' || which === 'tree') {
    if (layout.sideC) { layout.sideC = false; changed = true; }
    if (which === 'notes' && layout.notesC) { layout.notesC = false; changed = true; }
    if (which === 'tree' && layout.treeC) { layout.treeC = false; changed = true; }
  }
  if (changed) { saveLayout(); applyLayout(); }
  if (isNarrow()) {
    const el = { reader: 'panelReader', chat: 'panelChat', notes: 'panelSide', tree: 'panelSide' }[which];
    if ($(el)) $(el).scrollIntoView({ behavior: scrollMotion(), block: 'start' });
  }
}

/** 分隔線拖曳:拖曳時整頁蓋一層透明遮罩,避免選到文字;雙擊恢復預設;方向鍵每次 16px */
function bindDivider(div, key, axis, sign, measure) {
  const [lo, hi] = LAYOUT_LIM[key];
  const set = (v) => {
    layout[key] = Math.round(Math.max(lo, Math.min(hi, v)));
    applyLayout();
  };
  div.addEventListener('pointerdown', (e) => {
    if (e.button !== 0 || div.classList.contains('off') || isNarrow()) return;
    if (div.id === 'sideSplitter' && (layout.treeC || layout.notesC)) return;
    e.preventDefault();
    const p0 = axis === 'x' ? e.clientX : e.clientY;
    const v0 = measure();
    const shield = $('dragShield');
    shield.className = 'drag-shield on ' + axis;
    div.classList.add('dragging');
    const move = (ev) => set(v0 + sign * ((axis === 'x' ? ev.clientX : ev.clientY) - p0));
    const up = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
      window.removeEventListener('pointercancel', up);
      shield.className = 'drag-shield';
      div.classList.remove('dragging');
      saveLayout();
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
  });
  div.addEventListener('dblclick', () => { layout[key] = LAYOUT_DEF[key]; saveLayout(); applyLayout(); });
  div.addEventListener('keydown', (e) => {
    const step = 16;
    const dec = axis === 'x' ? 'ArrowLeft' : 'ArrowUp', inc = axis === 'x' ? 'ArrowRight' : 'ArrowDown';
    if (e.key !== dec && e.key !== inc) return;
    e.preventDefault();
    set(measure() + sign * (e.key === inc ? step : -step));
    saveLayout();
  });
}

/** 手機/平板的欄位切換列:點了捲到那一欄;捲動時標示目前看到的是哪一欄 */
function bindMobileJump() {
  const btns = [...document.querySelectorAll('#mobileJump button')];
  const panelOf = { reader: 'panelReader', chat: 'panelChat', notes: 'panelSide' };
  const mark = (key) => btns.forEach(b => {
    const on = b.dataset.jump === key;
    b.classList.toggle('on', on);
    b.setAttribute('aria-current', String(on));
  });
  let holdUntil = 0;   // 點了之後捲動途中會經過別欄,這段時間不跟著捲動改標示
  btns.forEach(b => {
    b.onclick = () => { mark(b.dataset.jump); holdUntil = Date.now() + 900; focusPanel(b.dataset.jump); };
  });
  mark('reader');
  // 捲動時:畫面上露出最多的那一欄就是目前所在
  const ws = $('workspace');
  ws.addEventListener('scroll', () => {
    if (!isNarrow() || Date.now() < holdUntil) return;
    const wr = ws.getBoundingClientRect();
    let best = null, bestH = 0;
    Object.entries(panelOf).forEach(([key, id]) => {
      const r = $(id).getBoundingClientRect();
      const h = Math.min(r.bottom, wr.bottom) - Math.max(r.top, wr.top);
      if (h > bestH) { bestH = h; best = key; }
    });
    if (best) mark(best);
  }, { passive: true });
}

function bindLayout() {
  loadLayout();
  bindMobileJump();
  bindDivider($('colSplitL'), 'readerW', 'x', 1, () => $('panelReader').getBoundingClientRect().width);
  bindDivider($('colSplitR'), 'sideW', 'x', -1, () => $('panelSide').getBoundingClientRect().width);
  bindDivider($('sideSplitter'), 'treeH', 'y', 1, () => $('treeSec').getBoundingClientRect().height);
  applyLayout();
}


/* ================= 課程模式:登入 → 文本清單 → 閱讀 ================= */

async function courseApi(payload) {
  const res = await fetch('course_api.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) { const e = new Error(data.error || '連線失敗'); e.status = res.status; throw e; }
  return data;
}

function showCourseGate(show) {
  $('courseGate').classList.toggle('hidden', !show);
}

const CG_VIEWS = { choose: 'cgChoose', login: 'cgLogin', teacher: 'cgTeacher', texts: 'cgTexts',
                   join: 'cgJoin', courses: 'cgCourses', pwd: 'cgPwdCard' };
function showCourseView(which) {
  Object.entries(CG_VIEWS).forEach(([k, id]) => $(id).classList.toggle('hidden', k !== which));
  // 「回上一步」只在有地方可回時出現:文本清單 → 正在讀的這門課的文本;我的課程 → 目前這門課的文本清單
  const reading = !!(doc && doc.courseTextId && course && course.courseId === readingCourseId && docs.includes(doc));
  $('btnCgTextsBack').classList.toggle('hidden', !(which === 'texts' && reading));
  $('btnCgCoursesBack').classList.toggle('hidden', !(which === 'courses' && course && course.courseId));
}

/** 進入自由試用(訪客) */
function enterGuest() {
  audience = 'guest';
  localStorage.setItem('dupan.audience', 'guest');
  $('changeDocLabel').textContent = '更換文本';
  syncDocSwitchTitle();
  // 免費測試不再設定暱稱:清掉以前存下、使用者看不到也改不了的舊值
  nickname = '';
  try { localStorage.removeItem('dupan.nickname'); } catch (e) {}
  showCourseGate(false);
  const lastId = localStorage.getItem(LS_CURRENT);
  const last = docs.find(d => d.id === lastId && !d.courseTextId);
  if (last) { openDoc(last); return; }
  // 讀過但這次沒有「目前文本」:先到書架挑;完全沒讀過:直接上傳
  if (shelfDocs().length) { openShelf(); return; }
  openUploadOverlay(false);
}

/** 打開上傳畫面;canCancel=是否顯示「取消」(已有文本可回去時) */
function openUploadOverlay(canCancel) {
  // 已有文本開著:「更換文本」;第一次進來:「開始共讀」並附上讀伴簡介
  $('upHeading').textContent = doc ? '更換文本' : '選擇文本';
  $('upSub').textContent = doc ? `《${doc.title}》的進度會自動保存，之後可以從「我的書架」回來。`
    : '上傳一篇文本，開始與它對話吧。';
  $('upHero').style.display = doc ? 'none' : '';
  $('btnUpClose').style.display = canCancel ? '' : 'none';
  setUploadMode($('upText').value.trim() ? 'paste' : 'upload');
  $('btnCancelUpload').style.display = canCancel ? '' : 'none';
  // 「回上一步」(回身分選擇)「登出」只在第一次進來(還沒開文本)時需要;換文本時登出在「⋯」選單裡
  $('btnBackEntry').style.display = (!doc && siteMode === 'both' && !(course && course.courseId)) ? '' : 'none';
  // 僅公開模式沒有首頁可回,沒有紀錄時也就不需要登出
  $('btnUpExit').style.display = (doc || (siteMode === 'open' && !docs.length)) ? 'none' : '';
  $('uploadOverlay').classList.remove('hidden');
}

/* ---------------- 學生帳號(email + 密碼,登入狀態由伺服器的 cookie 保存) ---------------- */
let me = null;   // { email, name, mustChange, courses: [{courseId, courseName, sid, name}] }

/** 登入/加入課程/改密碼之後:決定要進哪個畫面 */
function afterAuth(data, preferCourseId) {
  me = data;
  // 同一台電腦換了另一位學生:先清掉前一位的課程閱讀紀錄,免得被當成自己的同步上去
  let prev = '';
  try { prev = localStorage.getItem('dupan.acct') || ''; } catch (e) {}
  if (prev && prev !== me.email) {
    docs = docs.filter(d => !d.courseTextId);
    saveDocs();
    course = null;
  }
  try { localStorage.setItem('dupan.acct', me.email); } catch (e) {}
  if (me.name && !nickname) { nickname = me.name; localStorage.setItem('dupan.nickname', me.name); }
  showCourseGate(true);
  if (me.mustChange) { openPwd(true); return; }
  const want = preferCourseId || (course && course.courseId);
  const c = me.courses.find(x => x.courseId === want) || (me.courses.length === 1 ? me.courses[0] : null);
  if (c) { enterCourse(c); return; }
  if (!me.courses.length) { openJoin(); return; }
  renderMyCourses();
  showCourseView('courses');
}

/** 進入某門課:記住目前課程並載入文本清單 */
function enterCourse(c) {
  course = { courseId: c.courseId, courseName: c.courseName, sid: c.sid, name: (me && me.name) || c.name || '' };
  localStorage.setItem('dupan.course', JSON.stringify(course));
  audience = 'course';
  localStorage.setItem('dupan.audience', 'course');
  $('changeDocLabel').textContent = '返回文本清單';
  syncDocSwitchTitle();
  return backToCourseList();
}

function renderMyCourses() {
  $('cgMe').textContent = me ? me.email : '';
  const list = $('cgCourseList');
  list.innerHTML = '';
  if (!me || !me.courses.length) { list.innerHTML = '<div class="cg-empty">還沒有加入任何課程。請按下方「加入新課程」，輸入老師提供的邀請碼。</div>'; return; }
  me.courses.forEach(c => {
    const b = document.createElement('button');
    b.className = 'cg-item';
    b.innerHTML = `<div class="cg-t-title">${esc(c.courseName)}</div><div class="cg-t-meta">學號 ${esc(c.sid)}</div>`;
    b.onclick = () => enterCourse(c);
    list.appendChild(b);
  });
}

/** 加入課程畫面(一次填完送出);已登入時不必再填 Email 與密碼 */
function openJoin() {
  $('cgJAcct').classList.toggle('hidden', !!me);
  $('cgJHint').textContent = me ? '請輸入新課程的邀請碼（向授課老師索取）與你在這門課的學號。'
                                : '請輸入課程教師所提供的邀請碼，並填寫你的資料。';
  $('cgJErr').textContent = '';
  if (me && me.name && !$('cgName').value) $('cgName').value = me.name;
  showCourseView('join');
  setTimeout(() => $('cgCode').focus(), 50);
}

/** 修改密碼;forced=教師重設的臨時密碼,必須改完才能繼續 */
let pwdForced = false;
function openPwd(forced) {
  pwdForced = !!forced;
  $('cgPUser').value = me ? me.email : '';
  ['cgPCur', 'cgP1', 'cgP2'].forEach(id => { $(id).value = ''; });
  $('cgPErr').textContent = '';
  $('cgPwdHint').textContent = forced
    ? '你目前使用的是老師重設的臨時密碼。請先設定你自己的新密碼（至少 8 個字元）。'
    : '請輸入目前的密碼，再設定新密碼（至少 8 個字元）。';
  $('btnCgPwdBack').classList.toggle('hidden', pwdForced);
  showCourseView('pwd');
}

/** 登入已失效(過期、在別台裝置改了密碼):回登入頁 */
function loginExpired(msg) {
  me = null; course = null;
  localStorage.removeItem('dupan.course');
  showCourseGate(true);
  showCourseView('login');
  $('cgErr').textContent = msg || '登入已過期，請重新登入。';
}

function renderCourseTexts(courseName, texts) {
  $('cgCourseName').textContent = courseName;
  $('cgWho').textContent = `${course.name || ''} ・ ${course.sid}`;
  const list = $('cgList');
  list.innerHTML = '';
  if (!texts.length) {
    list.innerHTML = '<div class="cg-empty">老師還沒有指定文本。請稍後再回來,或詢問老師。</div>';
    return;
  }
  let changed = false;
  texts.forEach(t => {
    const saved = docs.find(d => d.courseTextId === t.id);
    // 老師事後補上(或換掉)原檔:本機已開過的紀錄也跟著更新
    if (saved && (saved.orig || '') !== (t.orig || '')) { saved.orig = t.orig || ''; changed = true; }
    const card = document.createElement('button');
    card.className = 'cg-item' + (t.ready ? '' : ' disabled');
    const state = !t.ready ? '<span class="cg-state pending">尚未上傳內容</span>'
      : (saved ? `<span class="cg-state done">已開始・${docRespCount(saved)} 則回應</span>`
               : '<span class="cg-state">未開始</span>');
    card.innerHTML = `<div class="cg-t-title">${esc(t.title)}</div>
      <div class="cg-t-meta">${t.chars ? t.chars.toLocaleString() + ' 字' : '—'} ${state}</div>`;
    if (t.ready) card.onclick = () => openCourseText(t.id, t.title);
    list.appendChild(card);
  });
  if (changed) saveDocs();
}

/** 把伺服器上的進度放回新建的文本(同一個 docId,之後的自動儲存接著寫同一份紀錄);回傳有沒有接續 */
function restoreProgress(d, p) {
  if (!p || !p.found || !p.nodes || typeof p.nodes !== 'object' || Array.isArray(p.nodes)) return false;
  const nodes = p.nodes, st = p.state || {};
  const trunks = Object.values(nodes).filter(n => n && n.type === 'trunk');
  if (!trunks.length) return false;
  if (p.docId) d.id = p.docId;
  d.nodes = nodes;
  d.notes = Array.isArray(p.notes) ? p.notes : [];
  d.highlights = Array.isArray(p.highlights) ? p.highlights : [];
  // 目前的段落:存下來的那一段;舊紀錄沒有就用最後有對話的主幹
  d.rootId = nodes[st.rootId] ? st.rootId
    : (trunks.filter(t => (t.messages || []).length).sort((a, b) => lastTs(b) - lastTs(a))[0] || trunks[0]).id;
  if (st.bm && typeof st.bm === 'object') d.bm = { head: st.bm.head ?? -1, cur: st.bm.cur ?? -1 };
  const talked = trunks.some(t => (t.messages || []).length);
  if (st.briefingDone || talked) d.briefing = { summary: '', background: '', mindmap: null, done: true };
  if (st.persona) d.persona = st.persona;
  if (st.createdAt) d.createdAt = st.createdAt;
  return true;
}
const lastTs = n => (n.messages || []).reduce((m, x) => Math.max(m, x.ts || 0), 0);
async function openCourseText(textId, title) {
  // 已讀過的直接開啟本機紀錄,保留對話樹
  const existing = docs.find(d => d.courseTextId === textId);
  if (existing) { existing.courseId = course.courseId; showCourseGate(false); openDoc(existing); return; }   // 舊紀錄補上課程
  try {
    toast('載入文本中⋯');
    const data = await courseApi({
      action: 'text', courseId: course.courseId, sid: course.sid, textId
    });
    const d = createDoc(data.title, data.text);
    d.courseTextId = textId;
    d.courseId = course.courseId;
    d.orig = data.orig || '';
    const resumed = restoreProgress(d, await courseApi({ action: 'progress', courseId: course.courseId, sid: course.sid, textId }).catch(() => null));
    saveDocs();
    showCourseGate(false);
    openDoc(d);
    if (resumed) toast('已接續上次的閱讀進度：可在對話樹回到之前的對話，或到「閱讀」用書籤選新的範圍');
  } catch (e) {
    toast(e.message, true);
  }
}

/** 顯示目前課程的文本清單;回傳是否成功取得 */
async function backToCourseList() {
  showCourseGate(true);
  showCourseView('texts');
  $('cgCourseName').textContent = course.courseName || '';
  $('cgWho').textContent = `${course.name || ''} ・ ${course.sid}`;
  try {
    const data = await courseApi({ action: 'texts', courseId: course.courseId, sid: course.sid });
    renderCourseTexts(data.courseName, data.texts);
    return true;
  } catch (e) {
    if (e.status === 401) { loginExpired(); return false; }
    // 課程被刪除或已不在名單中:回「我的課程」(重新取一次帳號資料)
    if (e.status === 403 || e.status === 404) {
      toast(e.message, true);
      course = null; localStorage.removeItem('dupan.course');
      try { afterAuth(await courseApi({ action: 'me' })); } catch (e2) { loginExpired(); }
      return false;
    }
    // 網路不穩、伺服器暫時出錯:保留登入,請學生稍後重新整理
    $('cgList').innerHTML = '<div class="cg-empty">暫時連不上伺服器，請稍後重新整理頁面。（你仍是登入狀態，不必重新登入）</div>';
    return false;
  }
}

/** 學生登出(本機的閱讀紀錄保留;要連紀錄一起清請用上方的完整登出) */
function courseLogout() {
  me = null; course = null;
  localStorage.removeItem('dupan.course');
  localStorage.removeItem('dupan.audience');
  audience = '';
  courseApi({ action: 'logout' }).catch(() => {});
  $('cgPwd').value = '';
  showCourseGate(true);
  showCourseView('login');
}

/** 依站台模式初始化畫面 */
async function initSiteMode() {
  try {
    const res = await fetch('site.php', { cache: 'no-store' });
    const cfg = await res.json();
    siteMode = ['both', 'open', 'course'].includes(cfg.mode) ? cfg.mode : 'both';
    const g = cfg.guest || {};
    if (g.maxChars > 0) FREE_MAX_CHARS = g.maxChars;
    if (g.maxBytes > 0) FREE_MAX_BYTES = g.maxBytes;
    if (g.maxTurns > 0) FREE_MAX_TURNS = g.maxTurns;
  } catch (e) {
    siteMode = 'open';
  }
  document.body.dataset.mode = siteMode;
  $('upLimitText').textContent = `免費試用上限：每篇 ${FREE_MAX_CHARS.toLocaleString()} 字、檔案 ${FREE_MAX_BYTES / 1048576} MB 以內、每篇最多對話 ${FREE_MAX_TURNS} 輪。`;

  // 僅公開:維持原本的上傳流程,不出現入口選擇
  if (siteMode === 'open') {
    audience = 'guest';
    const lastId = localStorage.getItem(LS_CURRENT);
    const last = docs.find(d => d.id === lastId);
    if (last) { openDoc(last); return; }
    if (shelfDocs().length) openShelf(); else openUploadOverlay(false);
    return;
  }


  // 學生帳號:登入狀態存在伺服器的 cookie(180 天),瀏覽器資料被清掉也能直接回來
  if (audience !== 'guest' || (course && course.courseId)) {
    let data = null, offline = false;
    try { data = await courseApi({ action: 'me' }); }
    catch (e) { offline = e.status !== 401; }
    if (data) {
      afterAuth(data);
      // 回到上次讀的課程文本
      if (course && !data.mustChange) {
        const lastId = localStorage.getItem(LS_CURRENT);
        const last = docs.find(d => d.id === lastId && d.courseTextId);
        if (last) { showCourseGate(false); openDoc(last); }
      }
      return;
    }
    // 網路不穩、伺服器暫時出錯:沿用本機記得的課程,不要把學生踢回登入頁
    if (offline && course && course.courseId) { showCourseGate(true); backToCourseList(); return; }
    if (course) {
      // 改用帳號制以前(只憑邀請碼+學號)就加入課程的學生:直接帶到加入表單、預填原學號,
      // 設定一次帳號後會自動接回原本的名單與閱讀紀錄(都以學號記錄)
      let legacy = false, oldCode = '';
      try { legacy = !!course.sid && !localStorage.getItem('dupan.acct'); oldCode = localStorage.getItem('dupan.lastCode') || ''; } catch (e) {}
      const oldSid = course.sid;
      course = null; localStorage.removeItem('dupan.course'); localStorage.removeItem('dupan.audience'); audience = '';
      if (legacy) {
        showCourseGate(true);
        openJoin();
        $('cgSid').value = oldSid;
        if (oldCode) $('cgCode').value = oldCode;
        if (nickname && !$('cgName').value) $('cgName').value = nickname;
        $('cgJHint').textContent = `讀伴改用 Email 帳號登入了。請用老師給的邀請碼設定一次帳號（學號請維持原本的 ${oldSid}），你之前的閱讀紀錄都會保留。之後就用 Email 和密碼登入。`;
        return;
      }
    }
  }

  // 訪客身分已選過:直接進上傳流程
  if (siteMode === 'both' && audience === 'guest') {
    const lastId = localStorage.getItem(LS_CURRENT);
    const last = docs.find(d => d.id === lastId);
    if (last) { showCourseGate(false); openDoc(last); return; }
    enterGuest();
    return;
  }

  showCourseGate(true);
  showCourseView(siteMode === 'course' ? 'login' : 'choose');
}

/* ---------------- 啟動 ---------------- */

(function init() {
  loadDocs();
  bind();
  bindLayout();
  fetch('track.php', { method: 'POST' }).catch(() => {});   // 瀏覽計數
  const size = localStorage.getItem('dupan.readerSize');
  if (size) document.documentElement.style.setProperty('--reader-size', size + 'px');
  initSiteMode();
})();
