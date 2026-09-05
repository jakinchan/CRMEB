# CRMEB mini PC 構築手順【公開版：Ubuntu 26.04 + Docker】

[中文版](MINIPC-SETUP.zh-CN.md) / 更新日 2026-09-05

Ubuntu 26.04 は維持し、ホストには Docker Engine と Compose を導入します。PHP・DB はコンテナ内に置くため、Ubuntu に PHP 7.4 や宝塔を直接インストールしません。Docker による分離で古い PHP の依存環境を用意できますが、PHP のサポート終了は解消しません。まず検証環境として構築し、本番公開前に保守方針を決めます。

公式資料：[Docker の Ubuntu 対応・導入](https://docs.docker.com/engine/install/ubuntu/) / [CRMEB v5.6 要件](https://doc.crmeb.com/single/v56/20415)

`192.168.1.x`、`SSH_USER`、`GITHUB_OWNER` は公開用プレースホルダーで、実行前に置換します。127.0.0.1 は共通のループバック、127.0.0.11 は Docker 内部 DNS のため変更しません。ソフトの版とポート番号も匿名化対象ではありません。

## 1. ソフトウェア一覧

| ソフト | この構成 | 配置先 |
| --- | --- | --- |
| Ubuntu | 26.04 LTS を維持 | mini PC |
| Docker Engine / Compose plugin | Docker 公式 stable リポジトリ提供版 | ホスト |
| Git / OpenSSH / curl / OpenSSL | Ubuntu 提供版 | ホスト |
| PHP-FPM / CLI | 7.4.33、php:7.4.33-fpm-bullseye からビルド | コンテナ |
| PHP Redis 拡張 | 5.3.7 | PHP コンテナ |
| Nginx | nginx:1.28 | コンテナ |
| MySQL | mysql:8.0 | コンテナ＋永続ボリューム |
| Redis | redis:7.2、AOF 有効 | コンテナ＋永続ボリューム |
| Queue / Workerman / Timer | 同じ PHP イメージで別サービス | コンテナ |
| 宝塔 / Supervisor | 不要。再起動は Docker が管理 | — |
| Node.js / npm | node:22-bookworm と同梱 npm | 一時ビルドコンテナ |
| Composer | 2.2 系 | PHP コンテナ |

上記は構築候補で、全イメージの取得・ビルド・CRMEB 実機動作は未検証です。タグは更新されるため、検証後に実バージョンとイメージ digest を記録・固定します。PHP ベース OS も含めて保守状況を管理します。指定 CRMEB 資料は v5.6、確認した参考ソースは v6.0.0 です。clone 後に対象版を確認してください。

## 2. Ubuntu と Docker の準備

Ubuntu 本体で実行します。既存 Docker / Podman がある場合は、同梱スクリプトを実行する前に公式手順の競合パッケージ確認を行います。既存環境を自動削除しません。

```bash
cat /etc/os-release
uname -m
sudo apt update
sudo apt install openssh-server unzip
sudo systemctl enable --now ssh
hostname -I
```

公開用 ZIP を Ubuntu の `~/crmeb-deploy` に展開します。同じディレクトリ直下に compose.yaml、Dockerfile、nginx.conf、install-block.conf、install-docker.sh、build-source.sh、.dockerignore を配置します。まだ設定ファイルが GitHub にない場合も ZIP から配置できます。

```bash
cd ~/crmeb-deploy
less install-docker.sh
bash install-docker.sh
sudo docker version
sudo docker compose version
```

amd64 を主な対象とします。他の CPU は選択した全イメージの対応アーキテクチャを確認します。OS が Docker 対応でも各イメージが対応するとは限りません。

### Docker インストールコマンド（直接実行する場合）

install-docker.sh とどちらか一方を使います。Ubuntu 26.04 のみの環境が対象です。既存コンテナ環境がある場合は先に公式資料の競合確認を行います。

```bash
. /etc/os-release
sudo apt-get update
sudo apt-get install -y ca-certificates curl git openssl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker run --rm hello-world
sudo docker compose version

```

## 3. GitHub clone と初期設定

```bash
cd ~/crmeb-deploy
git clone https://github.com/GITHUB_OWNER/CRMEB.git source
git -C source log -1 --format='%H %s'
grep '^version=' source/crmeb/.version
test -f source/crmeb/composer.lock
test -f source/template/admin/package-lock.json
test -f source/crmeb/public/install/.env
```

GITHUB_OWNER を実際の信頼できる所有者に置換します。ソースやロックファイルが欠けていれば対象バージョンを確認してから進みます。既存の source を削除・上書きしません。公開 ZIP はソース本体を含みません。

新規設置だけに対し、ランダムなパスワードを生成します。既存店舗の移行は初期インストールをせず DB・アップロード・認証設定を復元します。

```bash
# 既存 .env があれば停止。Compose 用と CRMEB 用の .env は別ファイル
( set -C; umask 077
  printf 'DB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\nREDIS_PASSWORD=%s\n' \
    "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" > .env
)
# 上が失敗した場合は既存の設定を確認し、再生成しない
mkdir -p source/crmeb/runtime source/crmeb/backup source/crmeb/public/uploads
touch source/crmeb/.env source/crmeb/.constant
sudo docker compose config --quiet
bash build-source.sh
sudo docker compose run --rm --no-deps --user root php sh -c \
  'chown -R www-data:www-data runtime backup public && chown www-data:www-data .env .constant .version && chmod 640 .env .constant'
sudo docker compose run --rm --no-deps php php -m
sudo docker compose up -d mysql redis php nginx
sudo docker compose ps
```

PHP は curl、openssl、fileinfo、SimpleXML、DOM、posix 等のベース拡張に加え、Dockerfile で bcmath、mbstring、mysqli、pdo_mysql、gd、zip、pcntl、sockets、opcache、redis を追加します。必要な拡張が確認できなければインストールへ進みません。パスワードを含む .env は Git や公開 ZIP に追加しないでください。

### ソースビルドの範囲

同梱 build-source.sh は、PHP 実行イメージを作成し、composer.lock に従って依存関係を導入・検証し、template/admin を npm ci → npm run build でビルドして crmeb/public/admin へ配置します。PHP 自体のアプリソースは解釈実行されます。WeChat ミニプログラムのビルド・審査はこの処理に含みません。

Node.js は package.json の範囲（>14、<23）に合わせて 22 系を使います。旧 Webpack 用の --openssl-legacy-provider はビルドコンテナ内だけに設定します。npm ci が失敗したらロックファイルの整合性を確認し、npm install への自動切替やロック削除は行いません。Composer は DB 初期化前のスクリプト実行を避けて依存導入後に service:discover のみを実行します。対象版で追加の vendor:publish 等が必要な場合は配布元の手順に従います。

既存サービスが動作中ならスクリプトは停止します。初回専用を基本とし、更新時は先にバックアップ・サービス停止・ソース書込み権限の確認を行います。旧管理画面は build-backups に退避します。完了メッセージはビルド成功を意味し、業務機能の検証は第 5 章で行います。

構成ファイルも GitHub から取得したい場合は、公開 ZIP の 9 ファイルをリポジトリの docs/public にコミット・公開した後、clone 済み source から必要なファイルを ~/crmeb-deploy 直下へコピーできます。本作業では GitHub への push は行っていません。

```bash
cp source/docs/public/{compose.yaml,Dockerfile,nginx.conf,install-block.conf,install-docker.sh,build-source.sh,.dockerignore} .
```
## 4. ブラウザーで初期インストール

Nginx は初期状態で **mini PC の 127.0.0.1:8080 のみ**で待ち受けます。Windows PowerShell から SSH 転送を開始し、このウィンドウを開いたままブラウザーを利用します。

```powershell
ssh -L 8080:127.0.0.1:8080 SSH_USER@192.168.1.x
```

Windows ブラウザーで `http://localhost:8080/install/` を開きます。

| 設定 | 入力値 |
| --- | --- |
| DB ホスト / ポート | mysql / 3306 |
| DB 名 / ユーザー | crmeb / crmeb |
| DB パスワード | 配置先 .env の DB_PASSWORD |
| Redis ホスト / ポート | redis / 6379 |
| Redis パスワード / DB 番号 | .env の REDIS_PASSWORD / 0 |
| キャッシュ | Redis |

**コンテナ間接続に 127.0.0.1 を入力しません。** mysql と redis は Compose のサービス名です。ルート DB パスワードは CRMEB に使用しません。管理者を作成して最後まで完了し、/admin/ へログインします。

```bash
# Ubuntu: 設置完了後
sudo test -f source/crmeb/public/install.lock
sudo chmod 640 source/crmeb/.env source/crmeb/.constant
printf 'location ^~ /install/ { return 403; }\nlocation = /install { return 403; }\n' > install-block.conf
sudo docker compose exec nginx nginx -t
sudo docker compose exec nginx nginx -s reload
sudo docker compose --profile jobs up -d
```

source/crmeb/config/workerman.php の admin/chat 待受はコンテナ内の 0.0.0.0 を維持します。Nginx は別コンテナのため、ここを 127.0.0.1 に変更しません。内部 channel は同一 Workerman コンテナ内の 127.0.0.1 を維持します。ホストへ 40001～40003、3306、6379、9000 を公開しません。

## 5. 完了確認

```bash
sudo docker compose --profile jobs ps
sudo docker compose logs --tail=80 php nginx mysql redis
sudo docker compose --profile jobs logs --tail=80 queue workerman timer
sudo docker compose exec nginx nginx -t
sudo docker compose exec php php -v
sudo docker compose exec mysql mysql --version
sudo docker compose exec redis redis-server --version
curl -I http://127.0.0.1:8080/install/
```

商品表示、管理者ログイン、商品作成、画像アップロード、テスト注文、チャットと通知を確認します。インストール入口は 403、WebSocket は 101 を確認します。jobs 起動前のチャット接続失敗は起動後に再確認します。mini PC 再起動後もサービスが復帰することを確認します。ログには個人情報が含まれる可能性があるため、そのまま公開しません。

## 6. HTTPS 公開

この Compose は SSH 経由の検証用入口までを用意しています。インターネット公開にはホスト側の HTTPS リバースプロキシまたはトンネルを別途設定し、上流を 127.0.0.1:8080 とします。WebSocket の Upgrade と元の Host / HTTPS 情報を渡し、CRMEB 側で信頼するプロキシ・サイト URL の扱いを検証します。公開 URL は https://shop.example.com など自身のドメインに変更します。公開前に localhost の画像・API URL が残っていないか確認します。

Docker の公開ポートは UFW の規則を迂回する場合があります。LAN 全体で使うために 0.0.0.0:8080 に変更する場合も、UFW だけで限定できるとは判断しません。現在の localhost 限定を維持し HTTPS 入口で制御する構成を基本とします。WeChat の合法ドメイン・審査・実機検証は別途必要です。

## 7. バックアップ・更新・復元

DB は mysql-data、Redis は redis-data の名前付きボリュームに永続化します。画像と設定は source/crmeb にあります。コンテナ再作成だけではこれらは消えませんが、**docker compose down -v と volume prune はデータを失うため使用しません**。配置ディレクトリも保存します。

```bash
# Ubuntu: 注文・アップロード等の書込みを停止してから、整合したバックアップを取得
sudo docker compose stop nginx
sudo docker compose --profile jobs stop queue workerman timer php
mkdir -p backups
chmod 700 backups
stamp=$(date +%Y%m%d-%H%M%S)
# MySQL のパスワードはコンテナ内で参照。正常終了を確認
sudo docker compose exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --no-tablespaces --routines --triggers crmeb' > "backups/$stamp.sql"
sudo tar -czf "backups/$stamp-files.tar.gz" source/crmeb .env compose.yaml Dockerfile nginx.conf install-block.conf
chmod 600 "backups/$stamp.sql"
sudo chmod 600 "backups/$stamp-files.tar.gz"
# Redis AOF/RDB: stop Redis before copying its volume
sudo docker compose stop redis
sudo docker compose run --rm --no-deps --entrypoint tar redis -czf - -C /data . > "backups/$stamp-redis.tar.gz"
chmod 600 "backups/$stamp-redis.tar.gz"
sudo docker compose --profile jobs up -d
```

いずれかが失敗したら有効なバックアップと扱わず原因を確認します。別媒体へ暗号化保管し、隔離した別環境で同じコミット・構成と空 DB を用意し、SQL を投入、ファイルと設定を復元して注文・画像まで確認します。元環境で復元テストしません。復元先では DB 接続情報を合わせ、Redis 未処理ジョブの重複実行を防ぎます。

更新はコミットとイメージ digest を記録し、検証環境で試してから行います。稼働中の bind mount に git pull すると即反映されるため、サービス停止・バックアップ後に選択したコミットを反映します。DB 変更を伴う場合、コードだけ戻すロールバックは行いません。

## 8. 検証範囲

資料・構成ファイルを作成し、Compose の構文をローカル確認します。mini PC への接続、イメージ取得・ビルド、インストーラー、業務機能、HTTPS、復元は未実施です。古い PHP とベースイメージの取得失敗時に TLS 検証を無効化したり、ホスト Ubuntu のリポジトリを旧版に混在させたりしないでください。
