# テスト環境構築手順（自宅PC Docker + Cloudflare Tunnel + WeChat ミニプログラム）

日本の自宅PC上の Docker で現行ソースを動かし、Cloudflare Tunnel で HTTPS 公開して、
WeChat ミニプログラムと連携させるまでの手順です。

---

## 0. 最初に把握しておくべき制約

### ミニプログラムの「リリース」には ICP 備案が必要です

WeChat ミニプログラムは、通信先ドメインを **微信公众平台の「服务器域名」ホワイトリスト**に
登録する必要があり、**登録には ICP 備案済みドメインが要求されます**。
自宅PC + Cloudflare の一時ドメインでは登録できません。

| 版 | ドメイン検証 | 自宅PC構成で使えるか |
| --- | --- | --- |
| **开发版**（開発者ツール） | `urlCheck: false` で回避可 | ✅ **使える** |
| **开发版・体验版**（実機、調試モードON） | 調試モードで回避可 | ✅ **使える**（招待した端末のみ） |
| **正式版**（審査提出・一般公開） | **回避不可** | ❌ **使えない** |

つまり本ドキュメントで到達できるのは **「开发版・体验版での動作確認」**までです。
**正式リリースには備案済みドメインへの移行が必須**で、これは前回の
[china-access-via-hongkong.md](china-access-via-hongkong.md) で触れたとおり
香港ホスティングでも解決しません（ミニプログラムは備案が前提）。

テスト目的としては十分に機能するので、まず動かして確認する段階として進めます。

### ビルドには HBuilderX が必要です

`template/uni-app/package.json` は**空**（scripts も依存も無し）で、CLI ビルドの構成が
ありません。このプロジェクトは **HBuilderX（DCloud の IDE）前提**です。

---

## 1. 自宅PC で Docker を起動する

### 1-0. ⚠️ 現在動いているコンテナはリポジトリのソースを使っていません

作業前に必ず確認してください。現状を調べた結果は次のとおりです。

```
コンテナ名: crmeb
イメージ:   ccr.ccs.tencentyun.com/crmebky_php/crmebky:latest
ポート:     8080 -> 80
マウント数: 0        ← ホストのリポジトリを一切参照していない
```

- ソースは**イメージ内に焼き込まれた別のコピー**（`/var/www/crmeb`、2026-03-23 時点）です
- 実際に `crmeb/config/phone.php` も `app/lang/ja_jp.php` も**コンテナ内には存在しません**。
  つまり**今回追加した国際電話番号・メール登録・日本語パックのコードは動いていません**

実測で確認した結果（現行コンテナ、ポート8080）:

```
GET /api/get_lang_type_list    → HTTP 200   (元から存在するAPI)
GET /api/get_dial_code_list    → HTTP 404   (今回追加したAPI → 反映されていない)
ls /var/www/crmeb/config/phone.php → No such file or directory
docker inspect crmeb --format '{{len .Mounts}}' → 0
```
- nginx / php-fpm / mysql / redis / queue / timer / workerman を1コンテナに同居させた
  オールインワン構成で、supervisord が全て自動起動しています

**さらに重要な点**として、マウントが0のため **MySQL のデータもコンテナの書き込みレイヤ上**にあります。
`docker rm` や再作成を行うと、**適用済みのDB変更（3言語設定・電話番号カラム拡張・`email` 列追加）が
すべて消えます**。バックアップを取ってから作業してください。

```bash
docker exec crmeb sh -c "mysqldump -ucrmeb -p123456 --default-character-set=utf8mb4 \
  --single-transaction crmeb > /tmp/crmeb_backup.sql"
docker cp crmeb:/tmp/crmeb_backup.sql ./crmeb_backup.sql
```

「**現在のソースを動かす**」には、リポジトリをバインドマウントする構成へ切り替える必要があります。
`help/docker/docker-compose.yml` はそれ用の構成です（`../../crmeb:/var/www` をマウント）。

### 1-1. リポジトリ同梱の Docker 構成

`help/docker/docker-compose.yml` の値は次のとおりです。

| サービス | コンテナ名 | ホスト側ポート |
| --- | --- | --- |
| MySQL 8.0 | `crmeb_mysql` | 33062 |
| Redis | `crmeb_redis` | 63792 |
| PHP-FPM 7.4 | `crmeb_php` | 9000, 40001, 40002 |
| Nginx | `crmeb_nginx` | **8011** |

こちらはリポジトリの `crmeb/` を `/var/www` にマウントするため、
**ソースを編集すればそのまま反映されます**（nginx の root は `/var/www/public`）。

