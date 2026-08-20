# ミニPC本番リリース設計（Cloudflare連携 + 最小構成ビルド/リリース）

自宅PC（[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md)で使用したテスト環境）を、
WeChatミニプログラム込みの中国向けECの**本番運用**に転用するための設計です。
「早く動かす」「一番簡単」を優先し、**CI基盤（GitHub Actions等）を持たない、
1本のデプロイスクリプトを手動実行するだけの構成**を採用します。

## 0. 前提・スコープ

- 対象マシン: 既存の自宅PC（新規ハードウェア調達なし）
- 対象製品: WeChatミニプログラム込みの中国向けEC
- **WeChatミニプログラムは今回、体験版/開発者モードでの運用を前提とします。**
  正式版（一般公開・審査通過）にはICP備案済みドメインが必須で、自宅PC + Cloudflareの構成では
  到達できません（詳細は[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md)の
  「0. 最初に把握しておくべき制約」を参照）。正式リリースは本設計のスコープ外です。
- ビルド対象は3つ：サーバー側(PHP)コードの反映、admin フロントエンドのビルド、
  WeChatミニプログラムのビルド。**リリースの自動化レベルは「完全手動」を採用します**
  （CI/CD基盤のセットアップ・保守コストより、手順の単純さを優先）。
  ミニプログラムのビルド自動化（HBuilderX CLI）は技術的不確実性が高いため、
  検討する場合も別タスク（フェーズ2）として扱います（4章参照）。

## 1. 全体アーキテクチャ

```
GitHub (future_dev で開発 → master へ merge)
   │
   ▼ （手動）自宅PCで deploy.ps1 を実行
自宅PC
   ├─ git pull（リポジトリの working copy がそのまま本番配信ディレクトリ）
   ├─ admin フロントエンド build（template/admin → crmeb/public/admin）
   └─ workerman / queue の再起動（bind mount のためファイルは即反映）
   ▼
Cloudflare Tunnel（Windows サービス化・常駐）
   │ HTTPS
   ▼
エンドユーザー / WeChat 開発者ツール
```

**設計方針**: CI基盤（GitHub Actions・self-hosted runnerの登録・保守）を持たず、
`master`にmergeした後、自宅PC上で1本のスクリプトを手動実行するだけでリリースが完了する
構成にします。Cloudflare Tunnelはアウトバウンド接続方式のため、この構成全体で
**自宅PC側の受信ポート開放はゼロ**のまま維持されます。

## 2. デプロイスクリプト

```powershell
# deploy.ps1（自宅PC上、リポジトリのルートで実行）
git pull origin master

Push-Location template/admin
npm ci
npm run build
Copy-Item -Recurse -Force dist\* ..\..\crmeb\public\admin\
Pop-Location

docker exec crmeb_php php think workerman restart
docker exec crmeb_php php think queue:restart

# 簡易スモークテスト（任意）
curl.exe -f https://<本番ドメイン>/ | Out-Null
curl.exe -f https://<本番ドメイン>/api/get_lang_type_list | Out-Null
Write-Host "deploy done"
```

**リリース手順は「masterにmergeしたら、自宅PCでこのスクリプトを1回実行するだけ」。**

**設計上のポイント**

- リポジトリのworking copy自体が配信ディレクトリなので、`git pull`が完了した時点でPHP側の
  変更は反映済みです。rsyncやSCPは不要。`help/docker/docker-compose.yml`のbind-mount構成
  （`../../crmeb:/var/www`）と噛み合っています。
- `crmeb/vendor/` はリポジトリにコミット済みのため `composer install` 不要（作業を1手順減らせる）。
- admin フロントエンドのビルド成果物（`crmeb/public/admin`）は**都度ビルドし直す**方式にし、
  gitにはコミットしません（`.gitignore` に追加）。ビルドのソースは `template/admin` のみを正とします。
