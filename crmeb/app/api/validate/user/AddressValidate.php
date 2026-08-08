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
namespace app\api\validate\user;

use think\Validate;

/**
 * 用户地址验证类
 * Class AddressValidate
 * @package app\http\validates\user
 */
class AddressValidate extends Validate
{
    protected $rule = [
        'real_name' => 'require|max:25',
        'phone' => 'require|checkPhone',
        'province' => 'require',
        'city' => 'require',
        'district' => 'require',
        'detail' => 'require',
    ];

    protected $message = [
        'real_name.require' => '名称必须填写',
        'real_name.max' => '名称最多不能超过25个字符',
        'phone.require' => '手机号必须填写',
        'phone.checkPhone' => '手机号格式错误',
        'province.require' => '省必须填写',
        'city.require' => '市必须填写',
        'district.require' => '区/县必须填写',
        'detail.require' => '详细地址必须填写',
    ];

    /**
     * 配送先の連絡先電話番号
     *
     * ログインIDではなく連絡用なので、国際携帯番号に加えて
     * 従来どおり固定電話（市外局番付き）も許容する。
     *
     * @param string $value
     * @return bool
     */
    protected function checkPhone($value): bool
    {
        if (check_phone($value)) {
            return true;
        }
        // 固定電話・国際表記の連絡先（例: 03-1234-5678 / +81 3 1234 5678）
        return (bool)preg_match('/^\+?[0-9]{1,4}?[-\s0-9]{6,18}$/', trim((string)$value));
    }
}
