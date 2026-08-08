// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------

/**
 * 言語判定のユーティリティ
 *
 * ロケール識別子はサーバー側 eb_lang_type.file_name と揃える必要があります
 * （zh-CN / en-US / ja-JP）。この値がそのまま Cb-lang ヘッダーとして送られ、
 * eb_lang_country 経由で言語パックが選ばれます。
 */

// 対応ロケール。表示名は各言語での自称表記にする（利用者が自分の言語を見つけやすい）
export const SUPPORTED_LOCALES = [
	{ value: 'zh-CN', label: '简体中文', short: '中' },
	{ value: 'ja-JP', label: '日本語', short: '日' },
	{ value: 'en-US', label: 'English', short: 'EN' },
];

export const FALLBACK_LOCALE = 'zh-CN';

/**
 * ブラウザ／端末の言語を対応ロケールへ正規化する
 *
 * navigator.language は環境により 'ja' / 'ja-JP' / 'en-GB' / 'zh-Hans-CN' など
 * 揺れがあるため、言語サブタグで判定する。
 *
 * @param {string} raw
 * @returns {string|null} 対応外なら null
 */
export function normalizeLocale(raw) {
	const tag = String(raw || '').trim().toLowerCase().replace(/_/g, '-');
	if (!tag) return null;

	const primary = tag.split('-')[0];
	if (primary === 'ja') return 'ja-JP';
	if (primary === 'en') return 'en-US';
	if (primary === 'zh') {
		// 繁体中文は有効化されていないため簡体中文に寄せる
		return 'zh-CN';
	}
	return null;
}

/**
 * 端末の言語設定を取得する
 *
 * H5 は navigator、ミニプログラム／APP は uni.getSystemInfoSync を使う。
 *
 * @returns {string} 生のロケール文字列
 */
export function getDeviceLocale() {
	// #ifdef H5
	if (typeof navigator !== 'undefined') {
		const langs = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language];
		for (const l of langs) {
			if (normalizeLocale(l)) return l;
		}
		return navigator.language || '';
	}
	// #endif
	// #ifndef H5
	try {
		const info = uni.getSystemInfoSync();
		return info.language || info.hostLanguage || '';
	} catch (e) {
		return '';
	}
	// #endif
	return '';
}

/**
 * 実際に使うロケールを決める
 *
 * 優先順位:
 *   1. 利用者が明示的に選んだ言語（ローカルストレージ）
 *   2. 端末／ブラウザの言語
 *   3. 簡体中文
 *
 * @returns {string}
 */
export function resolveLocale() {
	const saved = uni.getStorageSync('locale');
	if (saved && isSupported(saved)) return saved;
	return normalizeLocale(getDeviceLocale()) || FALLBACK_LOCALE;
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
 * 表示用の短縮ラベル（ヘッダーのボタンに出す）
 * @param {string} locale
 * @returns {string}
 */
export function shortLabel(locale) {
	const found = SUPPORTED_LOCALES.find((item) => item.value === locale);
	return found ? found.short : SUPPORTED_LOCALES[0].short;
}
