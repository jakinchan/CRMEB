# 海外会員の登録対応（国際電話番号 / メールアドレス）

海外会員の登録手段は2つあります。

| 手段 | 認証 | 向いているケース |
| --- | --- | --- |
| 国際電話番号 | SMS | 阿里云・腾讯云で国際SMSを契約できる場合 |
| **メールアドレス** | メール | SMSが届かない国、固定電話しか無い利用者、SMS費用を抑えたい場合 |

メール登録は SMS の設定が一切不要で、SMTP を用意するだけで使えます。
詳細は末尾の「メールアドレスでの会員登録」を参照してください。

---


CRMEB は標準では中国の携帯番号（`/^1[3-9]\d{9}$/`）しか受け付けないため、
海外の会員が自分の番号で登録できませんでした。この対応で **日本・欧米を含む
国際電話番号での登録・ログイン** ができるようになります。

## 保存形式

| 国番号 | 保存形式 | 例 |
| --- | --- | --- |
| 86（中国） | 国内表記のまま | `13800138000` |
| それ以外 | E.164 形式 | `+819012345678` |

中国番号を国内表記のまま残しているのは **既存会員がログインできなくなるのを避けるため**です。
電話番号は `eb_user.account` / `eb_user.phone` としてログインIDに使われており、
形式を変えると既存アカウントに到達できなくなります。

先頭が `+` かどうかで国際番号か中国番号かを一意に判別できるため、
データ移行なしで新旧が共存できます。

## 対応国

`crmeb/config/phone.php` の `countries` で管理します。現在の収録は16件です。

日本 / 中国 / 韓国 / 台湾 / 香港 / シンガポール / アメリカ・カナダ /
イギリス / ドイツ / フランス / イタリア / スペイン / オランダ / スイス /
スウェーデン / オーストラリア

国を追加するには1行足すだけです。

```php
['dial_code' => '64', 'iso' => 'NZ', 'name' => '新西兰', 'name_en' => 'New Zealand',
 'name_ja' => 'ニュージーランド', 'trunk_prefix' => '0', 'pattern' => '/^2\d{7,9}$/'],
```

- `trunk_prefix` … 国内発信時の頭に付く番号。入力に付いていたら除去します（日本の `090…` → `90…`）
- `pattern` … トランクプレフィックスを除いた「国内有意番号」に対する検証パターン

## 検証の緩さ

携帯番号の番号帯は国ごとに頻繁に追加されるため、パターン管理が追いつかず
**登録できない事故が起きるほうが実害が大きい**という判断で、既定を緩めています。

```php
'strict' => false,                 // 既定
'strict_dial_codes' => ['86'],     // 中国だけは常に厳密
'national_min_digits' => 5,
'national_max_digits' => 14,
```

`strict = false`（既定）のときの挙動は次のとおりです。

| ケース | 結果 |
| --- | --- |
| `countries` の `pattern` に一致 | ○ 受け付ける |
| `pattern` に一致しないが桁数は妥当（例: 日本の固定電話 `03-1234-5678`、050 IP電話、060 番号帯） | ○ 受け付ける |
| `countries` に無い国番号（例: `+64` NZ、`+91` インド） | ○ 受け付ける |
| 桁数が範囲外（国内番号が5桁未満／14桁超、E.164 合計15桁超） | ✕ 拒否 |
| 国番号が `0` で始まる | ✕ 拒否（E.164 で存在しない） |
| **中国番号が `/^1[3-9]\d{9}$/` に合わない** | ✕ 拒否 |

**中国だけ厳密なまま**にしているのは、中国番号を国内表記で保存しており
既存会員のログインIDそのものであるため、緩めると不正な値がアカウントとして
登録されうるからです。

厳格に運用したい場合は `'strict' => true` にすると、`countries` の `pattern` に
完全一致する番号のみを受け付けます（未登録の国番号も拒否）。

端末側（`utils/validate.js`）も同じ桁数範囲で判定します。国別の詳細パターンは
サーバーにのみ持たせ、二重管理を避けています。

## 適用手順

### 1. DB スキーマの拡張

電話番号カラムが中国番号（11桁）前提の幅しかないため広げます。

```bash
mysql -u<user> -p <database> < crmeb/public/install/phone_international.sql
```

拡張するのは7カラムです。いずれも**幅を広げるだけ**で、NOT NULL・デフォルト値・
コメントは既存定義を維持しており、データの切り捨ては発生しません（冪等）。

| テーブル | カラム | 変更 |
| --- | --- | --- |
| `eb_user` | `phone` | `char(15)` → `varchar(20)` |
| `eb_user` | `record_phone` | `varchar(11)` → `varchar(20)` |
| `eb_user_address` | `phone` | `varchar(16)` → `varchar(25)` |
| `eb_store_order` | `user_phone` | `varchar(18)` → `varchar(25)` |
| `eb_store_integral_order` | `user_phone` | `varchar(18)` → `varchar(25)` |
| `eb_sms_record` | `phone` | `char(11)` → `varchar(20)` |
| `eb_system_store_staff` | `phone` | `char(15)` → `varchar(20)` |

