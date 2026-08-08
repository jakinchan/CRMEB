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
 * 注册验证
 * Class RegisterValidates
 * @package app\http\validates\user
 */
class RegisterValidates extends Validate
{
    protected $rule = [
        'phone' => 'require|checkPhone',
        'account' => 'require|checkPhone',
        'captcha' => 'require|length:6',
        'password' => 'require',
    ];

    protected $message = [
        'phone.require' => '请输入手机号',
        'phone.checkPhone' => '手机号格式不正确',
        'account.require' => '请输入手机号',
        'account.checkPhone' => '手机号格式不正确',
        'captcha.require' => '请输入验证码',
        'captcha.length' => '验证码错误',
        'password.require' => '密码必须是在6到16位之间',
    ];

    /**
     * 手机号検証（海外番号対応）
     *
     * 検証時点の値は既に保存形式へ正規化されている想定。
     * 中国番号は国内表記、それ以外は E.164 形式を受け付ける。
     *
     * @param string $value
     * @return bool
     */
    protected function checkPhone($value): bool
    {
        return check_phone($value);
    }


    public function sceneCode()
    {
        return $this->only(['phone']);
    }


    public function sceneRegister()
    {
        return $this->only(['account', 'captcha', 'password']);
    }
}
