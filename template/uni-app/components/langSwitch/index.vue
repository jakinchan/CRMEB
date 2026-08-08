<template>
	<!--
		言語切替ボタン（中 / 日 / EN）
		ログイン不要で使えるようにしており、初回訪問者もブラウザ言語から切り替えられる。
	-->
	<picker mode="selector" :range="labels" :value="index" @change="onChange">
		<view class="lang-switch">
			<text class="iconfont icon-diqiu" v-if="showIcon"></text>
			<text class="lang-switch__text">{{ shortText }}</text>
			<text class="iconfont icon-xiangxia lang-switch__arrow"></text>
		</view>
	</picker>
</template>

<script>
import { SUPPORTED_LOCALES, resolveLocale, shortLabel } from '@/utils/locale.js';
import { getLangJson, getLangList } from '@/api/user.js';

export default {
	name: 'langSwitch',
	props: {
		showIcon: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		return {
			// 管理画面で有効化された言語に追従させたいのでサーバーから取得する。
			// 取得できるまで／失敗時は静的な一覧で動かす。
			locales: SUPPORTED_LOCALES,
			index: 0,
		};
	},
	computed: {
		labels() {
			return this.locales.map((item) => item.label);
		},
		shortText() {
			return shortLabel(this.locales[this.index] ? this.locales[this.index].value : '');
		},
	},
	created() {
		this.syncIndex();
		this.loadLocales();
	},
	methods: {
		/** 現在の言語を選択位置に反映する */
		syncIndex() {
			const current = resolveLocale();
			const i = this.locales.findIndex((item) => item.value === current);
			this.index = i > -1 ? i : 0;
		},
		/** 有効な言語一覧をサーバーから取得する */
		loadLocales() {
			getLangList()
				.then((res) => {
					const list = (res.data || [])
						.map((item) => ({
							value: item.value,
							label: item.name,
							short: shortLabel(item.value),
						}))
						.filter((item) => item.value);
					if (list.length) {
						this.locales = list;
						this.syncIndex();
					}
				})
				.catch(() => {
					// 取得できなくても静的一覧で切り替えられるようにしておく
				});
		},
		onChange(e) {
			const next = this.locales[Number(e.detail.value)];
			if (!next || next.value === this.locales[this.index].value) return;
			this.index = Number(e.detail.value);
			this.apply(next.value);
		},
		/**
		 * 選択した言語を保存し、言語パックを取り直して即時反映する
		 */
		apply(locale) {
			// 先に保存する。request.js が Cb-lang をここから読むため、
			// この順序でないと取得する言語パックが切り替わらない。
			uni.setStorageSync('locale', locale);
			getLangJson()
				.then((res) => {
					const key = Object.keys(res.data)[0];
					uni.setStorageSync('localeJson', res.data);
					this.$i18n.setLocaleMessage(key, res.data[key]);
					this.$nextTick(() => {
						this.$i18n.locale = key;
						this.$emit('change', key);
					});
				})
				.catch(() => {
					this.$util.Tips({ title: this.$t(`加载失败`) });
				});
		},
	},
};
</script>

<style scoped lang="scss">
.lang-switch {
	display: flex;
	align-items: center;
	padding: 0 10rpx;
	font-size: 26rpx;
	color: inherit;
	white-space: nowrap;
}

.lang-switch__text {
	margin: 0 4rpx;
}

.lang-switch__arrow {
	font-size: 20rpx;
	opacity: 0.6;
}
</style>
