# tianchicaoben.com 自宅サーバー・WeChatテスト環境構築手順

最終更新: 2026-09-02

対象構成:

```text
WeChat開発者ツール / 体験メンバー
              |
              | HTTPS / WSS
              v
 test.tianchicaoben.com
              |
       Cloudflare Tunnel
              |
              v
 http://127.0.0.1:8080
              |
       自宅Windowsサーバー
              |
 Docker: Nginx + PHP + MySQL + Redis + Workerman
```

この環境の到達点は、WeChatの**開発版・体験版によるテスト**である。中国本土向け正式版の
一般公開環境ではない。正式公開は
[海外運用会社向け本番リリース手順](wechat-mini-program-overseas-cloudflare-release.md)へ移行する。

## 0. 2026-09-02時点の確認結果

| 項目 | 確認結果 |
| --- | --- |
| `tianchicaoben.com` 権威DNS | `dns1.onamae.com` / `dns2.onamae.com`。現在はCloudflare DNSではない |
| apexの現在のAレコード | `150.95.255.38` |
| `test.tianchicaoben.com` | 同じIPへ解決。Cloudflare Tunnel用CNAMEへ置き換えが必要 |
| HTTPS | apex、`test` とも443番へ接続できなかった |
| Docker Desktop | 停止中 |
| Cloudflared Windowsサービス | 稼働中・自動起動 |
| cloudflared | `2026.7.3` |
| Docker ComposeのWebポート | `8080:80` |
| MySQLデータ | `help/docker/mysql/data` に既存データあり |
| `install.lock` | 存在するためインストール済み |
| HBuilderX | インストール済み |
| 微信开发者工具 | 未検出。別途インストールが必要 |
| ミニプログラムAppID | CRMEBデモ値のまま |
| ミニプログラムAPI | 現在は `https://tianchibencao.goodworld.co.jp` |

DNS、サービス、接続状態は変化するため、実施日に同じ確認を再実行する。

## 1. 使用するホスト名

テストにはapexの `tianchicaoben.com` ではなく、次を使う。

```text
https://test.tianchicaoben.com
```

理由:

- apexにある既存Webサイトやメール設定と分離できる。
- Cloudflare Tunnelの削除・再作成が容易。
- 将来、中国本土クラウドへ `api.tianchicaoben.com` を作る際に混同しない。

## 2. 事前準備

### 必要なアカウント・アプリ

- お名前.comのドメイン管理権限
- Cloudflareアカウント
- Windows自宅サーバーの管理者権限
- Docker Desktop
- HBuilderX
- 微信开发者工具
- 微信公众平台のミニプログラムAppID

### 先に記録するもの

- 現在のお名前.com DNSレコード全部
- MX、TXT、SPF、DKIM、DMARCレコード
- Cloudflareが割り当てる2つのネームサーバー
- 現在稼働中のCloudflare Tunnel名と接続先アカウント
- 現在のDBバックアップ

Cloudflareへネームサーバーを変更する前にMX/TXTを移さないと、メールが停止する可能性がある。

## 3. Dockerを安全に起動する

### 3-1. Docker Desktopを起動する

WindowsでDocker Desktopを起動し、完全に起動するまで待つ。

**Windows PowerShell:**

```powershell
docker info
```

エラーが出ず、Server情報が表示されればよい。

### 3-2. ポート公開を自宅PC内に限定する

現在の `help/docker/docker-compose.yml` は複数ポートを全ネットワークへ公開している。
Cloudflare Tunnelは同じPCから接続するため、インターネットやLANへ直接公開する必要はない。

公開前に次の形へ変更する。

| サービス | 現在 | テスト時の推奨 |
| --- | --- | --- |
| MySQL | `33062:3306` | `127.0.0.1:33062:3306` |
| Redis | `63792:6379` | `127.0.0.1:63792:6379` |
| PHP-FPM | `9000:9000` | ホスト公開を削除 |
| Workerman | `40001/40002` | ホスト公開を削除。NginxからDocker内部接続 |
| Nginx | `8080:80` | `127.0.0.1:8080:80` |

