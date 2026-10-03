// 首頁快捷列下的 Edge 建議：用 Edge 的人不顯示（熊哥 2026-10-04：已經有了就不需要提示；只需要首頁有）。
(function () {
  var ua = navigator.userAgent || '';
  var brands = (navigator.userAgentData && navigator.userAgentData.brands) || [];
  var isEdge = /\bEdg(e|A|iOS)?\//.test(ua) || brands.some(function (b) { return /Edge/i.test(b.brand); });
  if (!isEdge) return;
  function run() { document.querySelectorAll('.quick-tip').forEach(function (n) { n.hidden = true; }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
