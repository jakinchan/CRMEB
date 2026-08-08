import Vue from 'vue';
import VueI18n from 'vue-i18n'
import { resolveLocale, FALLBACK_LOCALE } from '@/utils/locale.js';

Vue.use(VueI18n)

// 初回表示は端末／ブラウザの言語を使う。利用者が明示的に選んだ場合はそれを優先する。
// 判定は utils/locale.js に集約し、H5 / ミニプログラム / APP で同じ結果になるようにする。
const lang = resolveLocale();

const i18n = new VueI18n({
	locale: lang,
	fallbackLocale: FALLBACK_LOCALE,
	messages: uni.getStorageSync('localeJson'),
	silentTranslationWarn: true, // 去除国际化警告
})
export default i18n