最低限、Windows Defender Firewallでも33062、63792、9000、40001、40002を外部から許可しない。

`APP_DEBUG` は現在 `true` のため、Cloudflare公開前に `false` へ変更する。Compose内のDB/Redis
パスワードも弱い初期値なので、外部テスターを招待する前に変更する。既存MySQLデータがある場合、
環境変数だけ変えても既存DBユーザーのパスワードは変わらないため、DB内のユーザー変更とPHP側設定を
同時に行う。

### 3-3. Nginxのホスト名を変更する

`help/docker/nginx/vhost.conf`:

```nginx
server_name test.tianchicaoben.com;
```

`/notice` と `/msg` のWebSocketプロキシ設定は現在のファイルに存在するため残す。

### 3-4. コンテナを起動する

**Windows PowerShell:**

```powershell
cd D:\Local\apps\CRMEB\help\docker
docker compose config
docker compose up -d
docker compose ps
```

`mysql`、`redis`、`phpfpm`、`nginx` が起動していることを確認する。

次の操作はしない。

```text
docker compose down -v
help/docker/mysql/data の削除
crmeb/public/install.lock の削除
```

いずれもデータ消失または再インストールにつながる。

### 3-5. DBをバックアップする

コンテナ起動後、既存の認証情報を使って `mysqldump` を取得し、Dockerデータディレクトリとは
別の物理ディスクまたは暗号化ストレージへコピーする。バックアップファイルから別DBへ復元できることも
確認する。パスワードをコマンド履歴や手順書へ直接書かない。

### 3-6. ローカル動作を確認する

**Windows PowerShell:**

```powershell
curl.exe -I http://127.0.0.1:8080/
curl.exe http://127.0.0.1:8080/api/get_lang_type_list
docker compose logs --tail 100 nginx phpfpm
```

期待結果:

- Web画面が200またはアプリの正常なリダイレクトを返す。
- APIがJSONを返す。
- Nginx/PHPログに致命的エラーがない。

ローカルが動かない状態でCloudflare設定へ進まない。

## 4. tianchicaoben.comをCloudflare DNSへ移す

Cloudflare Free/Proの一般的な構成では、Cloudflareを権威DNSにするためネームサーバー変更が必要である。

公式手順: <https://developers.cloudflare.com/dns/zone-setups/full-setup/setup/>

### 4-1. Cloudflareへドメインを追加する

1. Cloudflare Dashboardへログインする。
2. 「Add a domain / Onboard a domain」で `tianchicaoben.com` を追加する。
3. DNS自動取り込み後、現在のお名前.com DNSレコードと1件ずつ比較する。
4. apex、`www`、MX、TXT、SPF、DKIM、DMARCを確認する。
5. Cloudflareが割り当てた2つのネームサーバーを記録する。

### 4-2. お名前.comでネームサーバーを変更する

1. お名前.com Naviで `tianchicaoben.com` を選択する。
2. ネームサーバー設定を開く。
3. Cloudflare画面に表示された2つのネームサーバーへ変更する。
4. 古いDNSSEC DSレコードが設定されている場合は、Cloudflare公式手順に従って移行する。
5. Cloudflare Dashboardが `Active` になるまで待つ。

Cloudflareが指定したネームサーバーは手入力で推測せず、画面からそのままコピーする。

### 4-3. DNSを確認する

**Windows PowerShell:**

```powershell
Resolve-DnsName tianchicaoben.com -Type NS
Resolve-DnsName test.tianchicaoben.com
```

NSが `*.ns.cloudflare.com` になれば権威DNS移行は完了である。

## 5. Cloudflare Tunnelを設定する

Cloudflare Tunnelはローカルアプリを公開ホスト名へ接続でき、ルーターの80/443番ポート転送は不要である。

公式手順: <https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/routing-to-tunnel/>

### 5-1. 既存Cloudflaredサービスを確認する