新規インストールでは `crmeb/public/install/crmeb.sql` に反映済みなので追加作業は不要です。

### 2. 国際SMS の設定（必須）

**ここが実運用の要件です。** 番号を受け付けられても認証コードが届かなければ登録は完了しません。

`crmeb/config/sms.php` の `international` を設定してください。

```php
'international' => [
    'enable' => true,
    'template_id' => '',        // 国際用テンプレートID
    'aliyun_sign_name' => '',   // 阿里云の国際用署名
    'tencent_sign_name' => '',  // 腾讯云の国際用署名
],
```

同梱のSMSドライバは4種ですが、**国際送信に使えるのは阿里云と腾讯云だけ**です。

| ドライバ | 国際送信 |
| --- | --- |
| 一号通（既定） | ✕ CRMEB自社リセールで国際送信は想定外 |
| 阿里云 | ○ 業者側で国際SMSの有効化＋国際テンプレート登録が必要 |
| 腾讯云 | ○ 同上 |
| 创蓝 | ✕ |

海外会員を受け付ける場合は、管理画面でSMSドライバを **阿里云** または **腾讯云** に切り替えてください。
一号通のまま海外番号に送ろうとすると「現在の短信ドライバは海外送信に対応していません」で明示的に失敗します
（黙って失敗させず、原因が分かるようにしています）。

なお国際SMSの本文は英数字が基本です。国際テンプレートは英語で登録してください。

## 実装の要点

### サーバー側

| ファイル | 役割 |
| --- | --- |
| `crmeb/config/phone.php` | 対応国・検証パターン・保存形式の設定 |
| `crmeb/crmeb/utils/PhoneNumber.php` | 正規化 / 検証 / E.164変換 / 国番号分解 |
| `crmeb/app/common.php` | `check_phone()` を国際対応に変更し、`normalize_phone()` を追加 |
| `crmeb/app/api/controller/v1/LoginController.php` | `verify` / `register` / `reset` / `mobile` / `login` で `dial_code` を受け取り保存形式へ正規化 |
| `crmeb/app/api/validate/user/*.php` | 固定正規表現を `checkPhone` ルールへ差し替え |
| `crmeb/app/services/message/notice/SmsService.php` | 海外番号を判定し国際テンプレート・署名へ切り替え |
| `crmeb/app/api/controller/v1/PublicController.php` | 国番号一覧API `get_dial_code_list` |

**正規化は入口で一度だけ**行います。検証コード送信（`verify`）と登録（`register` / `mobile`）で
同じ保存形式に揃わないと、コードのキャッシュキー `code_<phone>` が一致せず認証に失敗するためです。

### フロント（uni-app）

| ファイル | 役割 |
| --- | --- |
| `components/dialCodePicker/index.vue` | 国番号セレクタ（3画面で共用） |
| `utils/validate.js` | `checkPhone(phone, dialCode)` / `formatPhone()` |
| `api/public.js` | `getDialCodeList()` |
| `pages/users/login/index.vue` | ログイン・登録画面にセレクタを追加 |
| `pages/users/retrievePassword/index.vue` | パスワード再設定にセレクタを追加 |

端末側は**桁数の妥当性だけ**を見る緩い検証にしています。国ごとの詳細パターンを
サーバーと端末で二重管理しないためで、厳密な検証はサーバーが行います。

## 既知の制限

- **`pages/users/binding_phone/index.vue` は未対応です。**
  WeChat / ミニプログラム利用者が電話番号を紐付ける導線で、WeChat前提のため
  中国国内を想定しています。海外会員は通常のログイン画面（電話番号＋SMS）から登録します。
  なおこのページには `registerVerify()` をオブジェクトではなく位置引数で呼んでいる
  既存の不具合があります（今回の対応範囲外）。
- **管理画面の電話番号入力は未変更です。** 会員の電話番号編集はサーバー側
  （`adminapi` の `User.php`）で正規化されるため動作しますが、管理画面に残る
  中国番号向けの正規表現は、CRMEB自社SMSサービスの申込フォームや店舗設定など
  会員登録とは別の用途のものです。
- **固定電話も形式上は登録できてしまいます。** 検証を緩めた副作用です。
  SMS認証が前提のため、固定電話で登録しようとすると認証コードが届かず登録は完了しません
  （形式チェックでは弾かれず、SMS送信の段で失敗します）。
  形式の段階で弾きたい場合は `config/phone.php` の `strict` を `true` にしてください。

---

# メールアドレスでの会員登録

SMS が届かない国や電話番号を持たない利用者でも登録できるよう、
メールアドレスでの登録・ログイン・パスワード再設定を追加しました。

