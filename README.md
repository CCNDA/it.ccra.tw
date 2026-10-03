# it.ccra.tw — CCRA 資訊服務

中華基督教救助協會（CCRA）資訊部的內部服務入口：同工用協會 M365 帳號登入後，可以申請 AI 工具、報修，並連到每天更新的 Teams 頻道。

> 當你感覺不到資訊部的存在，才是資訊部真正存在的價值。
> 但別忘了，每一個理所當然，都是有人默默付出代價。

## 架構

```
同工瀏覽器 ──► Cloudflare Access（Microsoft Entra ID 登入）──► nginx ──► 靜態頁／PHP
```

- **登入**：Cloudflare Zero Trust Access，身分來源為協會的 Microsoft Entra ID；只允許協會租用戶的帳號。
- **來源端防護**：nginx 只放行 Cloudflare IP（`deploy/cloudflare-only.conf`），PHP 另外驗證 `Cf-Access-Jwt-Assertion`（RS256 簽章、AUD、iss、exp，見 `lib/access.php`）——只信標頭是不夠的。
- **AI 工具使用申請**（`site/ai-apply/`）：信箱取自登入憑證，姓名與部門依 M365 帳號預選；資料存 SQLite（`/var/lib/it-ccra/`，不在網站根目錄）。

## 目錄

| 路徑 | 內容 |
|---|---|
| `site/` | 網站根目錄（`/var/www/it.ccra.tw`） |
| `lib/access.php` | Cloudflare Access JWT 驗證（`/var/www/it-lib/`，放在網站根目錄之外） |
| `deploy/` | nginx 站台設定與 Cloudflare IP 白名單 |

## 部署（Ubuntu + nginx + PHP-FPM 8.3）

```bash
sudo install -d /var/www/it.ccra.tw /var/www/it-lib
sudo cp -r site/* /var/www/it.ccra.tw/
sudo cp lib/access.php /var/www/it-lib/
sudo install -d -m 750 -o www-data -g www-data /var/lib/it-ccra
sudo apt-get install -y php8.3-fpm php8.3-sqlite3
sudo cp deploy/cloudflare-only.conf /etc/nginx/snippets/
sudo cp deploy/nginx-it.ccra.tw.conf /etc/nginx/sites-available/it.ccra.tw
sudo ln -s /etc/nginx/sites-available/it.ccra.tw /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

`lib/access.php` 裡的 `ACCESS_TEAM`、`ACCESS_AUD` 要換成自己 Cloudflare Access 應用的值。Cloudflare IP 範圍會變動，請定期從 <https://www.cloudflare.com/ips/> 更新白名單。

## 不在這裡的東西

- 申請資料、登入者對照表、任何憑證或金鑰——都只存在主機上。
- 通知信（審核／主管知會／申請人確認）由資訊部的維運腳本寄出，不在本專案內。

## 授權

程式碼以 MIT 授權釋出（見 `LICENSE`）。`site/img/` 內的協會標誌為社團法人中華基督教救助協會所有，**不在 MIT 授權範圍內**，未經同意請勿使用。

維護：CCRA 資訊部（IT大蘇 / ituncle-sys）