このPCではCloudflared Windowsサービスが既に稼働している。二重にサービスをインストールしない。

1. Cloudflare Zero Trust Dashboardを開く。
2. 「Networks → Tunnels」で接続済みのTunnelを確認する。
3. このPCのconnectorが `Healthy` か確認する。
4. 正しいCloudflareアカウントのTunnelなら、そのTunnelを再利用する。

別アカウントのTunnelだった場合は、既存ホストへの影響を確認してからメンテナンス時間を決め、
正しいアカウントのTunnelへ切り替える。Tunnel tokenは手順書、Git、チャットへ貼らない。

### 5-2. Published applicationを追加する

Tunnelの「Published application routes」に次を追加する。

| 項目 | 値 |
| --- | --- |
| Hostname | `test.tianchicaoben.com` |
| Service type | HTTP |
| URL | `http://127.0.0.1:8080` |

Dashboardからrouteを追加すると、通常はTunnelの `<UUID>.cfargotunnel.com` を指すCNAMEが作成される。
既存の `test` Aレコードが残っている場合は競合させず、Tunnel用CNAMEへ置き換える。

### 5-3. Cloudflare設定

| 設定 | テスト時の値 |
| --- | --- |
| Always Use HTTPS | ON |
| WebSockets | ON |
| Development Mode | ON、またはキャッシュ無効 |
| Rocket Loader | OFF |
| Auto Minify | OFF |
| `/api/*` | Bypass Cache |
| `/notice*`, `/msg*` | Bypass Cache |
| `/admin*`, `/install*` | Bypass Cache |
| 決済callback | Bypass Cache |

Cloudflareはproxied WebSocketをサポートする。公式説明:
<https://developers.cloudflare.com/network/websockets/>

### 5-4. Cloudflare Accessの適用範囲

WeChatクライアントはCloudflare Accessのログイン画面を処理できないため、
`test.tianchicaoben.com` 全体や `/api/*` にAccessを設定しない。

Accessを使用する場合は次だけを対象にする。

- `test.tianchicaoben.com/admin*`
- `test.tianchicaoben.com/install*`

`install.lock` は存在するが、`/install/*` はNginxまたはCloudflare WAFでも遮断する。

### 5-5. 公開URLを確認する

**Windows PowerShell:**

```powershell
Resolve-DnsName test.tianchicaoben.com
curl.exe -I https://test.tianchicaoben.com/
curl.exe https://test.tianchicaoben.com/api/get_lang_type_list
```

Cloudflare DashboardでTunnelがHealthy、外部HTTPSとAPIが成功することを確認する。

## 6. CRMEBをテストURLへ切り替える

### 6-1. CRMEB管理画面

`https://test.tianchicaoben.com/admin/` を開き、次を設定する。

| 設定 | 値 |
| --- | --- |
| `site_url` | `https://test.tianchicaoben.com` |
| `routine_appId` | 本案件のミニプログラムAppID |
| `routine_appsecret` | 本案件のAppSecret。サーバー側だけに保存 |
| `routine_name` | ミニプログラム名 |

商品画像やDB内の絶対URLに旧ドメインが残っていないか確認する。

### 6-2. ミニプログラム側

`template/uni-app/manifest.json`:

- `mp-weixin.appid` をCRMEBデモ値から本案件AppIDへ変更する。
- 開発版・体験版テスト中は `urlCheck: false` を使用する。

`template/uni-app/config/app.js`:

