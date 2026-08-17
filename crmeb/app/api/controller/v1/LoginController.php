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

namespace app\api\controller\v1;

use app\Request;
use app\services\message\notice\MailService;
use app\services\message\notice\SmsService;
use app\services\wechat\WechatServices;
use crmeb\services\mail\SmtpMailer;
use think\facade\Config;
use crmeb\services\CacheService;
use app\services\user\LoginServices;
use think\exception\ValidateException;
use app\api\validate\user\RegisterValidates;

/**
 * 微信小程序授权类
 * Class AuthController
 * @package app\api\controller
 */
class LoginController
{
    protected $services;

    /**
     * LoginController constructor.
     * @param LoginServices $services
     */
    public function __construct(LoginServices $services)
    {
        $this->services = $services;
    }

    /**
     * H5账号登陆
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function login(Request $request)
    {
        [$account, $password, $spread, $agent_id, $dialCode] = $request->postMore([
            'account', 'password', 'spread', ['agent_id', 0], ['dial_code', '']
        ], true);
        if (!$account || !$password) {
            return app('json')->fail('请输入账号和密码');
        }
        // 海外会員は E.164 形式で保存されているため、入力値を保存形式へ寄せて照合する。
        // 正規化できない場合はユーザー名など電話番号以外のアカウントとみなし、入力値をそのまま使う。
        $normalized = normalize_phone((string)$account, $dialCode);
        if ($normalized) {
            $account = $normalized;
        }
        if (strlen(trim($password)) < 6 || strlen(trim($password)) > 32) {
            return app('json')->fail('账号密码必须是在6到32位之间');
        }
        return app('json')->success('登录成功', $this->services->login($account, $password, $spread, $agent_id));
    }

    /**
     * 退出登录
     * @param Request $request
     * @return mixed
     */
    public function logout(Request $request)
    {
        $key = trim(ltrim($request->header(Config::get('cookie.token_name')), 'Bearer'));
        CacheService::delete(md5($key));
        return app('json')->success('退出成功');
    }

    /**
     * 获取发送验证码key
     * @return mixed
     */
    public function verifyCode()
    {
        $unique = password_hash(uniqid(true), PASSWORD_BCRYPT);
        CacheService::set('sms.key.' . $unique, 0, 300);
        $time = sys_config('verify_expire_time', 1);
        return app('json')->success(['key' => $unique, 'expire_time' => $time]);
    }

    /**
     * 获取图片验证码
     * @param Request $request
     * @return \think\Response
     */
    public function captcha(Request $request)
    {
        ob_clean();
        $rep = captcha();
        $key = app('session')->get('captcha.key');
        $uni = $request->get('key');
        if ($uni) {
            CacheService::set('sms.key.cap.' . $uni, $key, 300);
        }
        return $rep;
    }

