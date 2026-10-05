// 頁首右上角顯示登入身分，點了到 M365「我的帳戶」（熊哥 2026-10-04）。
// 身分來自 Cloudflare Access 的 get-identity（已登入才進得來這個站，所以一定拿得到）。
// 資訊部帳號另外多一顆「戰情室」（名單與 lib/itstaff.php 一致；頁面本身另有伺服器端檢查，這裡只是顯示捷徑）
var IT_STAFF = ['black@ccra.org.tw', 'orionlin@cceaccra.onmicrosoft.com', 'irenek@ccra.org.tw', 'sarahshih@ccra.org.tw', 'jack@ccra.org.tw', 'ituncle@ccra.org.tw'];
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
    // M365 大頭貼（熊哥 10-05）；沒設照片的人 avatar.php 回 404，就維持姓名第一個字
    var img = new Image(); img.alt = '';
    img.onload = function () { av.textContent = ''; av.appendChild(img); };
    img.src = '/live/avatar.php?e=' + encodeURIComponent(email);
    var tx = document.createElement('span'); tx.className = 'who-tx';
    var b = document.createElement('b'); b.textContent = name;
    var s = document.createElement('small'); s.textContent = email;
    tx.appendChild(b); tx.appendChild(s); a.appendChild(av); a.appendChild(tx);
    if (IT_STAFF.indexOf(email.toLowerCase()) >= 0 && location.pathname.indexOf('/ops/') !== 0) {
      var o = document.createElement('a'); o.className = 'ops-link'; o.href = '/ops/'; o.textContent = '🛰️ 戰情室';
      wrap.appendChild(o); a.style.marginLeft = '8px';
    }
    wrap.appendChild(a);
  }).catch(function () {});
})();
