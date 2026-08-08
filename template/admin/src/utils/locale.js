// +---------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +---------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +---------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +---------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +---------------------------------------------------------------------

/**
 * 管理画面の言語判定
 *
 * vue-i18n のロケール識別子（zh-cn / en / ja）と、サーバーへ送る言語識別コード
 * （eb_lang_country.code）は別物なので、両方の対応表をここに集約する。
 */

// 表示名は各言語での自称表記にする（利用者が自分の言語を見つけやすい）
export const SUPPORTED_LOCALES = [
  { value: 'zh-cn', label: '简体中文', short: '中' },
  { value: 'ja', label: '日本語', short: '日' },
  { value: 'en', label: 'English', short: 'EN' },
];

export const FALLBACK_LOCALE = 'zh-cn';

// vue-i18n のロケール -> サーバーの言語識別コード
export const LOCALE_TO_LANG = {
  'zh-cn': 'zh-CN',
  en: 'en-US',
  ja: 'ja-JP',
  'zh-tw': 'zh-Hant',
};

// moment のロケール（識別子が i18n と一部異なる）
export const LOCALE_TO_MOMENT = {
  'zh-cn': 'zh-cn',
  en: 'en',
  ja: 'ja',
  'zh-tw': 'zh-tw',
};

/**
 * ブラウザの言語を対応ロケールへ正規化する
 *
 * navigator.language は 'ja' / 'ja-JP' / 'en-GB' / 'zh-Hans-CN' など揺れがあるため、
 * 言語サブタグで判定する。
 *
 * @param {string} raw
 * @returns {string|null} 対応外なら null
 */
export function normalizeLocale(raw) {
  const tag = String(raw || '')
    .trim()
    .toLowerCase()
    .replace(/_/g, '-');
  if (!tag) return null;
  const primary = tag.split('-')[0];
  if (primary === 'ja') return 'ja';
  if (primary === 'en') return 'en';
  // 繁体中文は有効化されていないため簡体中文に寄せる
  if (primary === 'zh') return 'zh-cn';
  return null;
}

/**
 * ブラウザの言語設定から使うロケールを決める
 * @returns {string}
 */
export function detectBrowserLocale() {
  if (typeof navigator === 'undefined') return FALLBACK_LOCALE;
  const langs = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language];
  for (const l of langs) {
    const normalized = normalizeLocale(l);
    if (normalized) return normalized;
  }
  return FALLBACK_LOCALE;
}

/**
 * 対応ロケールか
 * @param {string} locale
 * @returns {boolean}
 */
export function isSupported(locale) {
  return SUPPORTED_LOCALES.some((item) => item.value === locale);
}

/**
 * 実際に使うロケールを決める
 *
 * 優先順位:
 *   1. 利用者が明示的に選んだ言語（localStorage の themeConfigPrev）
 *   2. ブラウザの言語
 *   3. 簡体中文
 *
 * @param {object|null} storedThemeConfig localStorage から読んだ themeConfigPrev
 * @returns {string}
 */
export function resolveLocale(storedThemeConfig) {
  const saved = storedThemeConfig && storedThemeConfig.globalI18n;
  if (saved && isSupported(saved)) return saved;
  return detectBrowserLocale();
}

/**
 * 表示用の短縮ラベル
 * @param {string} locale
 * @returns {string}
 */
export function shortLabel(locale) {
  const found = SUPPORTED_LOCALES.find((item) => item.value === locale);
  return found ? found.short : SUPPORTED_LOCALES[0].short;
}
