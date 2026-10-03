# it.ccra.tw — CCRA 資訊服務

中華基督教救助協會（CCRA）資訊部的內部服務入口：同工用協會 M365 帳號登入後，可以申請 AI 工具、報修，並連到每天更新的 Teams 頻道。

> 當你感覺不到資訊部的存在，才是資訊部真正存在的價值。
> 但別忘了，每一個理所當然，都是有人默默付出代價。

## 架構

```
同工瀏覽器 ──► Cloudflare Access（Microsoft Entra ID 登入）──► Cloudflare Tunnel ──► 內部主機 nginx（127.0.0.1）──► 靜態頁／PHP
```

- **登入**：Cloudflare Zero Trust Access，身分來源為協會的 Microsoft Entra ID；只允許協會租用戶的帳號。
- **主機在內網**：網站放在協會內部主機，經 Cloudflare Tunnel（cloudflared 由內往外連）對外，主機沒有任何對外開放的埠；nginx 只聽 127.0.0.1。
- **來源端仍驗證登入**：PHP 驗證 `Cf-Access-Jwt-Assertion`（RS256 簽章、AUD、iss、exp，見 `lib/access.php`）——只信標頭是不夠的。
- **AI 工具使用申請**（`site/ai-apply/`）：信箱取自登入憑證，姓名與部門依 M365 帳號預選；資料存 SQLite（`/var/lib/it-ccra/`，不在網站根目錄）。

## 目錄

| 路徑 | 內容 |
|---|---|
| `site/` | 網站根目錄（`/var/www/it.ccra.tw`） |
| `lib/access.php` | Cloudflare Access JWT 驗證（`/var/www/it-lib/`，放在網站根目錄之外） |
| `deploy/` | nginx 站台設定、部署腳本與 systemd timer |

## 發布：推上 GitHub 就上線

`main` 分支是正式版。主機每 2 分鐘檢查一次（`it-ccra-deploy.timer`），有新提交就執行 `deploy/deploy.sh`：同步 `site/`、`lib/`，nginx 設定有變才重新載入。

採「主機自己拉」而不是 GitHub Actions 推：主機在內網沒有對外入口，而公開程式庫不宜掛 self-hosted runner。

## 首次安裝（Ubuntu + nginx + PHP-FPM）

```bash
sudo apt-get install -y nginx php-fpm php-sqlite3 rsync git
sudo git clone https://github.com/CCNDA/it.ccra.tw.git /opt/it.ccra.tw
sudo install -d /var/www/it.ccra.tw /var/www/it-lib
sudo install -d -m 750 -o www-data -g www-data /var/lib/it-ccra
sudo ln -s /etc/nginx/sites-available/it.ccra.tw /etc/nginx/sites-enabled/
sudo cp /opt/it.ccra.tw/deploy/it-ccra-deploy.{service,timer} /etc/systemd/system/
sudo /opt/it.ccra.tw/deploy/deploy.sh --force
sudo systemctl enable --now it-ccra-deploy.timer
```

`lib/access.php` 裡的 `ACCESS_TEAM`、`ACCESS_AUD` 要換成自己 Cloudflare Access 應用的值。Tunnel 的 ingress 設定為 `it.ccra.tw → http://localhost:80`。

## 不在這裡的東西

- 申請資料、登入者對照表、任何憑證或金鑰——都只存在主機上。
- 通知信（審核／主管知會／申請人確認）由資訊部的維運腳本寄出，不在本專案內。

## 授權

程式碼以 MIT 授權釋出（見 `LICENSE`）。`site/img/` 內的協會標誌為社團法人中華基督教救助協會所有，**不在 MIT 授權範圍內**，未經同意請勿使用。

維護：CCRA 資訊部（IT大蘇 / ituncle-sys）
