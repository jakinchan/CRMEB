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

namespace crmeb\utils;

use think\facade\Config;

/**
 * 海外会員に対応した電話番号の正規化・検証
 *
 * 保存形式:
 *   - 国番号 86（中国）… 国内表記のまま（例: 13800138000）。既存会員との互換のため。
 *   - それ以外          … E.164 形式（例: +819012345678）
 * 先頭が「+」かどうかで国際番号か中国番号かを一意に判別できます。
 *
 * Class PhoneNumber
 * @package crmeb\utils
 */
class PhoneNumber
{
    /**
     * 対応国の一覧を返す
     * @return array
     */
    public static function countries(): array
    {
        return Config::get('phone.countries', []);
    }

    /**
     * 国番号から国定義を引く
     * @param string|int $dialCode
     * @return array|null
     */
    public static function country($dialCode): ?array
    {
        $dialCode = ltrim((string)$dialCode, '+');
        foreach (self::countries() as $country) {
            if ((string)$country['dial_code'] === $dialCode) {
                return $country;
            }
        }
        return null;
    }

    /**
     * 既定の国番号
     * @return string
     */
    public static function defaultDialCode(): string
    {
        return (string)Config::get('phone.default_dial_code', '86');
    }

    /**
     * 国内表記のまま保存する国番号（既存互換）
     * @return string
     */
    protected static function nationalFormatDialCode(): string
    {
        return (string)Config::get('phone.national_format_dial_code', '86');
    }

    /**
     * この国番号を pattern で厳密に検証すべきか
     * @param string $dialCode
     * @return bool
     */
    protected static function isStrict(string $dialCode): bool
    {
        if (Config::get('phone.strict', false)) {
            return true;
        }
        $strictCodes = array_map('strval', Config::get('phone.strict_dial_codes', ['86']));
        return in_array($dialCode, $strictCodes, true);
    }

    /**
     * 緩い検証: 国番号と国内有意番号の桁数が妥当か
     * @param string $dialCode
     * @param string $national
     * @return bool
     */
    protected static function isPlausible(string $dialCode, string $national): bool
    {
        // 国番号が 0 で始まることはない（E.164）
        if ($dialCode === '' || $dialCode[0] === '0') {
            return false;
        }
        $dialLen = strlen($dialCode);
        $nationalLen = strlen($national);
        if ($dialLen < (int)Config::get('phone.dial_code_min_digits', 1)
            || $dialLen > (int)Config::get('phone.dial_code_max_digits', 4)) {
            return false;
        }
        if ($nationalLen < (int)Config::get('phone.national_min_digits', 5)
            || $nationalLen > (int)Config::get('phone.national_max_digits', 14)) {
            return false;
        }
        // E.164 は国番号を含めて最大15桁
        return ($dialLen + $nationalLen) <= (int)Config::get('phone.max_e164_digits', 15);
    }

    /**
     * 入力値から余分な記号（空白・ハイフン・括弧）を取り除く
     * @param string $phone
     * @return string
     */
    protected static function clean(string $phone): string
    {
        $phone = trim($phone);
        $plus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D/', '', $phone);
        return $plus ? '+' . $digits : (string)$digits;
    }

    /**
     * 国内有意番号を取り出す（トランクプレフィックスを除去）
     * @param string $national
     * @param array $country
     * @return string
     */
    protected static function stripTrunkPrefix(string $national, array $country): string
    {
        $prefix = (string)($country['trunk_prefix'] ?? '');
        if ($prefix === '' || !str_starts_with($national, $prefix)) {
            return $national;
        }
        $stripped = substr($national, strlen($prefix));
        if ($stripped === '') {
            return $national;
        }
        // 除去後がパターンに合致するなら確実に採用する
        if (!empty($country['pattern']) && preg_match($country['pattern'], $stripped)) {
            return $stripped;
        }
        // 緩い検証時は、パターンに合わなくても
        // 「0 で始まる国内表記」は国内有意番号へ寄せる（日本の 090… → 90…）
        if (!self::isStrict((string)$country['dial_code']) && $prefix === '0') {
            return $stripped;
        }
        return $national;
    }

