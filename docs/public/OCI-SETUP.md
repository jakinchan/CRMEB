# OCI 上で CRMEB を構築する手順（Ubuntu + Docker）

更新日: 2026-09-05

この手順は、Oracle Cloud Infrastructure（OCI）の Compute インスタンスに、GitHub の CRMEB ソースを clone し、Docker Compose で構築する公開用手順です。OCI コンソールの機密情報、個別の IP、OCID、SSH 秘密鍵、パスワードは記載しません。`GITHUB_OWNER`、`SSH_USER`、`oci-instance-ip`、`shop.example.com` は利用者の値に置き換えます。

## 1. OCI の推奨構成

| 用途 | シェイプ | CPU / メモリ | 料金の目安 |
|---|---|---:|---:|
| 検証・小規模 | `VM.Standard.A1.Flex` | 2 OCPU / 12GB | Always Free 枠内なら無料 |
| 互換性重視 | `VM.Standard.E4.Flex` | 2 OCPU / 8GB | 有料。従量課金 |
| 本番推奨 | `VM.Standard.E4.Flex` | 4 OCPU / 16GB | 有料。従量課金 |

A1 は ARM64 のため、PHP・PECL・Node.js・Composer と全コンテナイメージの ARM64 対応を確認します。確認できない場合は x86 系の E4 を選びます。Always Free はホーム・リージョン、コンピュート枠、ブロック・ボリューム枠などの制限を受けます。[OCI Always Free](https://docs.oracle.com/ja-jp/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)

OCI のシェイプ単価は変更されるため、作成画面の見積りを最終確認します。[OCI Compute 料金](https://www.oracle.com/cloud/iaas-paas/)

## 2. OCI コンソールでインスタンスを作る

1. OCI コンソールでリージョンを選択する（東京 `ap-tokyo-1` または大阪 `ap-osaka-1`）。
2. Compute → Instances → Create instance を開く。
3. イメージは Ubuntu 24.04 LTS または OCI が提供する対象 Ubuntu を選ぶ。既存の Ubuntu 26.04 を使う場合は Docker と各イメージの対応を確認する。
4. シェイプは A1 Flex 2 OCPU / 12GB（ARM64）または E4 Flex 4 OCPU / 16GB（x86）を選択する。
5. SSH 公開鍵を登録する。秘密鍵を Web 画面や GitHub にアップロードしない。
6. パブリック IPv4 を割り当てる。Always Free を利用する場合、固定 IP や予約 IP の課金条件を確認する。
7. ブート・ボリュームは 100GB 以上を目安にする。CRMEB の MySQL、画像、バックアップの容量を見積もる。
8. インスタンスを作成し、パブリック IP と OS ユーザーを控える。

## 3. OCI のネットワーク・ファイアウォール

OCI の VCN セキュリティ・リストまたは Network Security Group で、次だけを許可します。

| ポート | 接続元 | 用途 |
|---:|---|---|
| 22/tcp | 管理元の固定 IP または VPN | SSH |
| 80/tcp | `0.0.0.0/0`（HTTP リダイレクト・証明書検証時のみ） | HTTP |
| 443/tcp | `0.0.0.0/0` | HTTPS |

3306、6379、40001、40002、40003、8080、9000 は開放しません。OCI の受信ルールと Ubuntu の UFW を両方使う場合、SSH を許可してから設定し、Docker の公開ポートが UFW を迂回しないよう Compose のポートを localhost に限定します。

SSH 接続（Windows PowerShell）：

```powershell
ssh -i C:\path\to\oci_key SSH_USER@oci-instance-ip
```

## 4. Ubuntu の初期設定

OCI インスタンス内で実行します。

```bash
cat /etc/os-release
uname -m
sudo apt update
sudo apt full-upgrade -y
sudo apt install -y ca-certificates curl git openssl unzip ufw
sudo systemctl enable --now ssh
```

A1 では `uname -m` が `aarch64`、E4 では通常 `x86_64` です。選択したシェイプと Docker イメージのアーキテクチャが一致することを確認します。

## 5. Docker Engine と Compose を公式リポジトリから導入

Docker 公式は Ubuntu 24.04、26.04 と amd64 / arm64 に対応しています。[Docker 公式 Ubuntu 手順](https://docs.docker.com/engine/install/ubuntu/)

```bash
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
. /etc/os-release
sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker run --rm hello-world
sudo docker compose version
```

Docker 公式のリポジトリが対象 Ubuntu の Suite を提供していない場合、Suite を推測して置換せず、対応 OS または公式のパッケージ方式を確認します。

## 6. GitHub からソースを clone

```bash
mkdir -p ~/crmeb-deploy
cd ~/crmeb-deploy
git clone https://github.com/GITHUB_OWNER/CRMEB.git source
cd source
git log -1 --format='%H %s'
grep '^version=' crmeb/.version
test -f crmeb/composer.json
test -f crmeb/composer.lock
test -f template/admin/package.json
test -f template/admin/package-lock.json
cd ..
```

`GITHUB_OWNER` は対象リポジトリの所有者に置き換えます。非公開リポジトリは SSH 認証などを使い、トークンを URL に埋め込みません。clone 後、対象バージョンとコミット SHA を記録します。

公開用 ZIP に含まれる構成ファイルを使用する場合は、同じ `~/crmeb-deploy` に配置します。公開 ZIP は CRMEB ソース本体を含みません。

## 7. 構成ファイルを置いてビルドする

公開 ZIP または GitHub の `docs/public` から次のファイルを `~/crmeb-deploy` 直下へコピーします。

```text
compose.yaml
Dockerfile
nginx.conf
install-block.conf
install-docker.sh
build-source.sh
.dockerignore
```

GitHub 側に構成ファイルがある場合：

```bash
cd ~/crmeb-deploy
cp source/docs/public/{compose.yaml,Dockerfile,nginx.conf,install-block.conf,install-docker.sh,build-source.sh,.dockerignore} .
```

新規サイト用の Compose 認証情報を生成します。既存 `.env` がある場合は上書きしません。

```bash
cd ~/crmeb-deploy
( set -C; umask 077
  printf 'DB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\nREDIS_PASSWORD=%s\n' \
    "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" > .env
)
mkdir -p source/crmeb/runtime source/crmeb/backup source/crmeb/public/uploads
sudo docker compose config --quiet
bash build-source.sh
```

`build-source.sh` は PHP 7.4.33 イメージを作り、Composer 依存を lock ファイルから導入・検証し、Node.js 22 で管理画面をビルドします。失敗時は `npm install` へ自動切替せず、ロックファイル・対象コミット・イメージアーキテクチャを確認します。

## 8. CRMEB を起動する

```bash
sudo docker compose up -d mysql redis php nginx
sudo docker compose ps
sudo docker compose logs --tail=80 mysql redis php nginx
```

初期インストールは SSH ローカル転送を使います。

```powershell
ssh -L 8080:127.0.0.1:8080 SSH_USER@oci-instance-ip
```

Windows のブラウザーで `http://localhost:8080/install/` を開き、次を入力します。

```text
DB ホスト: mysql
DB ポート: 3306
DB 名: crmeb
DB ユーザー: crmeb
DB パスワード: .env の DB_PASSWORD
Redis ホスト: redis
Redis ポート: 6379
Redis パスワード: .env の REDIS_PASSWORD
Redis DB: 0
```

コンテナ間の接続先に `127.0.0.1` は入力しません。インストール完了後、`public/install.lock` の生成を確認します。

```bash
sudo test -f source/crmeb/public/install.lock
printf 'location ^~ /install/ { return 403; }\nlocation = /install { return 403; }\n' > install-block.conf
sudo docker compose exec nginx nginx -t
sudo docker compose exec nginx nginx -s reload
sudo docker compose --profile jobs up -d
sudo docker compose --profile jobs ps
```

## 9. HTTPS を公開する

1. OCI の DNS または Cloudflare DNS にドメインを設定する。
2. OCI の 80/443 セキュリティ・ルールを設定する。
3. OCI インスタンス上の HTTPS リバースプロキシまたは Cloudflare Tunnel を構成し、上流を `127.0.0.1:8080` にする。
4. WebSocket の Upgrade を `/notice` と `/msg` へ転送する。
5. 証明書が有効になってから HTTP→HTTPS を有効にする。
6. 外部モバイル回線から商品、画像、管理画面通知を確認する。

Cloudflare Tunnel を使う場合は、OCI への受信 80/443 を閉じ、`cloudflared` から `http://127.0.0.1:8080` へ接続します。Cloudflare 側の設定は本書の OCI 構築とは別管理です。

## 10. 起動確認・更新・バックアップ

```bash
sudo docker compose --profile jobs ps
sudo docker compose exec php php -v
sudo docker compose exec mysql mysql --version
sudo docker compose exec redis redis-server --version
curl -I http://127.0.0.1:8080/
curl -I http://127.0.0.1:8080/install/
```

商品表示、管理画面ログイン、商品登録、画像アップロード、テスト注文、通知、チャットを確認します。`/install/` は 403、WebSocket は 101 を確認します。

MySQL と Redis の名前付きボリューム、`source/crmeb` の画像・設定、`backups` ディレクトリを別リージョンまたは別媒体へ暗号化して保存します。`docker compose down -v` と `docker volume prune` はデータを削除するため使用しません。

更新は次の順序にします。

1. DB・画像・設定をバックアップする。
2. 対象 Git コミットと Docker イメージの digest を記録する。
3. サービスを停止してから検証済みソースを配置する。
4. `bash build-source.sh` と `docker compose config --quiet` を実行する。
5. 起動後に業務機能とログを確認する。

## 11. 運用上の制限

OCI Free Tier、クレジット、ブロック・ボリューム、パブリック IP、通信量には条件があります。Always Free の空きシェイプが取得できない場合があります。PHP 7.4 は CRMEB の互換性のために使用しますが、公式サポート終了済みです。インターネット公開前にアクセス制御、更新方針、監視、復元テストを決めてください。

本手順は OCI コンソール、Docker 構成、GitHub clone、ビルド手順を整理したものです。OCI アカウント作成、シェイプ取得、イメージ pull、CRMEB の実機動作、HTTPS、WeChat 実機試験は未実施です。
