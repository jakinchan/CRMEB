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

namespace app\services\message\notice;

use app\services\BaseServices;
use crmeb\exceptions\ApiException;
use crmeb\services\mail\SmtpMailer;
use think\facade\Config;
use think\facade\Log;

/**
 * メール通知（会員登録の認証コード送信）
 *
 * SMS が届かない国や、電話番号を持たない利用者でも会員登録できるようにするため、
 * メールでの認証コード送信を提供します。
 *
 * Class MailService
 * @package app\services\message\notice
 */
class MailService extends BaseServices
{
    /**
     * メール送信が使える状態か
     * @return bool
     */
    public function isEnabled(): bool
    {
        $config = Config::get('mail', []);
        return !empty($config['enable'])
            && !empty($config['host'])
            && !empty($config['from_address']);
    }

    /**
     * 認証コードを送信する
     *
     * @param string $email 宛先
     * @param string $code 認証コード
     * @param int $expireMinutes 有効時間（分）
     * @return bool
     */
    public function sendVerifyCode(string $email, string $code, int $expireMinutes): bool
    {
        if (!$this->isEnabled()) {
            throw new ApiException('メール送信が設定されていません');
        }
        $siteName = sys_config('site_name') ?: 'CRMEB';

        // 件名・本文は多言語パックを通す（未登録なら原文のまま返る）
        $subject = sprintf('[%s] %s', $siteName, getLang('验证码'));
        $body = implode("\n", [
            getLang('您的验证码是') . ' ' . $code,
            sprintf(getLang('验证码有效期为{:minute}分钟'), $expireMinutes),
            '',
            getLang('如果这不是您本人的操作，请忽略此邮件'),
            '',
            '--',
            $siteName,
        ]);
        // getLang は {:var} 形式の置換を前提としているため、sprintf ではなく明示的に置換する
        $body = str_replace('{:minute}', (string)$expireMinutes, $body);

        return $this->send($email, $subject, $body);
    }

    /**
     * メールを送信する
     *
     * @param string $email
     * @param string $subject
     * @param string $body
     * @return bool
     */
    public function send(string $email, string $subject, string $body): bool
    {
        $mailer = new SmtpMailer(Config::get('mail', []));
        try {
            return $mailer->send($email, $subject, $body);
        } catch (\Throwable $e) {
            // 送信内容は記録するが、宛先以外の個人情報は残さない
            Log::error(sprintf('メール送信に失敗しました: %s / SMTP応答: %s',
                $e->getMessage(), $mailer->getLastResponse()));
            throw new ApiException('メールの送信に失敗しました');
        }
    }
}
