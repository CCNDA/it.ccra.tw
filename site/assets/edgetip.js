// 非 Edge 瀏覽器提示（熊哥 2026-10-04：偵測到非 Edge 時，在不干擾操作下給提示；已經有了就不需要提示）。
// ・用 Edge 的人：什麼都不顯示，連首頁快捷列下那行建議也藏起來。
// ・頁面上已經有建議（首頁 .quick-tip）：不再加第二個。
// ・其他頁：頁首下方一條細提示，跟著頁面捲走、不浮在內容上；可關閉，關掉後 14 天內不再出現。
(function () {
  var ua = navigator.userAgent || '';
  var brands = (navigator.userAgentData && navigator.userAgentData.brands) || [];
  var isEdge = /\bEdg(e|A|iOS)?\//.test(ua) || brands.some(function (b) { return /Edge/i.test(b.brand); });
  function run() {
    var inline = document.querySelectorAll('.quick-tip');
    if (isEdge) { inline.forEach(function (n) { n.hidden = true; }); return; }
    if (inline.length) return;
    var KEY = 'edgetip-dismissed';
    try { var t = +localStorage.getItem(KEY); if (t && Date.now() - t < 14 * 864e5) return; } catch (e) {}
    var d = document.createElement('div');
    d.className = 'edgetip'; d.setAttribute('role', 'status');
    d.innerHTML = '<span>💡 建議改用 <a href="https://www.microsoft.com/zh-tw/edge" target="_blank" rel="noopener">Edge 瀏覽器</a>，登入公司帳號後 M365 工具免重複登入。</span>';
    var x = document.createElement('button');
    x.type = 'button'; x.setAttribute('aria-label', '關閉提示'); x.textContent = '×';
    x.onclick = function () { d.remove(); try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {} };
    d.appendChild(x);
    var main = document.querySelector('main');
    if (main) main.insertBefore(d, main.firstChild); else document.body.insertBefore(d, document.body.firstChild);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