    /**
     * 验证验证码是否正确
     * @param $uni
     * @param string $code
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    protected function checkCaptcha($uni, string $code): bool
    {
        $cacheName = 'sms.key.cap.' . $uni;
        if (!CacheService::has($cacheName)) {
            return false;
        }
        $key = CacheService::get($cacheName);
        $code = mb_strtolower($code, 'UTF-8');
        $res = password_verify($code, $key);
        if ($res) {
            CacheService::delete($cacheName);
        }
        return $res;
    }

    /**
     * 验证码发送
     * @param Request $request
     * @param SmsService $services
     * @return mixed
     */
    public function verify(Request $request, SmsService $services)
    {
        [$phone, $type, $key, $captchaType, $captchaVerification, $dialCode] = $request->postMore([
            ['phone', 0],
            ['type', ''],
            ['key', ''],
            ['captchaType', ''],
            ['captchaVerification', ''],
            ['dial_code', ''],
        ], true);

        // 海外番号に対応するため、以降の処理は保存形式に正規化した番号で行う
        // （送信回数の集計キーと検証コードのキーを登録・ログイン時と一致させる）
        $normalized = normalize_phone((string)$phone, $dialCode);
        if (!$normalized) {
            return app('json')->fail('手机号格式不正确');
        }
        $phone = $normalized;

        $keyName = 'sms.key.' . $key;
        if (!CacheService::has($keyName)) return app('json')->fail('发送验证码失败,请刷新页面重新获取');

        // 验证限制
        // 验证码每分钟发送上限
        $maxMinuteCountKey = 'sms.minute.' . $phone . date('YmdHi');
        $minuteCount = 0;
        if (CacheService::has($maxMinuteCountKey)) {
            $minuteCount = CacheService::get($maxMinuteCountKey) ?? 0;
            $maxMinuteCount = Config::get('sms.maxMinuteCount', 5);
            if ($minuteCount > $maxMinuteCount) return app('json')->fail('同一手机号每分钟最多发送' . $maxMinuteCount . '条');

        }

        // 验证码单个手机每日发送上限
        $maxPhoneCountKey = 'sms.phone.' . $phone . '.' . date('Ymd');
        $phoneCount = 0;
        if (CacheService::has($maxPhoneCountKey)) {
            $phoneCount = CacheService::get($maxPhoneCountKey) ?? 0;
            $maxPhoneCount = Config::get('sms.maxPhoneCount', 20);
            if ($phoneCount > $maxPhoneCount) return app('json')->fail('同一手机号每天最多发送' . $maxPhoneCount . '条');

        }

        // 验证码单个手机每日发送上限
        $maxIpCountKey = 'sms.ip.' . app()->request->ip() . '.' . date('Ymd');
        $ipCount = 0;
        if (CacheService::has($maxIpCountKey)) {
            $ipCount = CacheService::get($maxIpCountKey) ?? 0;
            $maxIpCount = Config::get('sms.maxIpCount', 50);
            if ($ipCount > $maxIpCount) return app('json')->fail('同一IP每天最多发送' . $maxIpCount . '条');

        }

        //二次验证
        try {
            aj_captcha_check_two($captchaType, $captchaVerification);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }

        try {
            validate(RegisterValidates::class)->scene('code')->check(['phone' => $phone]);
        } catch (ValidateException $e) {
            return app('json')->fail($e->getMessage());
        }
        $time = sys_config('verify_expire_time', 1);
        $smsCode = $this->services->verify($services, $phone, $type, $time);
        if ($smsCode) {
            CacheService::set('code_' . $phone, $smsCode, $time * 60);
            CacheService::set($maxMinuteCountKey, (int)$minuteCount + 1, 61);
            CacheService::set($maxPhoneCountKey, (int)$phoneCount + 1, 86401);
            CacheService::set($maxIpCountKey, (int)$ipCount + 1, 86401);
            return app('json')->success('验证码发送成功');
        } else {
            return app('json')->fail('验证码发送失败');
        }

    }

