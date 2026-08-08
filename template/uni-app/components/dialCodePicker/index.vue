<template>
	<!-- 国番号セレクタ: 海外会員が自国の電話番号で登録できるようにする -->
	<picker mode="selector" :range="labels" :value="index" @change="onChange" :disabled="disabled">
		<view class="dial-code">
			<text class="code">+{{ current.dial_code }}</text>
			<text class="iconfont icon-xiangxia"></text>
		</view>
	</picker>
</template>

<script>
import { getDialCodeList } from '@/api/public';

export default {
	name: 'dialCodePicker',
	props: {
		// v-model で選択中の国番号（"81" など。+ は含まない）
		value: {
			type: [String, Number],
			default: '',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			list: [],
			index: 0,
		};
	},
	computed: {
		current() {
			return this.list[this.index] || { dial_code: this.value || '86' };
		},
		// 表示名は端末の言語に合わせて選ぶ
		labels() {
			return this.list.map((item) => `+${item.dial_code}  ${this.localName(item)}`);
		},
	},
	created() {
		this.loadList();
	},
	methods: {
		localName(item) {
			const locale = this.$i18n ? this.$i18n.locale : 'zh-CN';
			if (locale === 'ja-JP') return item.name_ja || item.name;
			if (locale === 'en-US') return item.name_en || item.name;
			return item.name;
		},
		loadList() {
			getDialCodeList()
				.then((res) => {
					this.list = res.data.list || [];
					// 親から指定が無ければサーバー既定値を採用する
					const target = this.value || res.data.default;
					const i = this.list.findIndex((item) => String(item.dial_code) === String(target));
					this.index = i > -1 ? i : 0;
					this.emit();
				})
				.catch(() => {
					// 取得に失敗しても登録手続きを止めないため、中国番号にフォールバックする
					this.list = [{ dial_code: '86', name: '中国', name_en: 'China', name_ja: '中国' }];
					this.index = 0;
					this.emit();
				});
		},
		onChange(e) {
			this.index = Number(e.detail.value);
			this.emit();
		},
		emit() {
			this.$emit('input', String(this.current.dial_code));
			this.$emit('change', this.current);
		},
	},
};
</script>

<style scoped lang="scss">
.dial-code {
	display: flex;
	align-items: center;
	padding-right: 16rpx;
	margin-right: 16rpx;
	border-right: 1rpx solid #e5e5e5;
	white-space: nowrap;

	.code {
		font-size: 28rpx;
		color: #333;
	}

	.iconfont {
		margin-left: 6rpx;
		font-size: 20rpx;
		color: #999;
	}
}
</style>
