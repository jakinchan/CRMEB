# ミニPC本番リリース設計（Cloudflare連携 + 自動ビルド）

自宅PC（[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md)で使用したテスト環境）を、
WeChatミニプログラム込みの中国向けECの**本番運用**に転用するための設計です。
「早く動かす」ことを優先し、自動ビルド・自動デプロイのパイプラインを構築します。

## 0. 前提・スコープ

- 対象マシン: 既存の自宅PC（新規ハードウェア調達なし）
- 対象製品: WeChatミニプログラム込みの中国向けEC
- **WeChatミニプログラムは今回、体験版/開発者モードでの運用を前提とします。**
  正式版（一般公開・審査通過）にはICP備案済みドメインが必須で、自宅PC + Cloudflareの構成では
  到達できません（詳細は[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md)の
  「0. 最初に把握しておくべき制約」を参照）。正式リリースは本設計のスコープ外です。
- 自動ビルドの対象は次の3つ：サーバー側(PHP)コードのデプロイ、admin フロントエンドのビルド、
  WeChatミニプログラムのビルド。ただしミニプログラムのビルド自動化はHBuilderX（GUI専用IDE）が
  前提で技術的不確実性が高いため、**フェーズ2として今回のスコープ外**とします（4章参照）。

## 1. 全体アーキテクチャ

```
GitHub (future_dev で開発 → master へ merge)
   │ push/merge to master
   ▼
GitHub Actions（クラウド側でワークフロー定義、ジョブは self-hosted runner に割り当て）
   ▼
自宅PC上の Self-hosted Runner
   ├─ git pull（リポジトリの working copy がそのまま本番配信ディレクトリ）
   ├─ admin フロントエンド build（template/admin → crmeb/public/admin）
   ├─ workerman / queue の再起動（bind mount のためファイルは即反映）
   └─ スモークテスト（トップページ・API・管理画面への疎通確認）
   ▼
Cloudflare Tunnel（Windows サービス化・常駐）
   │ HTTPS
   ▼
エンドユーザー / WeChat 開発者ツール
```

**設計方針**: self-hosted runner は自宅PCからGitHubへのアウトバウンド接続のみで動作し、
Cloudflare Tunnel も同様にアウトバウンド接続方式です。これにより**自宅PC側の受信ポート開放を
一切増やさずに**CI/CDと公開を両立します。

## 2. CI/CDパイプラインの詳細

`.github/workflows/deploy.yml`（クラウド側で定義、実行は self-hosted runner）

```yaml
on:
  push:
    branches: [master]
jobs:
  deploy:
    runs-on: self-hosted
    steps:
      - uses: actions/checkout@v4   # runnerのワークスペース = 本番配信ディレクトリ
      - name: admin フロントエンドをビルド
        run: |
          cd template/admin
          npm ci
          npm run build
          # dist を crmeb/public/admin へ配置
      - name: サーバー側の反映
        run: |
          docker exec crmeb_php php think workerman restart
          docker exec crmeb_php php think queue:restart
      - name: スモークテスト
        run: |
          curl -f https://<本番ドメイン>/
          curl -f https://<本番ドメイン>/api/get_lang_type_list
          curl -f https://<本番ドメイン>/admin/
```

**設計上のポイント**

- self-hosted runnerのワークスペース自体を配信ディレクトリにすることで、
  「デプロイ」＝「git checkoutが完了した状態」になり、rsyncやSCPが不要。
  `help/docker/docker-compose.yml`のbind-mount構成（`../../crmeb:/var/www`）と噛み合っています。
- `crmeb/vendor/` はリポジトリにコミット済みのため `composer install` 不要（デプロイ高速化）。
- admin フロントエンドのビルド成果物（`crmeb/public/admin`）は**都度ビルドし直す**方式にし、
  gitにはコミットしません（`.gitignore` に追加）。ビルドのソースは `template/admin` のみを正とします。
- PHP/nginx/mysql/redisの各コンテナは**再作成しません**（`docker compose restart`すら行いません）。
  bind mountによりファイルは即反映され、workermanとqueueだけ再起動すれば十分です。
  コンテナ再作成はDBデータ消失リスクがあるため、通常運用フローから意図的に除外します
  （[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md) 1-0章の教訓）。