    /**
     * 入力された番号を保存形式へ正規化する
     *
     * @param string $phone 利用者が入力した番号（ハイフン・先頭0付きでも可）
     * @param string|int|null $dialCode 選択された国番号。未指定なら既定値
     * @return string|null 正規化できなければ null
     */
    public static function normalize(string $phone, $dialCode = null): ?string
    {
        $phone = self::clean($phone);
        if ($phone === '' || $phone === '+') {
            return null;
        }

        // 入力が既に +国番号 で始まっている場合は、そこから国を判定する
        if (str_starts_with($phone, '+')) {
            $digits = substr($phone, 1);
            foreach (self::sortedByDialCodeLength() as $country) {
                $dc = (string)$country['dial_code'];
                if (str_starts_with($digits, $dc)) {
                    $national = substr($digits, strlen($dc));
                    $built = self::build($country, $national);
                    if ($built !== null) {
                        return $built;
                    }
                }
            }
            // 未登録の国番号。緩い検証なら E.164 として妥当かだけを見る
            return self::buildUnknown($digits);
        }

        $dialCode = $dialCode === null || $dialCode === '' ? self::defaultDialCode() : ltrim((string)$dialCode, '+');
        $country = self::country($dialCode);
        if ($country) {
            return self::build($country, $phone);
        }
        // countries に無い国番号でも、緩い検証なら受け付ける
        return self::buildUnknown($dialCode . ltrim($phone, '0'), $dialCode);
    }

    /**
     * countries に登録が無い国番号の番号を組み立てる
     *
     * 厳密検証が有効な場合は受け付けない。
     *
     * @param string $digits 国番号を含む全桁
     * @param string|null $dialCode 判明している場合の国番号
     * @return string|null
     */
    protected static function buildUnknown(string $digits, ?string $dialCode = null): ?string
    {
        if (Config::get('phone.strict', false)) {
            return null;
        }
        if ($dialCode === null) {
            // 国番号の区切りが分からないため、全体の桁数のみで妥当性を見る
            $min = (int)Config::get('phone.dial_code_min_digits', 1) + (int)Config::get('phone.national_min_digits', 5);
            $max = (int)Config::get('phone.max_e164_digits', 15);
            if (strlen($digits) < $min || strlen($digits) > $max) {
                return null;
            }
            return '+' . $digits;
        }
        $national = substr($digits, strlen($dialCode));
        if (!self::isPlausible($dialCode, $national)) {
            return null;
        }
        return '+' . $dialCode . $national;
    }

    /**
     * 国番号の長い順に並べる（+1 と +81 の誤判定を防ぐ）
     * @return array
     */
    protected static function sortedByDialCodeLength(): array
    {
        $countries = self::countries();
        usort($countries, function ($a, $b) {
            return strlen((string)$b['dial_code']) <=> strlen((string)$a['dial_code']);
        });
        return $countries;
    }

    /**
     * 国定義と国内番号から保存形式を組み立てる
     * @param array $country
     * @param string $national
     * @return string|null
     */
    protected static function build(array $country, string $national): ?string
    {
        $dialCode = (string)$country['dial_code'];
        $national = self::stripTrunkPrefix($national, $country);

        $matchesPattern = !empty($country['pattern']) && preg_match($country['pattern'], $national);
        if (!$matchesPattern) {
            // 厳密検証の国（既定では中国）はパターン不一致で拒否する。
            // それ以外は桁数が妥当なら受け付ける（番号帯の追加に追従できないため）。
            if (self::isStrict($dialCode) || !self::isPlausible($dialCode, $national)) {
                return null;
            }
        }

        // 中国番号は既存データと同じ国内表記のまま保存する
        if ($dialCode === self::nationalFormatDialCode()) {
            return $national;
        }
        $max = (int)Config::get('phone.max_e164_digits', 15);
        if (strlen($dialCode . $national) > $max) {
            return null;
        }
        return '+' . $dialCode . $national;
    }

    /**
     * 保存形式として妥当か
     * @param string $phone
     * @return bool
     */
    public static function isValid(string $phone): bool
    {
        return self::normalize($phone) !== null;
    }

    /**
     * SMS 送信用の E.164 形式（+国番号+国内番号）へ変換する
     *
     * 中国番号は国内表記で保存しているため、送信時に +86 を補う。
     *
     * @param string $phone 保存形式の番号
     * @return string
     */
    public static function toE164(string $phone): string
    {
        $phone = self::clean($phone);
        if (str_starts_with($phone, '+')) {
            return $phone;
        }
        return '+' . self::nationalFormatDialCode() . $phone;
    }

    /**
     * 表示用に国番号と国内番号へ分解する
     * @param string $phone 保存形式の番号
     * @return array{dial_code:string, national:string}
     */
    public static function split(string $phone): array
    {
        $phone = self::clean($phone);
        if (!str_starts_with($phone, '+')) {
            return ['dial_code' => self::nationalFormatDialCode(), 'national' => $phone];
        }
        $digits = substr($phone, 1);
        foreach (self::sortedByDialCodeLength() as $country) {
            $dc = (string)$country['dial_code'];
            if (str_starts_with($digits, $dc)) {
                return ['dial_code' => $dc, 'national' => substr($digits, strlen($dc))];
            }
        }
        return ['dial_code' => '', 'national' => $digits];
    }
}