アプリは `http://localhost:8011` で見えます。

### 1-2. 既存コンテナを止めてから起動する

ポートとコンテナ名の衝突を避けるため、先に現行コンテナを停止します。
**`docker rm` はしないでください**（DBが消えます）。停止だけなら data は残ります。

```bash
docker stop crmeb          # 削除ではなく停止

cd help/docker
docker compose up -d
docker compose ps
```

> 既存コンテナのDBを引き継ぎたい場合は、上記のバックアップを新しい MySQL に取り込みます。
> ```bash
> docker cp ./crmeb_backup.sql crmeb_mysql:/tmp/
> docker exec crmeb_mysql sh -c "mysql -ucrmeb -p123456 --default-character-set=utf8mb4 crmeb < /tmp/crmeb_backup.sql"
> ```
> 引き継ぐ場合は次の「インストーラ実行」は不要です（`.env` と `install.lock` も既にあります）。

### 1-3. Nginx の server_name を変える

`help/docker/nginx/vhost.conf` の `server_name` が `bz.crmeb.com` のままです。
Cloudflare 経由のホスト名を受け付けるよう変更します。テスト用途なら全許可で構いません。

```nginx
server_name _;
```

変更後は `docker compose restart nginx`。

### 1-4. インストーラを実行（新規に作る場合）

ブラウザで `http://localhost:8011/install/index.php` を開きます。

> すでに `crmeb/public/install.lock` がある場合、インストール済みです。
> 作り直す場合は `install.lock` を削除してから再実行してください（**DBも作り直されます**）。

DB接続情報は Docker のコンテナ名で指定します（ホストからではなくコンテナ間通信）。

| 項目 | 値 |
| --- | --- |
| データベースホスト | `crmeb_mysql` |
| ポート | `3306` |
| ユーザー / パスワード | `crmeb` / `123456` |
| データベース名 | `crmeb` |

インストール後、`crmeb/public/install/` は外部から見えないようにしてください。

### 1-4b. 現在のソースが動いているかを確認する

**ここを必ず確認してください。** 1-0 の問題（イメージ内の古いソースが動く）を踏んでいないかの検証です。

```bash
# 今回追加したファイルがコンテナから見えるか
docker exec crmeb_php sh -c "ls -l /var/www/config/phone.php /var/www/app/lang/ja_jp.php"

# API が国番号一覧を返すか（今回追加したエンドポイント）
curl -s http://localhost:8011/api/get_dial_code_list | head -c 200
```

`phone.php` と `ja_jp.php` が見え、`get_dial_code_list` が国番号のJSONを返せば、
リポジトリのソースで動いています。`No such file` や 404 が返る場合はマウントを見直してください。

### 1-5. 日本語を既定言語にする（任意）

管理画面のテストを日本語で行う場合は次を適用し、キャッシュを消します。

```bash
docker exec crmeb_mysql mysql -ucrmeb -p123456 --default-character-set=utf8mb4 crmeb \
  -e "UPDATE eb_lang_type SET is_default=0; UPDATE eb_lang_type SET is_default=1 WHERE id=6;"
find crmeb/runtime/cache -type f -name "*.php" -delete
```

`id` は 1=中文 / 2=English / 6=日本語 です。
**ミニプログラムのテストが中国語想定なら、既定は 1（中文）のままにしてください。**

---

## 2. Cloudflare Tunnel で HTTPS 公開する

自宅PCはグローバルIPが固定でなく、ポート開放も避けたいので **Cloudflare Tunnel**
（旧 Argo Tunnel）を使います。ルーターの設定変更も固定IPも不要で、HTTPS証明書も自動です。

### 2-1. 前提

- Cloudflare アカウント
- **Cloudflare に登録済みの独自ドメイン**（ネームサーバーを Cloudflare に向けたもの）

> 独自ドメインが無い場合、`cloudflared tunnel --url` で使い捨ての
> `*.trycloudflare.com` URL も発行できます。ただし**再起動ごとにURLが変わる**ため、
> ミニプログラム側の設定を毎回書き換えることになります。テストでも独自ドメインを推奨します。

### 2-2. cloudflared をインストールしてトンネルを作る

```bash
cloudflared tunnel login
cloudflared tunnel create crmeb-test
cloudflared tunnel route dns crmeb-test crmeb-test.example.com
```

### 2-3. 設定ファイル

`~/.cloudflared/config.yml`（Windows は `%USERPROFILE%\.cloudflared\config.yml`）

