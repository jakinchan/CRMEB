-- +----------------------------------------------------------------------
-- | CRMEB メール会員登録: 多言語パック追加（中国語 / English / 日本語）
-- +----------------------------------------------------------------------
-- | 適用: mysql -u<user> -p <database> < lang_email_register.sql
-- | 適用後は管理画面のキャッシュクリアを実行してください。
-- | INSERT は NOT EXISTS ガード付きのため再実行しても安全です（冪等）。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

-- ============================================================
-- 1. メール登録で使う文言を追加
-- ============================================================
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '您的验证码是', '您的验证码是', '您的验证码是', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '您的验证码是' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '您的验证码是', '您的验证码是', 'Your verification code is', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '您的验证码是' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '您的验证码是', '您的验证码是', '認証コードは', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '您的验证码是' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '验证码有效期为{:minute}分钟', '验证码有效期为{:minute}分钟', '验证码有效期为{:minute}分钟', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '验证码有效期为{:minute}分钟' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '验证码有效期为{:minute}分钟', '验证码有效期为{:minute}分钟', 'The code is valid for {:minute} minutes', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '验证码有效期为{:minute}分钟' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '验证码有效期为{:minute}分钟', '验证码有效期为{:minute}分钟', '認証コードの有効期限は{:minute}分です', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '验证码有效期为{:minute}分钟' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '如果这不是您本人的操作，请忽略此邮件', '如果这不是您本人的操作，请忽略此邮件', '如果这不是您本人的操作，请忽略此邮件', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '如果这不是您本人的操作，请忽略此邮件' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '如果这不是您本人的操作，请忽略此邮件', '如果这不是您本人的操作，请忽略此邮件', 'If you did not request this, please ignore this email', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '如果这不是您本人的操作，请忽略此邮件' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '如果这不是您本人的操作，请忽略此邮件', '如果这不是您本人的操作，请忽略此邮件', 'お客様による操作でない場合は、このメールを無視してください', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '如果这不是您本人的操作，请忽略此邮件' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '邮箱已注册', '邮箱已注册', '邮箱已注册', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '邮箱已注册' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '邮箱已注册', '邮箱已注册', 'This email is already registered', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '邮箱已注册' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '邮箱已注册', '邮箱已注册', 'このメールアドレスは既に登録されています', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '邮箱已注册' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '邮箱格式不正确', '邮箱格式不正确', '邮箱格式不正确', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '邮箱格式不正确' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '邮箱格式不正确', '邮箱格式不正确', 'The email address format is incorrect', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '邮箱格式不正确' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '邮箱格式不正确', '邮箱格式不正确', 'メールアドレスの形式が正しくありません', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '邮箱格式不正确' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '邮箱注册未开启', '邮箱注册未开启', '邮箱注册未开启', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '邮箱注册未开启' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '邮箱注册未开启', '邮箱注册未开启', 'Email registration is not enabled', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '邮箱注册未开启' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '邮箱注册未开启', '邮箱注册未开启', 'メールでの登録は無効です', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '邮箱注册未开启' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '请输入邮箱', '请输入邮箱', '请输入邮箱', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '请输入邮箱' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '请输入邮箱', '请输入邮箱', 'Please enter your email address', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '请输入邮箱' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '请输入邮箱', '请输入邮箱', 'メールアドレスを入力してください', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '请输入邮箱' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '邮箱注册', '邮箱注册', '邮箱注册', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '邮箱注册' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '邮箱注册', '邮箱注册', 'Email', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '邮箱注册' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '邮箱注册', '邮箱注册', 'メール', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '邮箱注册' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '手机号注册', '手机号注册', '手机号注册', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '手机号注册' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '手机号注册', '手机号注册', 'Phone', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '手机号注册' AND `is_admin` = 0);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '手机号注册', '手机号注册', '電話番号', 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '手机号注册' AND `is_admin` = 0);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '同一邮箱每分钟最多发送', '同一邮箱每分钟最多发送', '同一邮箱每分钟最多发送', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '同一邮箱每分钟最多发送' AND `is_admin` = 1);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '同一邮箱每分钟最多发送', '同一邮箱每分钟最多发送', 'Maximum emails per minute for the same address:', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '同一邮箱每分钟最多发送' AND `is_admin` = 1);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '同一邮箱每分钟最多发送', '同一邮箱每分钟最多发送', '同一アドレスへの1分あたりの送信上限は', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '同一邮箱每分钟最多发送' AND `is_admin` = 1);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '同一邮箱每天最多发送', '同一邮箱每天最多发送', '同一邮箱每天最多发送', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '同一邮箱每天最多发送' AND `is_admin` = 1);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '同一邮箱每天最多发送', '同一邮箱每天最多发送', 'Maximum emails per day for the same address:', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '同一邮箱每天最多发送' AND `is_admin` = 1);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '同一邮箱每天最多发送', '同一邮箱每天最多发送', '同一アドレスへの1日あたりの送信上限は', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '同一邮箱每天最多发送' AND `is_admin` = 1);

INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 1, '邮箱注册未开启', '邮箱注册未开启', '邮箱注册未开启', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 1 AND `code` = '邮箱注册未开启' AND `is_admin` = 1);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 2, '邮箱注册未开启', '邮箱注册未开启', 'Email registration is not enabled', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 2 AND `code` = '邮箱注册未开启' AND `is_admin` = 1);
INSERT INTO `eb_lang_code` (`type_id`, `code`, `remarks`, `lang_explain`, `is_admin`)
SELECT 6, '邮箱注册未开启', '邮箱注册未开启', 'メールでの登録は無効です', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_lang_code` WHERE `type_id` = 6 AND `code` = '邮箱注册未开启' AND `is_admin` = 1);

-- ============================================================
-- 2. 既存訳の是正
--    この文脈では「メールボックス」ではなく「メールアドレス」が適切
-- ============================================================
UPDATE `eb_lang_code` SET `lang_explain` = 'メールアドレス' WHERE `type_id` = 6 AND `code` = '邮箱';
UPDATE `eb_lang_code` SET `lang_explain` = '認証コード' WHERE `type_id` = 6 AND `code` = '验证码';
