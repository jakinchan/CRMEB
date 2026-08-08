<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

// +----------------------------------------------------------------------
// | メール送信設定 / Mail settings
// +----------------------------------------------------------------------
// | SMTP で送信します。Gmail / Amazon SES / SendGrid / Mailgun など
// | SMTP を提供するサービスならそのまま利用できます。
// |
// | 設定例:
// |   Amazon SES  host=email-smtp.ap-northeast-1.amazonaws.com port=587 encryption=tls
// |   SendGrid    host=smtp.sendgrid.net  port=587 username=apikey  encryption=tls
// |   Gmail       host=smtp.gmail.com     port=587 encryption=tls（アプリパスワードが必要）
// |
// | 認証情報はリポジトリに入れず .env で管理してください。
// +----------------------------------------------------------------------

use think\facade\Env;

return [
    // メール登録・メール送信を有効にするか
    'enable' => (bool)Env::get('mail.enable', false),

    // SMTP サーバー
    'host' => Env::get('mail.host', ''),
    'port' => (int)Env::get('mail.port', 587),

    // 暗号化方式: 'tls'（STARTTLS / 587番）, 'ssl'（SMTPS / 465番）, ''（暗号化なし）
    'encryption' => Env::get('mail.encryption', 'tls'),

    // 認証情報
    'username' => Env::get('mail.username', ''),
    'password' => Env::get('mail.password', ''),

    // 差出人
    'from_address' => Env::get('mail.from_address', ''),
    'from_name' => Env::get('mail.from_name', ''),

    // タイムアウト（秒）
    'timeout' => (int)Env::get('mail.timeout', 15),

    // 自己署名証明書のサーバーに対して検証を省略するか。
    // 既定は false（検証する）。true にすると中間者攻撃を検知できなくなるため、
    // 検証環境以外では使用しないでください。
    'allow_self_signed' => (bool)Env::get('mail.allow_self_signed', false),

    // 認証コードの有効時間（分）。未設定なら SMS と同じ設定値を使う
    'code_expire_minutes' => (int)Env::get('mail.code_expire_minutes', 0),

    // 送信上限（SMS と同じ考え方でスパム・課金事故を防ぐ）
    'maxAddressCount' => 20,   // 同一アドレスあたり1日
    'maxMinuteCount' => 5,     // 同一アドレスあたり1分
    'maxIpCount' => 50,        // 同一IPあたり1日
];