```yaml
tunnel: crmeb-test
credentials-file: C:\Users\<user>\.cloudflared\<TUNNEL_ID>.json

ingress:
  # WebSocket（通知・チャット）。CRMEB は workerman を 40001/40002 で待ち受ける
  - hostname: crmeb-test.example.com
    path: ^/notice
    service: http://localhost:8011
  - hostname: crmeb-test.example.com
    path: ^/msg
    service: http://localhost:8011
  # 本体
  - hostname: crmeb-test.example.com
    service: http://localhost:8011
  - service: http_status:404
```

`/notice` と `/msg` は Nginx 側で 40001 / 40002 にプロキシされる設定が既に入っているため、
トンネルからは 8011 に流すだけでよいです。

### 2-4. 起動

```bash
cloudflared tunnel run crmeb-test
```

`https://crmeb-test.example.com` でアクセスできることを確認します。

### 2-5. Cloudflare 側の設定

| 設定 | 値 | 理由 |
| --- | --- | --- |
| SSL/TLS モード | **Full**（または Flexible） | オリジンが HTTP のため Full strict は不可 |
| Always Use HTTPS | ON | ミニプログラムは HTTPS 必須 |
| WebSocket | **ON** | 通知・チャットに必要 |
| Caching | 開発中は **Development Mode ON** | 静的ファイルのキャッシュで混乱しないように |
| Rocket Loader / Auto Minify | **OFF** | JS を書き換えられて動作不良の原因になる |

### 2-6. サイトURLを設定する

管理画面「設定 → 基本設定」で `site_url` を `https://crmeb-test.example.com` にします。
現在は未設定です。決済コールバックや画像URLの生成に使われるため必ず入れてください。

---

## 3. WeChat ミニプログラムを準備する

### 3-1. AppID を用意する

`template/uni-app/manifest.json` の `mp-weixin.appid` は **CRMEB のデモ用**
（`wx3b82801238ca1b57`）です。**自分の AppID に必ず差し替えてください。**

微信公众平台（https://mp.weixin.qq.com）でミニプログラムを登録し、AppID と AppSecret を取得します。

> 個人アカウントでも登録できますが、**個人では微信支付が使えません**。決済までテストするなら
> 企業アカウントが必要です。表示・登録動線のテストだけなら個人でも足ります。

### 3-2. CRMEB 側にミニプログラム情報を設定

管理画面「設定 → 微信設定 → 小程序」で以下を入力します（現在すべて未設定です）。

| 設定キー | 内容 |
| --- | --- |
| `routine_appId` | ミニプログラムの AppID |
| `routine_appsecret` | AppSecret |
| `routine_name` | ミニプログラム名 |

### 3-3. uni-app の接続先を変更

`template/uni-app/config/app.js` の `HTTP_REQUEST_URL` がデモ環境を指しています。

