// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2024 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed CRMEB并不是自由软件，未经许可不能去掉CRMEB相关版权
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------


/**
 * 验证小数点后两位及多个小数
 * money 金额
*/ 
export function isMoney(money) {
  var reg = /(^[1-9]([0-9]+)?(\.[0-9]{1,2})?$)|(^(0){1}$)|(^[0-9]\.[0-9]([0-9])?$)/
  if (reg.test(money)) {
    return true
  } else {
    return false
  }
}

/**
 * 验证手机号码（海外番号にも対応）
 *
 * 国番号 86（中国）は従来どおり国内表記（1 で始まる11桁）。
 * それ以外の国は「国内有意番号として妥当な桁数か」だけを緩く確認し、
 * 厳密な検証はサーバー側（config/phone.php）に任せる。
 * 国ごとの細かなパターンを端末側に二重管理しないため。
 *
 * @param {string} phone 利用者が入力した番号（ハイフン・先頭0付きでも可）
 * @param {string|number} dialCode 選択された国番号。省略時は 86 とみなす
 * @returns {boolean}
 */
export function checkPhone(phone, dialCode) {
  const digits = String(phone == null ? '' : phone).replace(/\D/g, '')
  const code = String(dialCode == null || dialCode === '' ? '86' : dialCode).replace(/\D/g, '')
  // 中国番号はログインIDとして使われるため従来どおり厳密に検証する
  if (code === '86') {
    return /^1[3-9]\d{9}$/.test(digits)
  }
  // 国番号が 0 で始まることはない（E.164）
  if (!code || code[0] === '0') return false
  // 先頭の 0（国内発信用トランクプレフィックス）は除去して桁数を見る
  const national = digits.replace(/^0+/, '')
  // サーバー側 config/phone.php の national_min/max_digits と揃えている
  if (national.length < 5 || national.length > 14) return false
  // E.164 は国番号を含めて最大15桁
  return code.length + national.length <= 15
}

/**
 * 入力された番号を送信用の形に整える
 *
 * 中国番号はそのまま、それ以外は先頭0を除去した国内有意番号を返す。
 * 国番号は dial_code として別に送り、サーバー側で E.164 に組み立てる。
 *
 * @param {string} phone
 * @param {string|number} dialCode
 * @returns {string}
 */
export function formatPhone(phone, dialCode) {
  const digits = String(phone == null ? '' : phone).replace(/\D/g, '')
  const code = String(dialCode == null || dialCode === '' ? '86' : dialCode).replace(/\D/g, '')
  if (code === '86') return digits
  return digits.replace(/^0+/, '')
}

/**
 * 函数防抖 (只执行最后一次点击)
 * @param fn
 * @param delay
 * @returns {Function}
 * @constructor
 */
export const Debounce = (fn, t) => {
  const delay = t || 500
  let timer
  return function() {
    const args = arguments
    if (timer) {
      clearTimeout(timer)
    }
    timer = setTimeout(() => {
      timer = null
      fn.apply(this, args)
    }, delay)
  }
}
/**
 * 函数节流
 * @param fn
 * @param interval
 * @returns {Function}
 * @constructor
 */
export const Throttle = (fn, t) => {
  let last
  let timer
  const interval = t || 500
  return function() {
    const args = arguments
    const now = +new Date()
    if (last && now - last < interval) {
      clearTimeout(timer)
      timer = setTimeout(() => {
        last = now
        fn.apply(this, args)
      }, interval)
    } else {
      last = now
      fn.apply(this, args)
    }
  }
}



