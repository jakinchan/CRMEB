-- +----------------------------------------------------------------------
-- | CRMEB メール会員登録パッチ
-- +----------------------------------------------------------------------
-- | 適用: mysql -u<user> -p <database> < email_register.sql
-- | テーブル接頭辞が eb_ 以外の場合は一括置換してください。
-- +----------------------------------------------------------------------
-- | 背景:
-- |   会員登録が電話番号（＋SMS認証）のみで、SMSが届かない国や
-- |   固定電話しか持たない利用者が登録できませんでした。
-- |   メールアドレスでの登録・ログイン・パスワード再設定を追加します。
-- |
-- | 変更点:
-- |   1. eb_user.email を追加（ログインIDとして使用）
-- |   2. eb_user.account を 100 文字へ拡張
-- |      メールアドレスを account に入れるため。RFC 上のローカル部64＋@＋
-- |      ドメイン255 は現実には長すぎるので、実運用に十分な 100 とします。
-- |   3. email に検索用インデックスを追加（ログイン時に毎回引くため）
-- |
-- | 安全性:
-- |   既存カラムは幅を広げるだけ、email は追加のみでデータ変更はありません。
-- |   IF NOT EXISTS 相当のガードを入れており、再実行しても安全です（冪等）。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;

-- ============================================================
-- 1. eb_user.email を追加（既に存在する場合はスキップ）
-- ============================================================
SET @add_email := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `eb_user` ADD COLUMN `email` VARCHAR(100) NOT NULL DEFAULT '''' COMMENT ''邮箱'' AFTER `phone`',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user' AND COLUMN_NAME = 'email'
);
PREPARE stmt FROM @add_email;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2. account をメールアドレスが入る幅へ拡張
-- ============================================================
ALTER TABLE `eb_user`
  MODIFY COLUMN `account` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '用户账号';

-- ============================================================
-- 3. email の検索用インデックス（既に存在する場合はスキップ）
-- ============================================================
SET @add_index := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `eb_user` ADD INDEX `email` (`email`)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user' AND INDEX_NAME = 'email'
);
PREPARE stmt FROM @add_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
