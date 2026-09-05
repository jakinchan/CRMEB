# CRMEB portable production deployment / 可迁移生产部署

## 状態 / 状态

OCI Compute、さくらVPS、自宅Ubuntuで利用する標準Docker Compose構成です。クラウド専用APIは不要です。Cloudflare Containers用ではありません。

**構成作成済み、実機ビルド・動作未検証。** 現行互換のPHP 7.4.33はサポート終了済みです。公開前にサポート対象PHPへの移行または保守提供元を決定し、依存ライブラリの監査と業務テストを実施してください。MySQL/Redis/Nginxのタグも更新され得るため、検証後にdigest固定してください。

| Software | Configuration |
|---|---|
| PHP | 7.4.33 / FPM / Redis extension 5.3.7 (legacy compatibility) |
| Composer | 2.2 / composer.lock / no-dev |
| MySQL | 8.0 (override MYSQL_IMAGE) |
| Redis | 7.2 / AOF (override REDIS_IMAGE) |
| Nginx | 1.28 |
| HTTPS | Caddy 2, optional |
| Host | Linux + Docker Engine + Compose v2 + openssl |

DB・Redis・FPM・Workermanのポートはホストへ公開しません。初期状態は127.0.0.1:8088のみ。データはnamed volume、ソースはイメージに格納します。秘密情報はCompose secrets。ログサイズ制限、依存サービスの起動待ちを設定しています。healthcheckはプロセス/通信確認であり、業務の正常性は保証しません。

## 準備と起動（Ubuntu / Bash）

GitHubから独自改修を含むレビュー済みコミットをcloneし、リポジトリ直下から実行します。既存のhelp/docker環境とは別プロジェクトです。

```bash
cd deploy/production
sh init-secrets.sh
# .envのAPP_TAGを対象commit、DB_PREFIXを既存DBに合わせる
docker compose config --quiet
docker compose build php nginx
docker compose up -d --wait mysql redis
```

既存DBを移す場合は、旧APP_KEYを`secrets/app_key`へ安全に保存してください。独自の環境設定、証明書や第三者API設定も棚卸しし、必要なSecrets/mountを追加します。既存`.env`をイメージへコピーしないでください。生成済みパスワードを変えても既存DBユーザーのパスワードは変わりません。

本構成はインストーラーを削除・遮断しています。新規導入は隔離した既存開発環境でCRMEBの初期セットアップを完了し、そのDBを移してください。空のDBに対してWeb/ジョブを起動しても使用できません。

以下の`/secure/...`を実ファイルへ置換します。**復元先は新しい空のDB/volumeに限ります。** 既存稼働DBに実行しないでください。

```bash
docker compose exec -T mysql sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" exec mysql -uroot "$MYSQL_DATABASE"' < /secure/database.sql
docker compose run --rm --no-deps -T --user root --entrypoint tar php -xzf - -C /var/www/public < /secure/files.tar.gz
docker compose run --rm --no-deps --user root --entrypoint sh php -c 'chown -R www-data:www-data public/uploads public/statics'
docker compose up -d --wait php nginx
docker compose --profile jobs up -d
docker compose --profile jobs ps
curl -f http://127.0.0.1:8088/healthz
```

files.tar.gzは`uploads/`と`statics/`をトップレベルに含む信頼済みアーカイブを使用します。既存の他の保存先（証明書、エクスポート等）は別途保存先を確認して移管してください。Redisは単なるキャッシュではなくキューを含むため、旧環境でジョブを完了させるか、停止後のRDBも復元します。

Redisを空のvolumeへ復元する場合、redisサービスを停止し、`docker compose run --rm --no-deps -T --entrypoint sh redis -c 'cat > /data/dump.rdb; chown redis:redis /data/dump.rdb' < /secure/redis.rdb`を実行します。初回のみ`docker compose run --rm --no-deps --entrypoint sh redis -c 'exec docker-entrypoint.sh redis-server --appendonly no --requirepass "$(cat /run/secrets/redis_password)"'`で起動し、別端末からそのコンテナに`redis-cli CONFIG SET appendonly yes`（REDISCLI_AUTHを同Secretから設定）を実行してAOF生成完了を確認、正常停止します。その後通常のredisサービスを起動します。既存AOFがあるとRDBより優先されるため、既存volumeへの上書き復元はしません。復元訓練でキュー件数・再実行の冪等性を確認してください。