- PHP/nginx/mysql/redisの各コンテナは**再作成しません**（`docker compose restart`すら行いません）。
  bind mountによりファイルは即反映され、workermanとqueueだけ再起動すれば十分です。
  コンテナ再作成はDBデータ消失リスクがあるため、通常運用フローから意図的に除外します
  （[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md) 1-0章の教訓）。
- 将来トラフィックが増え、手動実行の手間や実行忘れが問題になってきたら、次の順で
  自動化レベルを上げられます（今回は導入しません）。
  1. Windowsタスクスケジューラで`deploy.ps1`を定期実行し、`git fetch`で差分がある時だけ処理する
     ポーリング方式（CI基盤不要）
  2. GitHub Actions + self-hosted runnerによるpush駆動のCI/CD（スモークテスト失敗の自動通知など、
     完成度は上がるが構築・保守の手間も増える）

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
| フェーズ1（本設計のスコープ） | サーバー側コード + admin フロントエンドのデプロイ（`deploy.ps1`の手動実行） | 高（標準的なnpm/dockerコマンドのみ） |
| フェーズ2（別タスク・後日、必要になれば） | HBuilderX CLIでのミニプログラム自動ビルド | 中〜低（要検証。難しければ「ビルドは手動、体験版アップロードのみ自動化」等に縮小） |

フェーズ1では、ミニプログラムのビルドは引き続き
[test-env-wechat-miniprogram.md](test-env-wechat-miniprogram.md) 4章の手順どおり手動（HBuilderX）
のままとします。

## 5. エラーハンドリング・ロールバック

- ビルド失敗（`npm run build`失敗など）→ `deploy.ps1`がその時点で止まります。PowerShellは
  デフォルトで非終端エラーでも後続行を実行し続けるため、各コマンド後に`if ($LASTEXITCODE -ne 0) { exit 1 }`
  を入れ、途中失敗時に後続の`Copy-Item`やコンテナ再起動が実行されないようにします。
- スモークテスト失敗（デプロイ後にAPIが200を返さない等）→ スクリプト実行者（自宅PCの前にいる本人）が
  その場で気づけます。自動通知やロールバックは行いません。`master`のコミット単位で状態が
  追えるため、必要な場合は手動で直前のコミットへ`git checkout`して`deploy.ps1`を再実行すれば
  即座に戻せます。
- DBスキーマ変更を伴うリリースは自動化の対象外とし、`crmeb/public/install/`配下のSQLファイル
  適用は引き続き手動とします（[multi-country-deployment.md](multi-country-deployment.md)と
  同じ運用）。自動マイグレーションは失敗時の被害が大きいため、意図的に含めません。

## 6. セキュリティ・運用上の注意点

- `install/`は本番投入前に必ず削除、または Cloudflare Access で保護（3章）。
- CI基盤を持たないため、GitHubトークンや外部サービスの資格情報を自宅PCに置く必要は
  ありません（`deploy.ps1`は`git pull`にリポジトリの通常のクレデンシャルを使うのみ）。
- PCの電源・スリープ無効化、`cloudflared`のサービス化は必須です
  （落ちると本番停止に直結します）。
- MySQLデータは引き続き`help/docker/mysql/data`に永続化されます。**本番運用への切り替え時に
  定期バックアップ（タスクスケジューラでの`mysqldump`実行）を新規に追加**することを強く
  推奨します。現状のドキュメントには手動バックアップ手順のみで自動化がありません。

## 7. 未解決・今後の検討事項

- ミニプログラムのビルド自動化（4章、フェーズ2として別途着手）
- リリースの自動化レベルの引き上げ（タスクスケジューラでのポーリング、または
  GitHub Actions + self-hosted runner。2章末尾参照。トラフィックや更新頻度が増えてから検討）
- 自動ロールバック（現状は手動`git checkout`のみ）
- DBマイグレーションの自動適用（現状は手動SQL適用のみ）
- 定期バックアップの具体的なスケジュールと保存先（本設計では「追加が必要」と指摘するのみ）