## 適用手順

### 1. DB

```bash
mysql -u<user> -p <database> < crmeb/public/install/email_register.sql
mysql -u<user> -p <database> < crmeb/public/install/lang_email_register.sql
```

| 対象 | 変更 |
| --- | --- |
| `eb_user.email` | 追加（`varchar(100)`、ログインIDとして使用） |
| `eb_user.account` | `varchar(32)` → `varchar(100)`（メールアドレスを入れるため） |
| `eb_user` の索引 | `email` に検索用インデックスを追加 |
| `eb_lang_code` | メール登録の文言を3言語分追加（12件×3） |

いずれも `NOT EXISTS` 相当のガード付きで**冪等**です。
新規インストールでは `crmeb.sql` に反映済みなので追加作業は不要です。
適用後は管理画面のキャッシュクリアを実行してください。

### 2. SMTP の設定

`crmeb/config/mail.php` は `.env` から値を読みます。認証情報はリポジトリに入れず
`.env` に記述してください。

```ini
[MAIL]
enable = true
host = email-smtp.ap-northeast-1.amazonaws.com
port = 587
encryption = tls
username = <SMTP ユーザー>
password = <SMTP パスワード>
from_address = noreply@example.com
from_name = ショップ名
```

`encryption` は `tls`（STARTTLS / 587番）、`ssl`（SMTPS / 465番）、空（暗号化なし）。
Amazon SES / SendGrid / Mailgun / Gmail など SMTP を提供するサービスならそのまま使えます。

**メール登録の有効・無効はこの設定だけで決まります。** `enable` が false、または
`host` / `from_address` が空の場合、フロントにメールタブは出ず、API も
「邮箱注册未开启」を返します。設定漏れで中途半端に動くことはありません。

## 実装

外部ライブラリを追加せず、PHP コアの `stream_socket_client` と openssl 拡張のみで
SMTP クライアントを実装しています（パッケージ取得が社内証明書で失敗する環境でも動くように）。

| ファイル | 役割 |
| --- | --- |
| `crmeb/config/mail.php` | SMTP 設定・送信上限 |
| `crmeb/crmeb/services/mail/SmtpMailer.php` | SMTP クライアント（STARTTLS / SMTPS、AUTH LOGIN、RFC 2047 の件名符号化） |
| `crmeb/app/services/message/notice/MailService.php` | 認証コードメールの組み立てと送信 |
| `crmeb/app/services/user/LoginServices.php` | `verifyEmail()` / `emailLogin()`、`register()` のメール対応 |
| `crmeb/app/api/controller/v1/LoginController.php` | `emailVerify()` / `emailLogin()`、`register` / `reset` のメール対応 |

### API

| メソッド | パス | 用途 |
| --- | --- | --- |
| POST | `email/verify` | メールへ認証コードを送信 |
| POST | `login/email` | メールでログイン（未登録なら自動で会員登録） |
| POST | `register` | `account` が `@` を含む場合はメール登録として処理 |
| POST | `register/reset` | 同じくメールでのパスワード再設定に対応 |

`register` / `reset` は `account` に `@` が含まれるかでメールと電話番号を振り分けます。
既存の電話番号での呼び出しは一切変わりません。

### 保存されるデータ

| カラム | 電話番号登録 | メール登録 |
| --- | --- | --- |
| `account` | 電話番号 | メールアドレス |
| `phone` | 電話番号 | 空 |
| `email` | 空 | メールアドレス |
| `nickname` | 中間4桁を伏せた番号 | ローカル部の先頭3文字 + `****` |

ニックネームは他の利用者にも見えるため、メールアドレス全体は使いません
（`member@example.com` → `mem****`）。

### 送信上限

SMS と同じ考え方で、迷惑メール化と課金事故を防ぐ上限を設けています
（`config/mail.php`）。同一アドレス 1分5通 / 1日20通、同一IP 1日50通。

## セキュリティ

- 宛先に改行を含むものは拒否します（メールヘッダーインジェクション対策）
- 件名・差出人名は非 ASCII を RFC 2047 で符号化し、本文は base64 で送ります
- TLS の証明書検証は既定で有効です。`mail.allow_self_signed` を true にすると
  検証を省略できますが、中間者攻撃を検知できなくなるため検証環境専用です

## 既知の制限

- **メール登録の初回はパスワードが `123456` で作成されます。**
  電話番号での `mobile()` ログインと同じ既存挙動に合わせたものです。
  そのままではパスワードログインが拒否される（初期パスワードのままでは
  ログインできない仕様）ため、利用者にはマイページからの変更を促してください。
- **メール本文はプレーンテキストのみ**です。HTML メールやテンプレート管理の
  管理画面 UI は未実装で、文言は多言語パック（`eb_lang_code`）で管理します。
- **管理画面にメール設定の UI はありません。** `.env` での設定のみです。