```javascript
HTTP_REQUEST_URL: `https://test.tianchicaoben.com`,
```

準備用スクリプトを利用できる。

**Windows PowerShell:**

```powershell
cd D:\Local\apps\CRMEB\template\uni-app
.\scripts\check-env.ps1 -ExpectedSiteUrl "https://test.tianchicaoben.com"
.\scripts\build-miniprogram.ps1 -SiteUrl "https://test.tianchicaoben.com"
```

2本目は `config/app.js` を変更するため、実行後にGit差分を確認する。実ビルドはHBuilderXで行う。

AppSecret、決済APIキー、証明書秘密鍵を `manifest.json`、`project.config.json`、Gitへ保存しない。
現在の `manifest.json` にはsecret形式の値があるため、用途を確認し、実値なら削除とローテーションを行う。

## 7. WeChat開発版・体験版を作る

### 7-1. 微信开发者工具をインストールする

現在このPCでは検出できていない。

公式ダウンロード:
<https://developers.weixin.qq.com/miniprogram/dev/devtools/download.html>

### 7-2. HBuilderXでビルドする

1. HBuilderXで `D:\Local\apps\CRMEB\template\uni-app` を開く。
2. `manifest.json` のAppIDとAPI URLを確認する。
3. 「発行 → 微信小程序」を実行する。
4. `unpackage/dist/build/mp-weixin` が生成されたことを確認する。

### 7-3. 微信开发者工具で開く

1. `unpackage/dist/build/mp-weixin` をインポートする。
2. 正しいAppIDを選択する。
3. 開発テストでは「合法域名のチェックを行わない」を有効にする。
4. コンパイルし、NetworkでAPIが `https://test.tianchicaoben.com/api/` を向くことを確認する。

### 7-4. 体験版

1. 微信开发者工具からコードをアップロードする。
2. 微信公众平台「バージョン管理」で体験版に設定する。
3. テストメンバーを追加する。
4. 実機で調試モードを有効にして確認する。

WeChat側の現在のアカウント種別・备案状態によっては、体験版でもドメイン通信が制限される可能性がある。
エラー時は微信开发者工具のNetworkと微信公众平台の服务器域名設定を確認する。

## 8. テスト項目

- [ ] `https://test.tianchicaoben.com` がHTTPSで開く
- [ ] `/api/get_lang_type_list` がJSONを返す
- [ ] Cloudflare経由で自宅のグローバルIPや8080番を直接公開していない
- [ ] トップページと商品画像が表示される
- [ ] 中国語・日本語・英語の切替が動く
- [ ] 新規登録、WeChatログイン、再ログインが動く
- [ ] カート、住所、送料、在庫、注文が動く
- [ ] 決済を使う場合、テスト注文・失敗・キャンセル・返金を確認した
- [ ] 画像アップロードが動く
- [ ] `/notice`、`/msg` のWebSocket接続と再接続が動く
- [ ] 管理画面へ注文と在庫が反映される
- [ ] Cloudflare AccessがAPIを遮断していない
- [ ] PC再起動後、DockerとTunnelを復旧できる
- [ ] DBバックアップから復元できる

## 9. 障害切り分け

1. `docker info` が失敗 → Docker Desktopを起動する。
2. `http://127.0.0.1:8080` が失敗 → Compose、Nginx、PHPログを確認する。
3. ローカル成功、外部HTTPS失敗 → Cloudflare NS、DNS CNAME、Tunnel Healthy状態を確認する。
4. Webは成功、WeChat API失敗 → `HTTP_REQUEST_URL`、AppID、ドメイン検証、Accessを確認する。
5. 画像だけ失敗 → `site_url`、画像URL、Cloudflareキャッシュを確認する。
6. WebSocketだけ失敗 → `/notice` `/msg`、Workerman、WebSockets設定を確認する。
7. 挙動が古い → 実行中コンテナが `../../crmeb:/var/www` をマウントしているか確認する。

## 10. テスト終了・本番移行

テストを止める場合はCloudflareのPublished application routeと `test` CNAMEを無効化する。
DBデータを削除せず、バックアップとテスト結果を保存する。

中国本土向け正式版では、次をテスト環境から流用しない。

- 自宅PCを本番サーバーとして使用すること
- `urlCheck: false`
- 調試モード依存
- 未备案のAPIドメイン
- 弱い初期パスワード
- `APP_DEBUG: true`

正式公開前に中国本土クラウド、备案済みドメイン、合法域名、正式AppID、決済、監視、バックアップへ
切り替える。
