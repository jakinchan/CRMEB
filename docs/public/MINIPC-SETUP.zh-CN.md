# CRMEB mini PC 部署手册【公开版：Ubuntu 26.04 + Docker】

[日本語版](MINIPC-SETUP.md) / 更新日期：2026-09-05

保留 Ubuntu 26.04，主机只安装 Docker Engine 和 Compose。PHP、数据库运行在容器内，不需要在 Ubuntu 直接安装 PHP 7.4 或宝塔。Docker 可以隔离旧 PHP 的用户空间依赖，但不能解决 PHP 已停止支持的问题。先完成测试部署，再确定生产维护方案。

官方资料：[Docker Ubuntu 安装与支持范围](https://docs.docker.com/engine/install/ubuntu/) / [CRMEB v5.6 环境要求](https://doc.crmeb.com/single/v56/20415)

192.168.1.x、SSH_USER、GITHUB_OWNER 是公开占位符，执行前替换。127.0.0.1 为回环地址，127.0.0.11 为 Docker 内部 DNS，保持不变。端口号与版本号不是脱敏对象。

## 1. 软件版本

| 软件 | 本配置 | 位置 |
| --- | --- | --- |
| Ubuntu | 保留 26.04 LTS | mini PC |
| Docker Engine / Compose plugin | Docker 官方 stable 源提供版 | 主机 |
| Git / OpenSSH / curl / OpenSSL | Ubuntu 提供版 | 主机 |
| PHP-FPM / CLI | 7.4.33，从 php:7.4.33-fpm-bullseye 构建 | 容器 |
| PHP Redis 扩展 | 5.3.7 | PHP 容器 |
| Nginx | nginx:1.28 | 容器 |
| MySQL | mysql:8.0 | 容器与持久卷 |
| Redis | redis:7.2，开启 AOF | 容器与持久卷 |
| 队列 / Workerman / Timer | 相同 PHP 镜像，各自独立服务 | 容器 |
| 宝塔 / Supervisor | 不需要，使用 Docker 重启策略 | — |
| Node.js / npm | node:22-bookworm 及镜像内 npm | 临时构建容器 |
| Composer | 2.2 系列 | PHP 容器 |

这是待验证的构建组合，尚未完成镜像拉取、构建与 CRMEB 实机测试。标签可能更新，验证后记录并固定实际版本与镜像 digest。旧 PHP 基础系统也需维护。参考文档是 v5.6，检查的样例源码为 v6.0.0，clone 后必须确认目标版本。

## 2. 安装 Docker

在 Ubuntu mini PC 本机执行：

```bash
cat /etc/os-release
uname -m
sudo apt update
sudo apt install openssh-server unzip
sudo systemctl enable --now ssh
hostname -I
```

将公开 ZIP 解压到 ~/crmeb-deploy，确保 compose.yaml、Dockerfile、nginx.conf、install-block.conf、install-docker.sh、build-source.sh、.dockerignore 位于该目录下。配置尚未上传 GitHub 时，也可先从 ZIP 获取。

```bash
cd ~/crmeb-deploy
less install-docker.sh
bash install-docker.sh
sudo docker version
sudo docker compose version
```

脚本针对仅安装 Ubuntu 26.04 的环境。若已有 Docker / Podman，先按官方说明检查冲突包，不自动删除已有服务或数据。主要面向 amd64；其他 CPU 需确认全部镜像架构，不能只看 Docker 对 OS 的支持。

### Docker 安装命令（直接执行版）

与 install-docker.sh 二选一；在仅有 Ubuntu 26.04 的主机执行。若已有容器环境，先检查官方说明中的包冲突。

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

## 3. GitHub clone 与初始化

```bash
cd ~/crmeb-deploy
git clone https://github.com/GITHUB_OWNER/CRMEB.git source
git -C source log -1 --format='%H %s'
grep '^version=' source/crmeb/.version
test -f source/crmeb/composer.lock
test -f source/template/admin/package-lock.json
test -f source/crmeb/public/install/.env
```

将 GITHUB_OWNER 改为可信仓库的实际所有者。缺少源码或锁文件时先确认目标版本；不要覆盖已有 source。公开 ZIP 不包含 CRMEB 源码本体。

以下仅用于全新安装。迁移旧商城时不运行新安装向导，应恢复数据库、上传文件与原认证配置。

```bash
# 已有 .env 时拒绝覆盖。这里是 Compose 配置，不是 CRMEB 配置
( set -C; umask 077
  printf 'DB_PASSWORD=%s\nDB_ROOT_PASSWORD=%s\nREDIS_PASSWORD=%s\n' \
    "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" > .env
)
# 如果失败，先检查已有配置，不重新生成
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

基础 PHP 包含 curl、openssl、fileinfo、SimpleXML、DOM、posix 等扩展；Dockerfile 添加 bcmath、mbstring、mysqli、pdo_mysql、gd、zip、pcntl、sockets、opcache、redis。检查缺失扩展后再安装应用。含密码的 .env 不上传 Git，也不放入公开 ZIP。

### 源码构建范围

build-source.sh 构建 PHP 运行镜像，按 composer.lock 安装并检查依赖，再对 template/admin 执行 npm ci 和 npm run build，将 dist 部署到 crmeb/public/admin。PHP 应用代码是解释执行的。微信小程序的打包、上传和审核不包含在此脚本中。

Node.js 22 符合当前 package.json 的 >14、<23 要求。旧 Webpack 所需 --openssl-legacy-provider 仅设置在构建容器。npm ci 失败时检查锁文件，不自动改用 npm install 或删除锁。Composer 安装先跳过脚本，随后仅执行 service:discover；目标版本若需要 vendor:publish 等额外步骤，按其发行说明执行。

有运行中的服务时脚本会停止。主要用于首次构建；更新前先备份、停服务并确认源码写权限。旧后台目录保存在 build-backups。构建完成不等于业务验收通过，继续第 5 节检查。

若配置文件也要从 GitHub 获取，先将公开 ZIP 的 9 个文件提交到仓库 docs/public，再从 clone 后的 source 复制到部署目录。本次没有向 GitHub push。

```bash
cp source/docs/public/{compose.yaml,Dockerfile,nginx.conf,install-block.conf,install-docker.sh,build-source.sh,.dockerignore} .
```
## 4. 浏览器安装

默认仅发布 mini PC 的 127.0.0.1:8080。Windows PowerShell 执行 SSH 转发，并保持窗口开启：

```powershell
ssh -L 8080:127.0.0.1:8080 SSH_USER@192.168.1.x
```

Windows 浏览器打开 http://localhost:8080/install/。

| 设置 | 值 |
| --- | --- |
| DB 主机 / 端口 | mysql / 3306 |
| DB 名 / 用户 | crmeb / crmeb |
| DB 密码 | 部署目录 .env 中 DB_PASSWORD |
| Redis 主机 / 端口 | redis / 6379 |
| Redis 密码 / 编号 | .env 中 REDIS_PASSWORD / 0 |
| 缓存 | Redis |

容器之间连接使用 mysql、redis 服务名，**不是 127.0.0.1**。CRMEB 不使用 DB root 密码。完成全部安装步骤，创建管理员并登录 /admin/。

```bash
# Ubuntu：安装完成后执行
sudo test -f source/crmeb/public/install.lock
sudo chmod 640 source/crmeb/.env source/crmeb/.constant
printf 'location ^~ /install/ { return 403; }\nlocation = /install { return 403; }\n' > install-block.conf
sudo docker compose exec nginx nginx -t
sudo docker compose exec nginx nginx -s reload
sudo docker compose --profile jobs up -d
```

source/crmeb/config/workerman.php 中 admin/chat 保持容器内 0.0.0.0 监听，Nginx 在另一容器，不改为 127.0.0.1。内部 channel 在同一 Workerman 容器内，保持 127.0.0.1。主机不发布 40001～40003、3306、6379、9000。

## 5. 验收

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

检查商品页面、后台登录、新增商品、图片上传、测试订单、聊天与通知。安装入口应返回 403，WebSocket 成功握手应为 101。jobs 启动前的聊天失败需在启动后重试。重启 mini PC 后确认所有服务恢复。日志可能含个人资料，不直接公开。

## 6. HTTPS 公开访问

当前配置只提供 SSH 转发的测试入口。外网访问需另配主机 HTTPS 反向代理或隧道，上游为 127.0.0.1:8080，正确转发 WebSocket Upgrade、原始 Host 和 HTTPS 信息，验证 CRMEB 可信代理与站点 URL 处理。将站点改为自己的 https://shop.example.com，检查图片、API 不残留 localhost 地址。

Docker 发布端口可能绕过 UFW。不要认为改成 0.0.0.0:8080 后仅靠 UFW 即能限定来源。默认保持本机绑定，通过 HTTPS 入口控制访问。微信合法域名、审核与真机验证另行完成。

## 7. 备份、恢复与更新

mysql-data 和 redis-data 为持久卷；图片和配置在 source/crmeb。普通重建容器不删除这些数据。**不要运行 docker compose down -v 或 volume prune**，它们可能删除数据库。部署目录同样需要保存。

```bash
# 先停止订单、上传等写入，再取得一致备份
sudo docker compose stop nginx
sudo docker compose --profile jobs stop queue workerman timer php
mkdir -p backups
chmod 700 backups
stamp=$(date +%Y%m%d-%H%M%S)
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

失败时先排查，不将失败文件视为有效备份。加密复制至独立设备。定期在隔离的新环境准备相同提交、配置和空数据库，导入 SQL，恢复文件及配置并验证订单与图片；不在原站测试恢复。对齐目标数据库连接，防止 Redis 剩余任务重复执行。

更新前记录提交与镜像 digest、备份并在测试环境验证。运行时对 bind mount 源码 git pull 会立即生效，应停止服务后再切换已验证提交。涉及数据库结构变化时，不能仅退回代码。

## 8. 验证范围

本次编写文档与配置，并在本地检查 Compose 语法。mini PC SSH、镜像拉取和构建、安装向导、业务功能、HTTPS 与恢复均未执行。旧基础镜像获取失败时，不禁用 TLS 检查，也不将旧 Ubuntu 软件源混入当前主机。