    /**
     * メールへ認証コードを送信する
     *
     * SMS が届かない国や電話番号を持たない利用者でも会員登録できるようにするため、
     * 電話番号版（verify）と同じ流れをメールで提供する。
     *
     * @param Request $request
     * @param MailService $services
     * @return mixed
     */
    public function emailVerify(Request $request, MailService $services)
    {
        [$email, $type, $key, $captchaType, $captchaVerification] = $request->postMore([
            ['email', ''],
            ['type', 'register'],
            ['key', ''],
            ['captchaType', ''],
            ['captchaVerification', ''],
        ], true);

        if (!$services->isEnabled()) {
            return app('json')->fail('邮箱注册未开启');
        }

        $email = trim((string)$email);
        if (!SmtpMailer::isValidAddress($email)) {
            return app('json')->fail('邮箱格式不正确');
        }

        $keyName = 'sms.key.' . $key;
        if (!CacheService::has($keyName)) {
            return app('json')->fail('发送验证码失败,请刷新页面重新获取');
        }

        // 送信回数の上限。SMS と同じ考え方で、迷惑メール化と課金事故を防ぐ
        $config = Config::get('mail', []);
        $limits = [
            ['mail.minute.' . $email . date('YmdHi'), (int)($config['maxMinuteCount'] ?? 5), 61, '同一邮箱每分钟最多发送'],
            ['mail.address.' . $email . '.' . date('Ymd'), (int)($config['maxAddressCount'] ?? 20), 86401, '同一邮箱每天最多发送'],
            ['mail.ip.' . $request->ip() . '.' . date('Ymd'), (int)($config['maxIpCount'] ?? 50), 86401, '同一IP每天最多发送'],
        ];
        $counters = [];
        foreach ($limits as [$cacheKey, $max, $ttl, $message]) {
            $count = CacheService::has($cacheKey) ? (int)CacheService::get($cacheKey) : 0;
            if ($count > $max) {
                return app('json')->fail($message . $max . '条');
            }
            $counters[] = [$cacheKey, $count, $ttl];
        }

        //二次验证
        try {
            aj_captcha_check_two($captchaType, $captchaVerification);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }

        $time = (int)($config['code_expire_minutes'] ?: sys_config('verify_expire_time', 1));
        try {
            $code = $this->services->verifyEmail($services, $email, (string)$type, $time);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }

        // 認証コードのキーは電話番号版と同じ名前空間を使い、登録処理を共通化する
        CacheService::set('code_' . $email, $code, $time * 60);
        foreach ($counters as [$cacheKey, $count, $ttl]) {
            CacheService::set($cacheKey, $count + 1, $ttl);
        }
        return app('json')->success('验证码发送成功');
    }

    /**
     * メールアドレスでのログイン（未登録なら自動で会員登録）
     *
     * @param Request $request
     * @return mixed
     */
    public function emailLogin(Request $request)
    {
        [$email, $captcha, $spread, $agent_id] = $request->postMore([
            ['email', ''], ['captcha', ''], ['spread', 0], ['agent_id', 0]
        ], true);

        /** @var MailService $mailService */
        $mailService = app()->make(MailService::class);
        if (!$mailService->isEnabled()) {
            return app('json')->fail('邮箱注册未开启');
        }

        $email = trim((string)$email);
        if (!SmtpMailer::isValidAddress($email)) {
            return app('json')->fail('邮箱格式不正确');
        }

        $verifyCode = CacheService::get('code_' . $email);
        if (!$verifyCode) {
            return app('json')->fail('请先获取验证码');
        }
        if (substr($verifyCode, 0, 6) != $captcha) {
            return app('json')->fail('验证码错误');
        }

        $user_type = $request->getFromType() ?: 'h5';
        try {
            $token = $this->services->emailLogin($email, $spread, $user_type, $agent_id);
        } catch (\Throwable $e) {
            return app('json')->fail($e->getMessage());
        }
        CacheService::delete('code_' . $email);
        return app('json')->success('登录成功', $token);
    }