```js
// #ifdef MP || APP-PLUS
HTTP_REQUEST_URL: `https://tianchibencao.goodworld.co.jp`,   // ← ここを変更
// #endif
```

自分のトンネルURLに変更します。

```js
HTTP_REQUEST_URL: `https://crmeb-test.example.com`,
```

**H5 側は `window.location` から自動取得**するため変更不要です。
ミニプログラムとAPPだけがこの設定を使います。

### 3-4. ドメイン検証の扱い

`manifest.json` の `mp-weixin.setting.urlCheck` は既に `false` になっています。

```json
"mp-weixin" : {
    "appid" : "<自分のAppID>",
    "setting" : {
        "urlCheck" : false,
```

これで**開発者ツール上ではホワイトリスト未登録のドメインでも通信できます**。
実機で確認する場合は、微信アプリ内でミニプログラムを開き
**「開発」→「調試モードON」**にしてください。

---

## 4. ビルドとリリース

### 4-1. HBuilderX でビルド

1. HBuilderX（https://dcloud.io/hbuilderx.html）をインストール
2. 「ファイル → インポート → 既存プロジェクト」で `template/uni-app` を開く
3. 「発行 → 微信小程序」を選ぶ
4. AppID を確認して実行 → `unpackage/dist/build/mp-weixin` が生成される

> HBuilderX の設定で微信開発者ツールのインストールパスを指定しておくと、
> ビルド後に自動で開発者ツールが起動します。

### 4-2. 微信開発者ツールで確認

1. 微信開発者ツールで `unpackage/dist/build/mp-weixin` を開く
2. 「詳細 → ローカル設定」で **「合法域名のチェックを行わない」にチェック**
3. コンパイルして動作確認

**確認すべき項目**

- [ ] トップページが表示され、商品が取得できる（＝APIに到達している）
- [ ] 会員登録・ログインができる
- [ ] 画像が表示される（`site_url` が正しいか）
- [ ] 言語が想定どおり（中文/日本語/English）
- [ ] 言語切替ボタンが動く
- [ ] 通知・チャット（WebSocket）が繋がる

うまく動かない場合、開発者ツールの Network タブで**リクエスト先URLが
トンネルURLになっているか**を最初に確認してください。

### 4-3. 体验版として配布（実機テスト）

1. 開発者ツールで「アップロード」→ バージョン番号と説明を入力
2. 微信公众平台「管理 → バージョン管理」で該当バージョンを**「体验版に設定」**
3. 「メンバー管理」でテスターの微信アカウントを**体験メンバーに追加**
4. テスターは体验版のQRコードから開く
5. **実機では「調試モードON」が必要**（ホワイトリスト未登録のため）

### 4-4. 正式リリース（この構成では不可）

正式版はドメイン検証を回避できません。以下が揃ってから提出します。

1. **ICP 備案済みドメイン**を取得する（中国本土の事業実体が必要）
2. 微信公众平台「開発 → 開発設定 → 服务器域名」で **request / socket / uploadFile /
   downloadFile** の各ドメインを登録
3. サーバーを備案対応の環境へ移行し、`HTTP_REQUEST_URL` と `site_url` を差し替え
4. 再ビルドして審査提出

---

## 5. 自宅PC構成の注意点

| 項目 | 注意 |
| --- | --- |
| PCの電源 | 落とすとテスト環境も止まります。スリープ設定を無効化してください |
| `cloudflared` の常駐 | Windows サービスとして登録すると再起動後も自動復帰します |
| MySQL のデータ | `help/docker/mysql/data` に永続化されます。**このディレクトリを消すとデータが消えます** |
| 帯域 | 画像配信で家庭回線を消費します。テスト用途なら問題になりにくいですが、体験メンバーを増やす場合は注意 |
| セキュリティ | トンネルURLは**インターネットから到達可能**です。管理画面のパスワードを強固にし、`install/` を必ず塞いでください |
| workerman | 同梱イメージは **supervisord が自動起動**します（`nginx` / `php-fpm` / `queue` / `timer` / `workerman`）。手動起動は不要です。状態確認は `docker exec <container> supervisorctl status`。手動操作が必要な場合は `php think workerman start\|stop\|reload\|status [admin\|chat\|channel]` |

---

## 6. トラブル時の切り分け順

0. **今回追加した機能が無い / 挙動が古い** → **イメージ内の古いソースで動いている**（1-0 参照）。
   `docker inspect <container> --format '{{len .Mounts}}'` が `0` なら確定です
1. **`http://localhost:8011` は見えるか** → Docker/Nginx の問題
2. **`https://crmeb-test.example.com` は見えるか** → Cloudflare Tunnel の問題
3. **開発者ツールで API が 200 か** → `HTTP_REQUEST_URL` / ドメイン検証の問題
4. **画像が出ないか** → `site_url` 未設定、または Cloudflare のキャッシュ
5. **言語が想定と違うか** → `eb_lang_type.is_default` とキャッシュ（`crmeb/runtime/cache`）
6. **WebSocket が繋がらないか** → workerman 未起動、または Cloudflare の WebSocket 設定OFF

---

## 7. この手順で確認できること・できないこと

| 項目 | 可否 |
| --- | --- |
| Docker で現行ソースが動く | ✅ |
| Cloudflare 経由の HTTPS 公開 | ✅ |
| ミニプログラムのビルド | ✅ |
| 開発者ツールでの動作確認 | ✅ |
| 体验版での実機確認（調試モード） | ✅ |
| **正式版のリリース** | ❌ ICP備案済みドメインが必要 |
| **微信支付のテスト** | ⚠️ 企業アカウント + 加盟店契約が必要。決済通貨は `CNY` 固定（[PayClient.php:165](../crmeb/crmeb/services/easywechat/v3pay/PayClient.php:165)） |

> 本ドキュメントの Docker / uni-app / CRMEB 設定に関する記述はリポジトリの実装を
> 直接確認したものです。一方、微信公众平台の画面構成や審査要件、Cloudflare の
> 設定名称は変更されることがあるため、実施時に各公式ドキュメントを併せて確認してください。
