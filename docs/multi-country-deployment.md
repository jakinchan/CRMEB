# 国別インスタンス構築手順（日本 / カナダ / 中国）

日本・カナダ・中国それぞれで販売・配送するため、**国ごとに独立した CRMEB を1つずつ**立てます。

```
shop.example.jp   →  CRMEB(JP)  DB: crmeb_jp   既定言語: 日本語
shop.example.ca   →  CRMEB(CA)  DB: crmeb_ca   既定言語: English
shop.example.cn   →  CRMEB(CN)  DB: crmeb_cn   既定言語: 简体中文
```

## なぜ1サイト統合ではなくこの構成か

CRMEB 単商戸版は以下が**サイト全体で1つ**しか持てません。

| 項目 | 実装上の制約 |
| --- | --- |
| 商品の在庫・価格 | `eb_store_product` に `store_id` が無く、店舗別に持てない |
| 配送元店舗 | `store_id` は店頭受取（`shipping_type=2`）のみ設定。宅配注文に店舗が紐付かない |
| 既定言語 | `eb_lang_type.is_default` がサイト全体で1件 |
| 通貨 | 通貨設定が存在せず単一 |
| 住所・送料 | `eb_system_city` は中国3,939件のみ。送料も中国の市単位 |

インスタンスを分けることで、在庫・価格・通貨・言語・送料をそれぞれ独立させられます。

---

## 事前に決めること

### ホスティング先

**中国向けは中国国内でのホスティングと ICP 備案が実務上必須**です（未取得だと接続が不安定・遮断される）。
日本・カナダは各リージョンに置くと表示速度が改善します。3インスタンスを1台に同居させることも可能ですが、
中国向けだけは分離する前提で設計してください。

### 決済（最重要）

同梱の決済ドライバは `crmeb/crmeb/services/pay/storage/` の4種のみで、**すべて中国向け**です。

| ドライバ | 用途 | 日本 | カナダ |
| --- | --- | --- | --- |
| WechatPay / V3WechatPay | 微信支付 | ✕ | ✕ |
| AliPay | 支付宝 | ✕ | ✕ |
| AllinPay | 通联支付 | ✕ | ✕ |

**日本・カナダのインスタンスはオンライン決済がそのままでは使えません。** 選択肢は次の3つです。

1. **線下支払い（銀行振込・代金引換）で運用** — 管理画面で有効化するだけ。改修不要
2. **残高（余额）払いのみにする** — 事前チャージ運用。改修不要
3. **Stripe / PayPal ドライバを追加実装** — `PayInterface` を実装したクラスを `pay/storage/` に追加。要開発

まず 1 または 2 で開始し、並行して 3 を進めるのが現実的です。

---

## 構築手順

以下は1インスタンス分の手順です。**日本・カナダ・中国で3回繰り返します。**
`{CC}` は `jp` / `ca` / `cn` に読み替えてください。

### 1. ソースを配置する

```bash
git clone <this-repo> crmeb-{CC}
cd crmeb-{CC}
git checkout future_dev
```

`crmeb/public/admin` にビルド済みの管理画面が含まれているため、フロントのビルドは不要です。
管理画面のソースを変更した場合のみ `template/admin` で `npm ci && npm run build` してから
`dist` を `crmeb/public/admin` へ配置します（Node 24 では `NODE_OPTIONS=--openssl-legacy-provider` が必要）。

### 2. データベースを用意する

インスタンスごとに**別のデータベース**を作ります。MySQL サーバーは共用でも構いません。

```sql
CREATE DATABASE crmeb_{CC} DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'crmeb_{CC}'@'%' IDENTIFIED BY '<パスワード>';
GRANT ALL PRIVILEGES ON crmeb_{CC}.* TO 'crmeb_{CC}'@'%';
FLUSH PRIVILEGES;
```

Redis も共用できます。インストーラが `uniqid()` から `CACHE_PREFIX` / `CACHE_TAG_PREFIX` / `QUEUE_NAME`
を自動生成するため、**インスタンスごとに個別にインストールする限りキャッシュキーは衝突しません**。