    /**
     * H5注册新用户
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function register(Request $request)
    {
        [$account, $captcha, $password, $spread, $dialCode] = $request->postMore([['account', ''], ['captcha', ''], ['password', ''], ['spread', 0], ['dial_code', '']], true);

        // メールアドレスでの登録にも対応する。@ を含む場合はメールとして扱う。
        $accountType = str_contains((string)$account, '@') ? 'email' : 'phone';
        if ($accountType === 'email') {
            /** @var MailService $mailService */
            $mailService = app()->make(MailService::class);
            if (!$mailService->isEnabled()) {
                return app('json')->fail('邮箱注册未开启');
            }
            $account = trim((string)$account);
            if (!SmtpMailer::isValidAddress($account)) {
                return app('json')->fail('邮箱格式不正确');
            }
        } else {
            // 検証コード送信時と同じ保存形式に揃える
            $normalized = normalize_phone((string)$account, $dialCode);
            if (!$normalized) {
                return app('json')->fail('手机号格式不正确');
            }
            $account = $normalized;
            try {
                validate(RegisterValidates::class)->scene('register')->check(['account' => $account, 'captcha' => $captcha, 'password' => $password]);
            } catch (ValidateException $e) {
                return app('json')->fail($e->getError());
            }
        }
        if (strlen(trim($password)) < 6 || strlen(trim($password)) > 32) {
            return app('json')->fail('账号密码必须是在6到32位之间');
        }
        $verifyCode = CacheService::get('code_' . $account);
        if (!$verifyCode)
            return app('json')->fail('请先获取验证码');
        $verifyCode = substr($verifyCode, 0, 6);
        if ($verifyCode != $captcha)
            return app('json')->fail('验证码错误');
        if (md5($password) == md5('123456')) return app('json')->fail('密码太过简单，请输入较为复杂的密码');

        $registerStatus = $this->services->register($account, $password, $spread, 'h5', $accountType);
        if ($registerStatus) {
            return app('json')->success('注册成功');
        }
        return app('json')->fail('注册失败');
    }

    /**
     * 密码修改
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function reset(Request $request)
    {
        [$account, $captcha, $password, $dialCode] = $request->postMore([['account', ''], ['captcha', ''], ['password', ''], ['dial_code', '']], true);

        // メールアドレスでのパスワード再設定にも対応する
        if (str_contains((string)$account, '@')) {
            $account = trim((string)$account);
            if (!SmtpMailer::isValidAddress($account)) {
                return app('json')->fail('邮箱格式不正确');
            }
        } else {
            // 検証コード送信時と同じ保存形式に揃える
            $normalized = normalize_phone((string)$account, $dialCode);
            if (!$normalized) {
                return app('json')->fail('手机号格式不正确');
            }
            $account = $normalized;
            try {
                validate(RegisterValidates::class)->scene('register')->check(['account' => $account, 'captcha' => $captcha, 'password' => $password]);
            } catch (ValidateException $e) {
                return app('json')->fail($e->getError());
            }
        }
        if (strlen(trim($password)) < 6 || strlen(trim($password)) > 32) {
            return app('json')->fail('账号密码必须是在6到32位之间');
        }
        $verifyCode = CacheService::get('code_' . $account);
        if (!$verifyCode)
            return app('json')->fail('请先获取验证码');
        $verifyCode = substr($verifyCode, 0, 6);
        if ($verifyCode != $captcha) {
            return app('json')->fail('验证码错误');
        }
        if ($password == '123456') return app('json')->fail('密码太过简单，请输入较为复杂的密码');
        $resetStatus = $this->services->reset($account, $password);
        if ($resetStatus) return app('json')->success('修改成功');
        return app('json')->fail('修改失败');
    }

    /**
     * 手机号登录
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function mobile(Request $request)
    {
        [$phone, $captcha, $spread, $agent_id, $dialCode] = $request->postMore([['phone', ''], ['captcha', ''], ['spread', 0], ['agent_id', 0], ['dial_code', '']], true);

        // 検証コード送信時と同じ保存形式に揃える
        $normalized = normalize_phone((string)$phone, $dialCode);
        if (!$normalized) {
            return app('json')->fail('手机号格式不正确');
        }
        $phone = $normalized;

        //验证手机号
        try {
            validate(RegisterValidates::class)->scene('code')->check(['phone' => $phone]);
        } catch (ValidateException $e) {
            return app('json')->fail($e->getError());
        }

        //验证验证码
        $verifyCode = CacheService::get('code_' . $phone);
        if (!$verifyCode)
            return app('json')->fail('请先获取验证码');
        $verifyCode = substr($verifyCode, 0, 6);
        if ($verifyCode != $captcha) {
            return app('json')->fail('验证码错误');
        }
        $user_type = $request->getFromType() ? $request->getFromType() : 'h5';
        $token = $this->services->mobile($phone, $spread, $user_type, $agent_id);
        if ($token) {
            CacheService::delete('code_' . $phone);
            return app('json')->success('登录成功', $token);
        } else {
            return app('json')->fail('退出成功');
        }
    }

    /**
     * H5切换登陆
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function switch_h5(Request $request)
    {
        $from = $request->post('from', 'wechat');
        $user = $request->user();
        $token = $this->services->switchAccount($user, $from);
        if ($token) {
            $token['userInfo'] = $user;
            return app('json')->success('登录成功', $token);
        } else
            return app('json')->fail('退出成功');
    }

    /**
     * 绑定手机号
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function binding_phone(Request $request)
    {
        list($phone, $captcha, $key, $dialCode) = $request->postMore([
            ['phone', ''],
            ['captcha', ''],
            ['key', ''],
            ['dial_code', '']
        ], true);
        // 検証コード送信時と同じ保存形式に揃える（送信時と紐付け時で国番号がずれるとキャッシュキーが一致しない）
        $normalized = normalize_phone((string)$phone, $dialCode);
        if (!$normalized) {
            return app('json')->fail('手机号格式不正确');
        }
        $phone = $normalized;
        //验证手机号
        try {
            validate(RegisterValidates::class)->scene('code')->check(['phone' => $phone]);
        } catch (ValidateException $e) {
            return app('json')->fail($e->getError());
        }
        if (!$key) {
            return app('json')->fail('参数错误');
        }
        if (!$phone) {
            return app('json')->fail('请输入手机号');
        }
        //验证验证码
        $verifyCode = CacheService::get('code_' . $phone);
        if (!$verifyCode)
            return app('json')->fail('请先获取验证码');
        $verifyCode = substr($verifyCode, 0, 6);
        if ($verifyCode != $captcha) {
            return app('json')->fail('验证码错误');
        }
        $re = $this->services->bindind_phone($phone, $key);
        if ($re) {
            CacheService::delete('code_' . $phone);
            return app('json')->success('绑定成功', $re);
        } else
            return app('json')->fail('绑定失败');
    }

    /**
     * 绑定手机号
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function user_binding_phone(Request $request)
    {
        list($phone, $captcha, $step, $dialCode) = $request->postMore([
            ['phone', ''],
            ['captcha', ''],
            ['step', 0],
            ['dial_code', '']
        ], true);

        // 検証コード送信時と同じ保存形式に揃える
        $normalized = normalize_phone((string)$phone, $dialCode);
        if (!$normalized) {
            return app('json')->fail('手机号格式不正确');
        }
        $phone = $normalized;

        //验证手机号
        try {
            validate(RegisterValidates::class)->scene('code')->check(['phone' => $phone]);
        } catch (ValidateException $e) {
            return app('json')->fail($e->getError());
        }
        if (!$step) {
            //验证验证码
            $verifyCode = CacheService::get('code_' . $phone);
            if (!$verifyCode)
                return app('json')->fail('请先获取验证码');
            $verifyCode = substr($verifyCode, 0, 6);
            if ($verifyCode != $captcha)
                return app('json')->fail('验证码错误');
        }
        $uid = (int)$request->uid();
        $re = $this->services->userBindindPhone($uid, $phone, $step);
        if ($re) {
            CacheService::delete('code_' . $phone);
            return app('json')->success($re['msg'] ?? '绑定成功', $re['data'] ?? []);
        } else
            return app('json')->fail('绑定失败');
    }

    public function update_binding_phone(Request $request)
    {
        [$phone, $captcha, $dialCode] = $request->postMore([
            ['phone', ''],
            ['captcha', ''],
            ['dial_code', ''],
        ], true);

        // 検証コード送信時と同じ保存形式に揃える
        $normalized = normalize_phone((string)$phone, $dialCode);
        if (!$normalized) {
            return app('json')->fail('手机号格式不正确');
        }
        $phone = $normalized;

        //验证手机号
        try {
            validate(RegisterValidates::class)->scene('code')->check(['phone' => $phone]);
        } catch (ValidateException $e) {
            return app('json')->fail($e->getError());
        }
        //验证验证码
        $verifyCode = CacheService::get('code_' . $phone);
        if (!$verifyCode)
            return app('json')->fail('请先获取验证码');
        $verifyCode = substr($verifyCode, 0, 6);
        if ($verifyCode != $captcha)
            return app('json')->fail('验证码错误');
        $uid = (int)$request->uid();
        $re = $this->services->updateBindindPhone($uid, $phone);
        if ($re) {
            CacheService::delete('code_' . $phone);
            return app('json')->success($re['msg'] ?? '修改成功', $re['data'] ?? []);
        } else
            return app('json')->fail('修改失败');
    }

    /**
     * 设置扫描二维码状态
     * @param string $code
     * @return mixed
     */
    public function setLoginKey(string $code)
    {
        if (!$code) {
            return app('json')->fail('扫码失败请重新扫描');
        }
        $cacheCode = CacheService::get($code);
        if ($cacheCode === false || $cacheCode === null) {
            return app('json')->fail('二维码已过期请重新扫描');
        }
        CacheService::set($code, '0', 600);
        return app('json')->success();
    }

    /**
     * apple快捷登陆
     * @param Request $request
     * @param WechatServices $services
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function appleLogin(Request $request, WechatServices $services)
    {
        [$openId, $phone, $email, $captcha] = $request->postMore([
            ['openId', ''],
            ['phone', ''],
            ['email', ''],
            ['captcha', '']
        ], true);
        if ($phone) {
            if (!$captcha) {
                return app('json')->fail('请输入验证码');
            }
            //验证验证码
            $verifyCode = CacheService::get('code_' . $phone);
            if (!$verifyCode)
                return app('json')->fail('请先获取验证码');
            $verifyCode = substr($verifyCode, 0, 6);
            if ($verifyCode != $captcha) {
                CacheService::delete('code_' . $phone);
                return app('json')->fail('验证码错误');
            }
        } else {
            if (!$openId) {
                return app('json')->fail('参数错误');
            }
        }
        if ($email == '') $email = substr(md5($openId), 0, 12);
        $userInfo = [
            'openId' => $openId,
            'unionid' => '',
            'avatarUrl' => sys_config('h5_avatar'),
            'nickName' => $email,
        ];
        $token = $services->appAuth($userInfo, $phone, 'apple');
        if ($token) {
            return app('json')->success('登录成功', $token);
        } else if ($token === false) {
            return app('json')->success('登录成功', ['isbind' => true]);
        } else {
            return app('json')->fail('登录失败');
        }

    }

    /**
     * 滑块验证
     * @return mixed
     */
    public function ajcaptcha(Request $request)
    {
        $captchaType = $request->get('captchaType');
        return app('json')->success(aj_captcha_create($captchaType));
    }

    /**
     * 一次验证
     * @return mixed
     */
    public function ajcheck(Request $request)
    {
        [$token, $pointJson, $captchaType] = $request->postMore([
            ['token', ''],
            ['pointJson', ''],
            ['captchaType', ''],
        ], true);
        try {
            aj_captcha_check_one($captchaType, $token, $pointJson);
            return app('json')->success();
        } catch (\Throwable $e) {
            return app('json')->fail('验证码错误');
        }
    }

    /**
     * 远程登录接口
     * @param Request $request
     * @return \think\Response
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2024/5/21
     */
    public function remoteRegister(Request $request)
    {
        [$remote_token] = $request->getMore([
            ['remote_token', ''],
        ], true);
        if ($remote_token == '') return app('json')->success('登录失败', ['get_remote_login_url' => sys_config('get_remote_login_url')]);
        return app('json')->success('登录成功', $this->services->remoteRegister($remote_token));
    }
}