- デプロイトリガーは`master`へのmergeとします（`future_dev`で開発し、安定した変更のみ
  `master`にmergeした時点で本番反映）。

## 3. Cloudflareの本番化

テスト時（開発モード前提）の設定から、以下を本番向けに変更します。

| 項目 | テスト時 | 本番運用への変更 |
| --- | --- | --- |
| cloudflaredの常駐 | 手動起動 | `cloudflared service install` でWindowsサービス化。PC再起動後も自動復帰 |
| Development Mode | ON | **OFF**（キャッシュが効くようにする） |
| Cloudflare Access | 未設定 | `/admin/*` と `/install/*` にアクセスポリシーを追加（メール認証 or ワンタイムPIN、無料枠で利用可） |
| Caching Level | デフォルト | 静的アセット（画像・CSS・JS）にキャッシュルールを設定し帯域を節約 |
| WAF | 未設定 | 基本的なマネージドルールを有効化（無料枠でも一部利用可） |

Cloudflare Accessは、[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md)の
「トンネルURLはインターネットから到達可能」という既知の注意点に対する本番向けの追加対策です。
管理画面自体のログインとは別レイヤーで、認証されていない人がログイン画面にすら到達できないように
します。

## 4. WeChatミニプログラムのビルド自動化（フェーズ2・今回のスコープ外）

- `template/uni-app/package.json` は空で、CLIビルドの構成が現状ありません。
- HBuilderXには公式のコマンドライン自動ビルド機能がありますが、GUIアプリケーションのため、
  対話セッションを持たないWindowsサービスとしてのself-hosted runnerから呼び出せるかは**未検証**です。
  動かない場合、runnerを「サービスではなく通常ログインユーザーのタスク」として動かす必要が
  出る可能性があります。

| フェーズ | 内容 | 自動化の確度 |
| --- | --- | --- |
| フェーズ1（本設計のスコープ） | サーバー側コード + admin フロントエンドの自動デプロイ | 高（標準的なnpm/dockerコマンドのみ） |
| フェーズ2（別タスク・後日） | HBuilderX CLIでのミニプログラム自動ビルド | 中〜低（要検証。難しければ「ビルドは手動、体験版アップロードのみ自動化」等に縮小） |

フェーズ1では、ミニプログラムのビルドは引き続き
[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md) 4章の手順どおり手動（HBuilderX）
のままとします。

## 5. エラーハンドリング・ロールバック

- ビルド失敗（`npm run build`失敗など）→ ジョブが失敗し、配信ディレクトリのファイルは
  変更されません（adminビルドが走らないため、直前の状態が保持されます）。
- スモークテスト失敗（デプロイ後にAPIが200を返さない等）→ ジョブを失敗としてマークし、
  通知のみ行います。自動ロールバックは今回のスコープ外です。`master`のコミット単位で状態が
  追えるため、必要な場合は手動で直前のコミットへ`git checkout`して即座に戻せます。
- DBスキーマ変更を伴うリリースは自動化の対象外とし、`crmeb/public/install/`配下のSQLファイル
  適用は引き続き手動とします（[multi-country-deployment.md](multi-country-deployment.md)と
  同じ運用）。自動マイグレーションは失敗時の被害が大きいため、意図的に含めません。

## 6. セキュリティ・運用上の注意点

- `install/`は本番投入前に必ず削除、または Cloudflare Access で保護（3章）。
- self-hosted runnerのトークンは自宅PCにのみ保存。runnerユーザーはDockerを操作できる権限が
  必要ですが、それ以上の昇格は与えません。
- PCの電源・スリープ無効化、`cloudflared`と`runner`のサービス化は必須です
  （片方でも落ちると本番停止に直結します）。
- MySQLデータは引き続き`help/docker/mysql/data`に永続化されます。**本番運用への切り替え時に
  定期バックアップ（タスクスケジューラでの`mysqldump`実行）を新規に追加**することを強く
  推奨します。現状のドキュメントには手動バックアップ手順のみで自動化がありません。

## 7. 未解決・今後の検討事項

- ミニプログラムのビルド自動化（4章、フェーズ2として別途着手）
- 自動ロールバック（現状は手動`git checkout`のみ）
- DBマイグレーションの自動適用（現状は手動SQL適用のみ）
- 定期バックアップの具体的なスケジュールと保存先（本設計では「追加が必要」と指摘するのみ）