ただし `.env` を他インスタンスからコピーして使い回すと接頭辞まで同じになり、
言語パックのキャッシュが混ざります。**`.env` は必ずインストーラに生成させてください。**
運用上の切り分けを明確にしたい場合は `[REDIS] SELECT` の DB 番号も分けておくと確実です。

### 3. Docker で立てる場合

`help/docker/docker-compose.yml` をインスタンスごとにコピーし、次を必ず変更します。
同じ値のままだと2つ目以降が起動しません。

| 項目 | 変更内容 |
| --- | --- |
| `container_name` | `crmeb_{CC}_mysql` など全コンテナで一意に |
| `ports` | ホスト側ポートを重複させない（例 nginx: JP=8011 / CA=8012 / CN=8013） |
| `networks.app_net.ipam.config.subnet` | `172.32.10.0/24` → `172.32.11.0/24` など重複させない |
| 各サービスの `ipv4_address` | 変更したサブネットに合わせる |
| `volumes` の `./mysql/data` | インスタンスごとに別ディレクトリへ |
| `MYSQL_DATABASE` | `crmeb_{CC}` |
| `TZ` / `DEFAULT_TIMEZONE` | JP=`Asia/Tokyo` / CA=`America/Toronto` / CN=`Asia/Shanghai` |
| `nginx/vhost.conf` の `server_name` | 各国のドメイン |
| `vhost.conf` の `proxy_pass` | 変更した PHP コンテナの IP に合わせる（`/notice` と `/msg`） |

`proxy_pass` の IP を直し忘れると WebSocket（通知・チャット）だけが動かないので注意してください。

### 4. インストーラを実行する

ブラウザで `https://shop.example.{CC}/install/index.php` を開き、DB接続情報と管理者アカウントを入力します。

インストーラは `crmeb/public/install/crmeb.sql` を取り込み、`.env` と `.constant` を生成し、
`crmeb/public/install.lock` を作成します。

**このリポジトリの `crmeb.sql` には既に以下が反映済み**なので、新規インストールでは追加の SQL 適用は不要です。

- 3言語（中国語 / English / 日本語）のみ有効化、日本語訳の補正482件
- ブラウザ言語コードの対応（`ja` / `en-GB` などの変種も解決）
- 国際電話番号を保存できるカラム幅（7カラム）
- メール登録用の `eb_user.email` と多言語文言

> 既存インスタンスへ後から適用する場合のみ、`crmeb/public/install/` の
> `lang_zh_en_ja.sql` / `phone_international.sql` / `email_register.sql` / `lang_email_register.sql`
> を順に適用してください（すべて冪等）。

インストール後、セキュリティのため `crmeb/public/install/` を削除するか外部から見えないようにします。

### 5. 既定言語を国別に設定する

**ここが国別サイトの要です。** 管理画面の「設定 → 多言語設定 → 言語タイプ」で対象言語を編集し、
「是否默认（既定にする）」を有効にします。保存時に他言語の `is_default` は自動で 0 になります。

SQL で直接設定する場合は次のとおりです。

```sql
-- 日本(JP): 日本語を既定に
UPDATE eb_lang_type SET is_default = 0;
UPDATE eb_lang_type SET is_default = 1 WHERE id = 6;

-- カナダ(CA): English を既定に
UPDATE eb_lang_type SET is_default = 0;
UPDATE eb_lang_type SET is_default = 1 WHERE id = 2;

-- 中国(CN): 简体中文を既定に（初期状態のまま）
UPDATE eb_lang_type SET is_default = 0;
UPDATE eb_lang_type SET is_default = 1 WHERE id = 1;
```

`id` は 1=中文 / 2=English / 6=日本語 です。

**適用後は必ずキャッシュをクリアしてください。** 既定言語は `range_name`、言語パックのバージョンは
`lang_version`（`uniqid()` のキャッシュ値）として保持されるため、消さないとフロントが古い言語パックを使い続けます。

```bash
find crmeb/runtime/cache -type f -name "*.php" -delete
```

なお**既定言語は初回訪問者にのみ効きます**。利用者がブラウザ言語や画面上の切替（中/日/EN）で
選んだ言語は端末に保存され、そちらが優先されます。これは意図した動作です。

