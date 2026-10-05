// 首頁「誰在線上」與彈幕（熊哥 2026-10-05）。資料來自 /live/api.php，伺服器端說明見該檔。
// 前景每 10 秒、背景每 60 秒回報一次；彈幕 10 分鐘後消失，本人與資訊部可刪。
// 🔴 彈幕只在本區塊頂端那條飄，不蓋住頁面其他地方（熊哥 10-05：娛樂互動不能干擾主要任務）。
// 不用 WebSocket：站在 Cloudflare Tunnel 後面，百來人輪詢的量很小，少一個要維運的常駐程式。
(function () {
  var box = document.getElementById('live');
  if (!box) return;
  var API = '/live/api.php';
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var seen = {}, first = true, timer = null, lane = 0, LANES = 3;

  box.innerHTML =
    '<div class="live-head"><span class="live-dot" aria-hidden="true"></span><b class="live-n">…</b><span>人在線上</span></div>' +
    '<div class="dm-layer" aria-hidden="true"></div>' +
    '<div class="live-people" aria-live="polite"></div>' +
    '<form class="live-say" autocomplete="off">' +
    '<input name="text" maxlength="40" placeholder="發一則彈幕，10 分鐘後消失" aria-label="彈幕內容">' +
    '<button type="submit">送出</button>' +
    '<button type="button" class="live-toggle" aria-expanded="false">最近彈幕</button>' +
    '</form><p class="live-msg" role="status"></p><ul class="live-list" hidden></ul>';
  var layer = box.querySelector('.dm-layer');
  var people = box.querySelector('.live-people'), n = box.querySelector('.live-n'),
      form = box.querySelector('.live-say'), input = form.querySelector('input'),
      msg = box.querySelector('.live-msg'), list = box.querySelector('.live-list'),
      toggle = box.querySelector('.live-toggle');
  if (reduce) { list.hidden = false; toggle.setAttribute('aria-expanded', 'true'); }   // 不飄字的人直接看清單

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function short(name) { return (name || '').replace(/^\d{3}-/, ''); }
  function avatar(p) {
    var s = el('span', 'live-av', short(p.name).charAt(0) || '?');
    if (p.av) {
      var img = new Image(); img.alt = '';   // 不可設 loading=lazy：還沒放進頁面的圖片永遠不會載入
      img.onload = function () { s.textContent = ''; s.appendChild(img); };
      img.src = '/live/avatar.php?e=' + encodeURIComponent(p.email);
    }
    return s;
  }
  function post(a, data) {
    var body = new URLSearchParams(data);
    return fetch(API + '?a=' + a, { method: 'POST', credentials: 'same-origin', headers: { 'X-Live': '1' }, body: body })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.err || r.status); return j; }); });
  }
  function fly(d, delay) {
    if (reduce) return;
    var b = el('div', 'dm');
    b.appendChild(el('b', null, short(d.name) + '：'));
    b.appendChild(document.createTextNode(d.text));
    b.style.top = (lane++ % LANES) * 30 + 4 + 'px';
    b.style.animationDelay = (delay || 0) + 's';
    b.addEventListener('animationend', function () { b.remove(); });
    layer.appendChild(b);
  }
  function render(j) {
    n.textContent = j.online.length;
    people.textContent = '';
    j.online.forEach(function (p) {
      var c = el('span', 'live-p' + (p.me ? ' me' : ''));
      c.title = p.email;
      c.appendChild(avatar(p));
      c.appendChild(el('span', null, short(p.name) + (p.me ? '（你）' : '')));
      people.appendChild(c);
    });
    list.textContent = '';
    if (!j.dm.length) list.appendChild(el('li', 'live-empty', '最近 10 分鐘沒有彈幕'));
    j.dm.slice().reverse().forEach(function (d) {
      var li = el('li');
      var t = new Date(d.at * 1000);
      li.appendChild(el('time', null, ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2)));
      li.appendChild(el('b', null, short(d.name)));
      li.appendChild(el('span', null, d.text));
      if (d.del) {
        var x = el('button', 'live-del', '刪除'); x.type = 'button';
        x.setAttribute('aria-label', '刪除這則彈幕');
        x.onclick = function () { x.disabled = true; post('del', { id: d.id }).then(poll, function (e) { msg.textContent = e.message; x.disabled = false; }); };
        li.appendChild(x);
      }
      list.appendChild(li);
    });
    var fresh = j.dm.filter(function (d) { return !seen[d.id]; });
    fresh.forEach(function (d, i) { seen[d.id] = 1; fly(d, first ? i * 1.2 : i * 0.6); });
    first = false;
  }
  function poll() {
    clearTimeout(timer);
    return fetch(API + '?a=poll', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(render).catch(function () {})
      .then(function () { timer = setTimeout(poll, document.hidden ? 60000 : 10000); });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var t = input.value.trim();
    if (!t) return;
    msg.textContent = '';
    post('say', { text: t }).then(function () { input.value = ''; poll(); }, function (err) { msg.textContent = err.message; });
  });
  toggle.addEventListener('click', function () {
    list.hidden = !list.hidden;
    toggle.setAttribute('aria-expanded', String(!list.hidden));
  });
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  box.hidden = false;
  poll();
})();
