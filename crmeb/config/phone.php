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
// | 海外会員向け 電話番号設定 / International phone number settings
// +----------------------------------------------------------------------
// | 保存形式について:
// |   国番号 86（中国）の番号は、既存データとの互換のため国内表記
// |   （先頭 1 の 11 桁）のまま保存します。
// |   それ以外の国は E.164 形式（+81xxxxxxxxxx）で保存します。
// |   → 先頭が「+」なら国際番号、数字のみなら中国番号と一意に判別できます。
// |
// | 国を追加する場合は countries に1行足すだけです。pattern は
// | 「国内有意番号」（トランクプレフィックス 0 を除いた部分）に対する正規表現です。
// +----------------------------------------------------------------------

return [
    // 国番号が指定されなかった場合に使う既定値（既存の中国向け挙動を維持）
    'default_dial_code' => '86',

    // この国番号だけは国内表記のまま保存する（既存会員の互換維持）
    'national_format_dial_code' => '86',

    // 国際番号として許容する全体の桁数（E.164 は国番号を含めて最大15桁）
    'max_e164_digits' => 15,

    // +------------------------------------------------------------------
    // | 検証の厳密さ
    // +------------------------------------------------------------------
    // | false（既定）… countries の pattern に合わなくても、桁数が妥当なら受け付ける。
    // |                 さらに countries に無い国番号も受け付ける。
    // |                 携帯番号の番号帯は国ごとに頻繁に増えるため、
    // |                 パターン管理が追いつかず登録できない事故を避けるための既定値。
    // | true          … pattern に完全一致する番号のみ受け付ける（厳格運用向け）。
    // +------------------------------------------------------------------
    'strict' => false,

    // strict = false でも、この国番号は必ず pattern で厳密に検証する。
    // 中国番号は国内表記のまま保存しており既存会員のログインIDでもあるため、
    // 緩めると不正な値がアカウントとして登録されうる。
    'strict_dial_codes' => ['86'],

    // 緩い検証で許容する「国内有意番号」の桁数範囲
    'national_min_digits' => 5,
    'national_max_digits' => 14,

    // 緩い検証で許容する国番号の桁数範囲（countries に無い国を受け付けるとき）
    'dial_code_min_digits' => 1,
    'dial_code_max_digits' => 4,

    // 対応国・地域
    //   dial_code    : 国番号（+ は含めない）
    //   iso          : ISO 3166-1 alpha-2
    //   trunk_prefix : 国内発信時の頭に付く番号。入力時に付いていたら除去する
    //   pattern      : 国内有意番号に対する検証パターン（SMSを送るため携帯番号を対象とする）
    //   name / name_en / name_ja : 表示名。多言語パックに依存せずここで完結させる
    'countries' => [
        // --- アジア ---
        ['dial_code' => '81', 'iso' => 'JP', 'name' => '日本', 'name_en' => 'Japan', 'name_ja' => '日本', 'trunk_prefix' => '0', 'pattern' => '/^[789]0\d{8}$/'],
        ['dial_code' => '86', 'iso' => 'CN', 'name' => '中国', 'name_en' => 'China', 'name_ja' => '中国', 'trunk_prefix' => '', 'pattern' => '/^1[3-9]\d{9}$/'],
        ['dial_code' => '82', 'iso' => 'KR', 'name' => '韩国', 'name_en' => 'South Korea', 'name_ja' => '韓国', 'trunk_prefix' => '0', 'pattern' => '/^1[016-9]\d{7,8}$/'],
        ['dial_code' => '886', 'iso' => 'TW', 'name' => '台湾', 'name_en' => 'Taiwan', 'name_ja' => '台湾', 'trunk_prefix' => '0', 'pattern' => '/^9\d{8}$/'],
        ['dial_code' => '852', 'iso' => 'HK', 'name' => '香港', 'name_en' => 'Hong Kong', 'name_ja' => '香港', 'trunk_prefix' => '', 'pattern' => '/^[456789]\d{7}$/'],
        ['dial_code' => '65', 'iso' => 'SG', 'name' => '新加坡', 'name_en' => 'Singapore', 'name_ja' => 'シンガポール', 'trunk_prefix' => '', 'pattern' => '/^[89]\d{7}$/'],

        // --- 北米 ---
        ['dial_code' => '1', 'iso' => 'US', 'name' => '美国/加拿大', 'name_en' => 'United States / Canada', 'name_ja' => 'アメリカ / カナダ', 'trunk_prefix' => '1', 'pattern' => '/^[2-9]\d{2}[2-9]\d{6}$/'],

        // --- ヨーロッパ・オセアニア ---
        ['dial_code' => '44', 'iso' => 'GB', 'name' => '英国', 'name_en' => 'United Kingdom', 'name_ja' => 'イギリス', 'trunk_prefix' => '0', 'pattern' => '/^7[1-9]\d{8}$/'],
        ['dial_code' => '49', 'iso' => 'DE', 'name' => '德国', 'name_en' => 'Germany', 'name_ja' => 'ドイツ', 'trunk_prefix' => '0', 'pattern' => '/^1[5-7]\d{7,9}$/'],
        ['dial_code' => '33', 'iso' => 'FR', 'name' => '法国', 'name_en' => 'France', 'name_ja' => 'フランス', 'trunk_prefix' => '0', 'pattern' => '/^[67]\d{8}$/'],
        ['dial_code' => '39', 'iso' => 'IT', 'name' => '意大利', 'name_en' => 'Italy', 'name_ja' => 'イタリア', 'trunk_prefix' => '', 'pattern' => '/^3\d{8,9}$/'],
        ['dial_code' => '34', 'iso' => 'ES', 'name' => '西班牙', 'name_en' => 'Spain', 'name_ja' => 'スペイン', 'trunk_prefix' => '', 'pattern' => '/^[67]\d{8}$/'],
        ['dial_code' => '31', 'iso' => 'NL', 'name' => '荷兰', 'name_en' => 'Netherlands', 'name_ja' => 'オランダ', 'trunk_prefix' => '0', 'pattern' => '/^6\d{8}$/'],
        ['dial_code' => '41', 'iso' => 'CH', 'name' => '瑞士', 'name_en' => 'Switzerland', 'name_ja' => 'スイス', 'trunk_prefix' => '0', 'pattern' => '/^7[5-9]\d{7}$/'],
        ['dial_code' => '46', 'iso' => 'SE', 'name' => '瑞典', 'name_en' => 'Sweden', 'name_ja' => 'スウェーデン', 'trunk_prefix' => '0', 'pattern' => '/^7[02369]\d{7}$/'],
        ['dial_code' => '61', 'iso' => 'AU', 'name' => '澳大利亚', 'name_en' => 'Australia', 'name_ja' => 'オーストラリア', 'trunk_prefix' => '0', 'pattern' => '/^4\d{8}$/'],
    ],
];