### 6. 通貨記号を国別に合わせる

通貨記号は多言語パックの文言として管理されているため、**言語ごとに差し替えられます**。

| コード | 中国語 | English | 日本語 |
| --- | --- | --- | --- |
| `￥` | `￥` | `$` | `￥` |
| `元` | `元` | `$` | `￥` |

カナダ($ CAD)は English の既存値がそのまま使えます。日本は `￥` のままで問題ありません。
変更したい場合は管理画面「設定 → 多言語設定 → 言語コード」から編集します。

> ⚠️ これは**表示上の記号だけ**です。金額そのものは各インスタンスの商品価格として
> 独立に設定してください（JP は円建て、CA は CAD 建てで価格を入力する）。
> 為替換算の機能はありません。

### 7. 国別の設定を入れる

| 設定 | 場所 | 備考 |
| --- | --- | --- |
| サイト名・ロゴ | 管理画面 → 設定 → 基本設定 | 国別に |
| 送料テンプレート | 管理画面 → 設定 → 送料テンプレート | 後述の制約あり |
| SMS | 管理画面 → 設定 → SMS | 海外は阿里云/腾讯云に切替え、`config/sms.php` の `international` を設定 |
| メール登録 | `.env` の `[MAIL]` | `docs/overseas-members.md` 参照。日本・カナダはこちらが実用的 |
| タイムゾーン | `.env` の `DEFAULT_TIMEZONE` | 受注時刻がずれるため必ず設定 |
| 決済 | 管理画面 → 設定 → 決済設定 | 日本・カナダは線下/残高、または要実装 |

---

## 残る制約（案Aでも解決しない）

### 住所フォームが中国式

`eb_user_address` は 省 / 市 / 区 の3階層固定で、`eb_system_city` は**中国の3,939件のみ**です。
日本（都道府県 / 市区町村）やカナダ（Province + Postal Code）に合いません。

日本・カナダのインスタンスでは、次のいずれかの対応が必要です。

1. **`eb_system_city` に各国データを投入する** — 3階層に無理なくマッピングできるなら最小改修。
   日本は 都道府県 / 市区町村 / (空 or 町名) に当てられます。カナダは Province / City / (空) 。
2. **住所フォームを国別に作り替える** — `AddressValidate` と uni-app の住所画面を改修。
   郵便番号を主体にするならこちら。`post_code` が `int unsigned` なのでカナダの英字郵便番号
   （例 `M5V 3L9`）は入らず、**カラム型の変更が必要**です。

### 送料テンプレートが中国の市単位

`eb_shipping_templates_region` が `eb_system_city` を参照するため、上記の都市データ投入とセットになります。
「全国一律送料」で運用するなら追加改修は不要です。

### 在庫・会員・注文は3サイトで分断

国をまたいだ在庫の付け替えや、同一会員の共通ポイントはできません。
統合が必要なら、外部の在庫・顧客マスタと各インスタンスを API 連携させる設計になります。

---

## 構築チェックリスト

各インスタンスで以下を確認してください。

- [ ] `crmeb/public/install.lock` が生成され、`install/` を削除または遮断した
- [ ] `.env` の DB 名・Redis の `SELECT` 番号が他インスタンスと重複していない
- [ ] `.env` の `DEFAULT_TIMEZONE` が現地時刻になっている
- [ ] `eb_lang_type` の `is_default` が目的の言語1件のみ
- [ ] キャッシュをクリアした（`crmeb/runtime/cache`）
- [ ] ブラウザの言語を切り替えて、初回表示が期待どおりになる
- [ ] 画面右上の言語切替（中/日/EN）で表示が変わる
- [ ] 管理画面の言語切替が効く（ヘッダー右上）
- [ ] 会員登録できる（電話番号 or メール）
- [ ] 決済手段が1つ以上有効になっている
- [ ] 送料テンプレートが設定済みで、テスト注文が完了する
- [ ] WebSocket（通知・チャット）が動く＝`vhost.conf` の `proxy_pass` が正しい
