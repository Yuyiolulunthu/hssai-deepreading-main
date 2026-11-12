/* 讀伴 ・ 密碼欄位的「眼睛」:點一下顯示密碼,再點一下隱藏。
 * 前台(index.html)與後台(teacher.php、dev.php)共用;頁面上所有 type=password 的欄位自動加上,樣式也由這裡注入。 */
(function () {
  const EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  const EYE_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.1 4.1M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7c1.8 0 3.4-.5 4.8-1.3"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';

  const css = document.createElement('style');
  css.textContent =
    '.pw-wrap{position:relative;display:block;width:100%}' +
    '.pw-wrap>input{width:100%;padding-right:42px!important;box-sizing:border-box}' +
    // 各頁面對 form 裡的 button 有自己的樣式(後台登入框把 button 撐滿整列),這裡一律用 !important 蓋回小圖示
    '.pw-wrap>.pw-eye{position:absolute!important;top:50%!important;right:6px!important;left:auto!important;transform:translateY(-50%)!important;' +
    'width:32px!important;min-width:0!important;height:32px!important;margin:0!important;padding:0!important;border:0!important;border-radius:6px!important;' +
    'background:none!important;box-shadow:none!important;color:#8a817c!important;cursor:pointer;display:flex!important;align-items:center;justify-content:center}' +
    '.pw-wrap>.pw-eye:hover,.pw-wrap>.pw-eye:focus-visible{color:#1F1B1A!important;background:rgba(0,0,0,.05)!important}' +
    '.pw-eye svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}';
  document.head.appendChild(css);

  function enhance(input) {
    if (input.dataset.pwEye) return;
    input.dataset.pwEye = '1';
    const wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    const sync = () => {
      const shown = input.type === 'text';
      btn.innerHTML = shown ? EYE_OFF : EYE;
      btn.setAttribute('aria-label', shown ? '隱藏密碼' : '顯示密碼');
      btn.title = shown ? '隱藏密碼' : '顯示密碼';
      btn.setAttribute('aria-pressed', String(shown));
    };
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      input.type = input.type === 'password' ? 'text' : 'password';
      sync();
      input.focus();
    });
    // 送出表單時恢復成密碼欄位,避免瀏覽器把它當一般文字記住
    if (input.form) input.form.addEventListener('submit', () => { input.type = 'password'; sync(); });
    sync();
    wrap.appendChild(btn);
  }

  function run() { document.querySelectorAll('input[type="password"]').forEach(enhance); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