## HTTPS・OCI公開

独自ドメインのDNSをVMへ向け、`.env`へ`DOMAIN=shop.example.com`を追加します。OCIのNSG/Security ListとホストFWでは80/443を許可、SSHは管理元に限定します。3306/6379/9000/40001〜40003は公開しません。

```bash
docker compose -f compose.yml -f compose.https.yml --profile jobs up -d
```

CaddyがTLSを終端します。公開到達性とDNSが証明書取得の前提です。独自ドメインをCRMEBのサイトURL・決済通知先・微信設定にも反映します。既存の80/443サービスがある場合は追加Caddyを起動せず既存リバースプロキシから127.0.0.1:8088へ転送してください。

OCI Ampereはarm64です。全ベースイメージ/拡張が対応するか実際にbuildして確認してください。amd64のイメージをそのまま使えるとは限りません。運用開始の目安は2CPU・4GB以上ですが、必要容量は商品数と負荷で計測します。

## バックアップ・更新・検証

メンテナンスで書き込みと外部決済通知を制御してから実行します。失敗時は原因確認後にサービスを戻してください。

```bash
docker compose --profile jobs stop nginx php queue timer workerman
sh backup.sh
docker compose --profile jobs up -d
```

バックアップはSQL、uploads/statics、Redis RDBを含みます。Secretsは別の暗号化した保管先へ保存し、バックアップを別ホストへ転送してください。バックアップの成功は復元成功を保証しないため、別Composeプロジェクトで復元訓練を行います。`docker compose down -v`はデータを消すため運用手順では使いません。

更新時は旧commit/APP_TAGとバックアップを保管し、新commitからbuild、メンテナンスで旧ジョブ停止後に起動します。DB変更があった場合はイメージだけ戻さず、切替後の注文を保全して整合させます。管理画面のオンライン更新はソースを永続化しない本構成では使用しません。

公開前に管理者/会員ログイン、商品画像、注文/在庫/決済再通知、キュー、タイマー、/noticeと/msgのWebSocket、再起動後の永続化を確認してください。`runtime`は一時領域のためCRMEB独自のファイルログは消えます。必要な監査ログは外部収集か専用volumeを追加します。

## 中文操作说明

这套配置适用于OCI Compute、普通VPS和Ubuntu主机，不依赖云厂商API。源码构建进镜像，MySQL、Redis、uploads/statics使用持久卷，密码使用Compose secrets。默认只绑定本机8088；公网HTTPS可选Caddy。

执行上方命令：生成密钥 → build → 启动MySQL/Redis → 导入已初始化数据库及附件 → 启动PHP/Nginx → 启动jobs profile。新安装需先在隔离环境完成安装后导入，本镜像不提供公网安装器。迁移时保留原APP_KEY并检查其他业务配置。不要直接覆盖正在使用的数据库。Redis队列需先清空处理或按上方流程恢复RDB并生成AOF。

生产域名配置到`.env`的DOMAIN，并开放80/443；数据库及内部通信端口不要开放。OCI ARM实例需要实际验证arm64构建。备份前暂停写入及后台任务，运行backup.sh并将备份移至异地；密钥单独加密保存。先进行独立环境恢复演练，再上线。

**尚未完成实际镜像构建和运行验证。PHP 7.4已经停止官方支持，上线前需要升级兼容验证或明确维护方案。** 现有依赖的安全问题不会因Docker化而自动解决。

References: [Compose secrets](https://docs.docker.com/compose/how-tos/use-secrets/), [PHP support](https://www.php.net/supported-versions.php).
