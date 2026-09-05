# CRMEB → Cloudflare Containers 移行手順 / 迁移步骤

確認日 / 确认日期：2026-09-05

## 日本語

### 移行範囲と前提

これは実装・検証を含む移行手順書です。移行用イメージやアダプターは未実装で、コマンドだけで現在のCRMEB全体が移行できる状態ではありません。Cloudflareへのデプロイや本番データ移行は実施していません。

Cloudflare Containersのディスクは一時領域です。停止後はイメージの状態から起動します。MySQLデータ、Redisのキュー、商品画像をコンテナ内だけに保存する構成では、本番データを維持できません。スリープ無効化でもホスト停止や再デプロイには対応できません。[公式FAQ](https://developers.cloudflare.com/containers/faq/)

全機能を維持して移行する現実的な構成は以下です。外部MySQL・Redisも使用するため「Cloudflareのみで完結」ではありません。

| 対象 | 移行先・必要な変更 |
|---|---|
| 管理画面・H5・PHP API | Containers：NginxとPHP-FPMを同一イメージへまとめる |
| MySQL | 外部の永続MySQL。既存SQL、照合順序、トランザクションとの互換性を検証 |
| Redis | 外部の永続Redis互換サービス。キャッシュ、セッション、キューを接続 |
| 商品画像・添付・生成ファイル | R2。CRMEBのアップロードアダプター追加と既存ファイル移行 |
| キュー・タイマー | 専用Container。停止後の再起動、ジョブ再試行、重複実行防止を実装 |
| Workermanのチャット・通知 | Container内で実行し、NginxからWebSocketを中継。再接続を実装 |
| 微信ミニプログラム | フロントを再ビルドし、微信側へ別途アップロード・審査。サーバー移行だけでは更新されない |

確認した現行ファイル：`help/docker/docker-compose.yml`、`help/docker/nginx/vhost.conf`、`crmeb/config/{database,cache,queue,session}.php`、`crmeb/crmeb/services/upload/storage/`。

### 1. ソースと移行前の状態を固定する

GitHub上の実際のリポジトリURLと、移行対象のコミットを指定します。公式ソースだけをcloneした場合、ローカルの独自改修は含まれません。まず改修を含むレビュー済みコミットを用意してください。

Ubuntu / Bash：新しい作業ディレクトリで実行します。

```bash
read -r -p 'GitHub repository URL: ' CRMEB_REPO_URL
git clone "$CRMEB_REPO_URL" crmeb-cloudflare
cd crmeb-cloudflare
read -r -p 'Reviewed commit SHA: ' CRMEB_COMMIT
git checkout --detach "$CRMEB_COMMIT"
git rev-parse HEAD
```

稼働側ではMySQLの論理バックアップ、アップロードファイル、設定、未処理キューを保全し、隔離環境で復元できることを確認します。稼働中のMySQLデータディレクトリを単純コピーする方法は使いません。`.env`、SQLダンプ、決済証明書、個人情報をGitやイメージに含めないでください。

### 2. 永続サービスを準備する

1. 外部MySQLに検証用DBを作り、バックアップを復元します。本番DBへ直接接続して検証しません。
2. Redis互換サービスについて、認証・TLS・使用コマンド・DB番号・キューの永続化を確認します。HTTP APIのみのサービスは既存PHPクライアントにそのまま接続できません。
3. Cloudflare R2に商品画像用バケットを作成し、公開画像用の独自ドメイン（例：`media.example.com`）を設定します。非公開ファイルは別管理し、認証付き取得または署名URLを使います。
4. MySQL/Redisの接続経路をTLSとアクセス制限付きで用意します。Containerの送信元を固定IPと仮定しないでください。接続制限に必要なら固定出口のプロキシ等を先に用意します。

現行の`database.php`はPDO接続パラメーターが空です。外部DBのCA検証を含むTLS設定を追加・検証します。Redisもcache、queue、sessionの全接続でTLSと認証を検証します。ホスト名の置換だけでは完了しません。

### 3. CRMEBを永続ディスク不要に改修する

- 設定はWorker SecretsからContainerの環境変数へ渡し、起動時にCRMEBの設定へ変換します。現在の設定キーは`database.hostname`、`database.hostport`、`redis.redis_hostname`等です。任意の`DB_HOST`を設定しても自動的には反映されません。
- キャッシュをRedisに、セッションを`session.type=redis`に切り替えます。キューは現在もRedis依存です。
- 現行アップロードドライバーにR2専用実装は見当たりません。S3互換のR2アダプターを追加し、アップロード・削除・URL生成・サムネイル・エクスポートを確認します。既存画像のキーとDB内URLも移行します。
- ローカルファイル必須のPDF等は一時領域で生成してからR2へ保存します。R2 FUSEをMySQLのデータディスクとして使用する設計にはしません。
- ログはstdout/stderrへ出します。オンライン更新でソースを書き換える運用をやめ、変更はイメージ再ビルドで反映します。
- インストールロックや必須設定が消えることで再インストール画面にならないよう、ビルド・起動処理を整備します。毎回の起動でDB初期化やマイグレーションを実行しません。

Secretsの渡し方：[公式例](https://developers.cloudflare.com/containers/examples/env-vars-and-secrets/)

### 4. CRMEB用イメージを作る

既存Composeはホストからソースをマウントするため、そのままでは移行できません。以下を満たす移行用`Dockerfile`を作成します。

1. CRMEBソース・Composer依存・ビルド済み管理画面/H5をイメージに格納します。Composerやフロントのlockファイルを使い、再現可能にします。
2. PHP拡張は現行との互換性を維持します。`docs/public/Dockerfile`はPHP 7.4.33ベースの参考で、Nginxやアプリを含む完成イメージではありません。PHPのサポート更新は別途互換性試験を行います。
3. Nginxを`0.0.0.0:8080`、PHP-FPMを`127.0.0.1:9000`へ設定します。ドキュメントルートは`/var/www/public`を維持します。
4. `/notice`を`127.0.0.1:40001`、`/msg`を`127.0.0.1:40002`へ中継し、WebSocket Upgradeを保持します。現在の固定内部IPを持ち込みません。
5. PID 1のプロセスマネージャーで子プロセスを監視し、SIGTERMを各プロセスへ転送します。HTTPS終端後のスキームを正しく扱い、Cookieとリダイレクトを検証します。
6. `.dockerignore`で`.git`、秘密設定、SQLダンプ、バックアップ、実顧客ファイルを除外します。

Cloudflare向けイメージは`linux/amd64`で作成します。[公式ライフサイクル](https://developers.cloudflare.com/containers/concepts/architecture/)

### 5. Worker / Containersプロジェクトを準備する

DockerとNode.js/npmを用意し、Workers Paidを有効にします。以下は移行用ディレクトリ内で実行します。テンプレートの初回質問ではデプロイを保留し、生成されたサンプルをCRMEB用に置き換えます。

```bash
docker info
node --version
npm --version
npm create cloudflare@latest -- --template=cloudflare/templates/containers-template
```

生成したプロジェクトへ移動し、次を設定します。

- Wrangler：`containers[].image`に完成したDockerfile、`class_name`にCRMEBのContainerクラスを指定。
- `durable_objects.bindings`と`migrations[].new_sqlite_classes`に同じクラス名を指定。
- 初期検証ではWeb用を`max_instances: 1`に制限し、固定インスタンス名へルーティング。性能測定後に増やします。
- Worker → Container → Nginx → PHPの経路を作り、WebSocketも同じWorker経由で転送。
- インスタンスサイズは実測で決めます。DBを外部化したWeb用の検証候補は`standard-1`（4 GiB）。現時点では必要メモリ未測定です。
- Worker Secretsを`envVars`等で明示的にContainerへ転送。登録しただけではPHPに渡りません。

例：実装側で同名のSecretsを読むようにした後、対話入力で登録します。

```bash
npx wrangler login
npx wrangler secret put CRMEB_DB_PASSWORD
npx wrangler secret put CRMEB_REDIS_PASSWORD
npx wrangler secret put CRMEB_R2_ACCESS_KEY_ID
npx wrangler secret put CRMEB_R2_SECRET_ACCESS_KEY
```

[公式セットアップ](https://developers.cloudflare.com/containers/get-started/) / [インスタンスサイズ](https://developers.cloudflare.com/containers/platform/limits/)

### 6. バックグラウンド処理を維持する

Webアクセスがなくても注文処理が進む設計が必要です。Queue/Timer用に固定名の専用Containerを用意し、無通信時の停止方針、Durable Object alarm等による再起動監視、異常通知を実装します。HTTPリクエストが来るまで起動しない設計では未処理注文が滞留します。

再起動・再デプロイ中は旧新プロセスが重なる前提で、Redis/DBロック、ジョブの冪等性、リトライ、タイマーの実行済み管理を実装します。`max_instances: 1`だけで業務処理の一回実行を保証しません。Workermanは切断後の再接続と通知の取りこぼしを検証します。

公式仕様上、一定時間の連続稼働は保証されません。[公式FAQ](https://developers.cloudflare.com/containers/faq/)

### 7. 検証環境へデプロイする

手順2〜6の実装とローカル試験が終わってから、検証用Workerへ実行します。

```bash
npx wrangler dev
# ローカル確認後、Ctrl+Cで終了
npx wrangler deploy
npx wrangler containers list
```

Dashboardのログと検証ドメインで確認します。初回のContainer準備には数分かかる場合があります。サンプル画面の表示だけでCRMEB移行完了とはしません。

受け入れ条件：管理者/会員ログイン、商品検索、画像アップロード、カート、在庫更新、注文、決済テストと重複コールバック、キュー、タイマー、チャット、ミニプログラムAPIが動作すること。Containerを停止・再起動しても注文・画像・セッションが維持され、アクセスのない時間帯もジョブが処理されること。DB切断と再接続、再デプロイ時の重複処理も試験します。

### 8. 本番切替・復旧

1. 切替時間を決め、旧環境への新規書き込みを停止し、決済通知の受信・再送手順を確保します。
2. 旧Queue/Timerを停止し、残ジョブを移管または処理完了させます。
3. MySQLと画像の最終差分を同期して件数・整合性を確認します。
4. 本番独自ドメインをWorkerへ向け、CRMEBのサイトURL、決済通知先、ミニプログラムのAPI/WebSocket設定を更新します。
5. 最小限の本番注文で確認し、エラー率・キュー滞留・課金を監視します。
6. 問題時は書き込みを止め、移行後の注文・決済・画像を旧環境へ整合させてから戻します。DNSだけ戻すと切替後の注文が失われる可能性があります。

### Cloudflareだけで完結させたい場合

MySQL→D1、Redis→Durable Objects/KV/Queues等へ置き換える場合は、SQL/ORM、トランザクション、キャッシュ、セッション、キューの実装変更が必要です。これらはMySQL/Redisプロトコルの差し替え先ではありません。別途データモデルと業務処理を設計し直す開発案件になります。

Docker-in-Dockerは公式に対応していますが、既存Composeのネットワーク設定調整が必要で、ディスク永続化の問題も解決しません。[公式FAQ](https://developers.cloudflare.com/containers/faq/)

費用はWorkers/Containers従量料金に、外部MySQL・Redis・R2・バックアップを加えて見積もります。月額5ドルは全構成の固定料金ではありません。[料金](https://developers.cloudflare.com/containers/platform/pricing/)

## 中文版

### 范围

本文是包含开发和验证工作的迁移步骤，并非可直接执行的一键部署脚本。迁移镜像、R2适配器及后台恢复机制尚未实现，也没有执行云端部署或生产数据迁移。

Containers的本地磁盘是临时磁盘。停止后重新启动会回到镜像初始状态。因此，不能把MySQL、Redis队列和商品图片仅保存在容器内部。关闭自动休眠也不能避免平台重启造成的数据丢失。

保留现有功能的方案：Nginx/PHP/Workerman迁移至Containers；MySQL和Redis使用外部持久服务；图片迁移至R2；队列与定时任务使用专用Container并实现自动恢复。这不是仅依靠Cloudflare的完整方案。

### 执行顺序

1. **固定源码。** 使用上方Bash命令clone实际GitHub仓库，checkout包含自定义修改的已审核commit。备份数据库、附件、配置及未处理队列，并在隔离环境验证恢复。不要将密钥、证书、数据库备份和客户资料写入Git或镜像。
2. **创建外部MySQL和Redis。** 先建立测试数据库并恢复数据；验证MySQL字符集、排序规则、SQL兼容性及TLS证书。Redis需支持现有PHP客户端使用的协议、命令、认证、数据库编号和队列持久化，不能直接替换为仅支持HTTP API的服务。不要假设Containers拥有固定出口IP。
3. **创建R2存储。** 配置公开图片域名，例如`media.example.com`，私有附件单独授权。当前上传驱动目录未发现R2专用驱动，需要增加S3兼容适配器，支持上传、删除、URL、缩略图和导出。迁移文件时同步数据库中的路径。
4. **修改配置与存储。** 将Worker Secrets显式传入Container，再映射到CRMEB的`database.hostname`、`redis.redis_hostname`等配置；设置Redis缓存及`session.type=redis`。数据库PDO参数目前为空，需要补充TLS配置；Redis的缓存、队列、会话连接均需验证。临时文件生成后上传R2，日志输出到stdout/stderr。
5. **制作应用镜像。** 将源码、锁定版本的依赖和编译后的管理端/H5放入镜像。现有Compose依赖主机挂载，不能直接使用。Nginx监听8080，PHP-FPM监听本机9000；`/notice`和`/msg`分别代理本机40001和40002，并保留WebSocket Upgrade。进程管理器需要处理退出信号。使用`linux/amd64`架构，并用`.dockerignore`排除秘密文件及数据。现有PHP 7.4.33 Dockerfile仅是兼容性参考，不是完整迁移镜像。
6. **创建Cloudflare项目。** 安装Docker及Node.js/npm，启用Workers Paid。运行上方`npm create cloudflare...`命令，首次先不部署。将模板替换为CRMEB镜像，并配置Container类、Durable Object绑定和SQLite类迁移。初期固定实例名并限制Web实例数量，内存按实测选择。按上方`wrangler secret put`命令录入秘密，再由代码传给容器。
7. **迁移后台任务。** Queue/Timer必须在没有网站访问时继续处理任务。建立专用实例、恢复监视、告警、分布式锁、重试和幂等机制。部署时可能有新旧进程重叠，实例数限制不能保证业务只执行一次。Workerman需验证断线重连及消息补偿。
8. **部署测试环境。** 完成前置开发后运行`npx wrangler dev`；本地验证后执行`npx wrangler deploy`及`npx wrangler containers list`。检查后台与会员登录、图片、购物车、库存、订单、支付重复通知、队列、定时任务、聊天和小程序API。强制重启容器，确认订单、文件及会话仍保留，无访问时后台任务仍执行。
9. **切换生产。** 暂停旧站写入，妥善处理支付回调；停止旧后台任务并处理积压队列；同步数据库和图片最后差异；切换Worker域名、站点URL、支付通知地址和小程序API/WebSocket地址。小程序需单独编译、上传与审核。监控错误、队列和费用。
10. **回滚。** 先暂停写入并核对迁移后的订单、支付和图片，再恢复旧站。仅切换DNS可能遗漏新订单。

若要求所有服务都留在Cloudflare，需要把MySQL改为D1等存储、Redis相关逻辑改为Durable Objects/KV/Queues等。这需要重构ORM、事务、会话及队列，不能仅修改连接地址。Docker-in-Docker也不能解决临时磁盘问题。

费用包含Workers、Containers、外部MySQL/Redis、R2及备份，5美元不是完整系统包月价格。官方依据及链接见日文各步骤。
