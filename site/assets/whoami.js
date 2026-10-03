// 頁首右上角顯示登入身分，點了到 M365「我的帳戶」（熊哥 2026-10-04）。
// 身分來自 Cloudflare Access 的 get-identity（已登入才進得來這個站，所以一定拿得到）。
(function () {
  var wrap = document.querySelector('.bar .wrap');
  if (!wrap) return;
  fetch('/cdn-cgi/access/get-identity', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
    var email = j.email || '', name = (j.name || email.split('@')[0] || '').replace(/^\d{3}-/, '');
    if (!email) return;
    var old = wrap.querySelector('.me'); if (old) old.remove();
    var a = document.createElement('a');
    a.className = 'me'; a.href = 'https://myaccount.microsoft.com/'; a.target = '_blank'; a.rel = 'noopener';
    a.title = email + '\n點一下開啟 M365 帳戶設定';
    a.setAttribute('aria-label', '登入身分：' + name + '（' + email + '），開啟 M365 帳戶設定');
    var av = document.createElement('span'); av.className = 'who-av'; av.textContent = name.charAt(0) || '?';
    var tx = document.createElement('span'); tx.className = 'who-tx';
    var b = document.createElement('b'); b.textContent = name;
    var s = document.createElement('small'); s.textContent = email;
    tx.appendChild(b); tx.appendChild(s); a.appendChild(av); a.appendChild(tx);
    wrap.appendChild(a);
  }).catch(function () {});
})();
