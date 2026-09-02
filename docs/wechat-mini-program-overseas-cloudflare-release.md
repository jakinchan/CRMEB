# 海外運用会社向け WeChat ミニプログラム本番リリース手順

最終更新: 2026-09-02

対象: CRMEB `template/uni-app`、海外法人が運用し、現在のドメインを Name.com で登録し、
Cloudflare DNS/CDN を利用している案件。

> 「nameドメイン」は **Name.com で登録したドメイン**という前提で記載する。
> 別サービスを意味する場合は、ドメイン登録事業者の部分だけ読み替えること。

## 1. 結論

中国本土の一般ユーザーへ正式公開するなら、次の構成を第一候補とする。

1. 中国本土の運用主体（中国法人・中国支店、または契約上正式に主体となる中国側パートナー）を確定する。
2. ミニプログラムの主体、备案主体、ドメイン名義、WeChat Payの契約主体を原則として揃える。
3. 中国向けに新しい本番ドメインを中国国内の認定登録事業者で取得するか、Name.com の既存ドメインを移管する。
4. Tencent Cloud 中国本土リージョンに CRMEB API、DB、Redis、画像、監視、バックアップを配置する。
5. 初期リリースは DNSPod/Tencent Cloud CDN・WAF を使用する。
6. Cloudflare は海外向けサイト、海外運用者向け入口、既存DNS用途として継続する。
7. 中国と海外を単一Cloudflare運用に統合する必要があり、Enterprise予算を確保できる場合だけ
   Cloudflare China Network（JD Cloud）を比較対象にする。

通常のCloudflareグローバルネットワークやCloudflare Tunnelをそのまま使っても、中国本土内の
配信拠点、ICP/APP备案、WeChat審査要件を代替できない。正式公開の基盤ではなく、開発版・体験版の
確認環境として扱う。

## 2. 最初に行う公開可否判定

技術作業やクラウド購入の前に、以下を1枚の確認票にして合意する。

| 確認項目 | 合格条件 | 担当 |
| --- | --- | --- |
| ミニプログラム主体 | 微信公众平台で対象国・業種・サービスカテゴリが選択できる | 海外運用会社 |
| 中国側主体 | 中国で备案できる法人・支店または正式な運用パートナーが決定済み | 事業責任者 |
| 販売形態 | 中国国内ECか越境ECかを確定し、必要な許認可・商品カテゴリを確認済み | 法務・事業 |
| 決済 | WeChat Payの国内/越境加盟店契約、通貨、精算口座、返金責任を確認済み | 経理・法務 |
| 個人情報 | 保存場所、越境移転、委託先、保持期間、削除窓口を中国法務と確認済み | 法務・セキュリティ |
| ドメイン | 备案主体と登録者名義が一致し、备案可能な登録事業者・TLDである | 中国側主体 |
| コンテンツ | 商品、広告表現、カテゴリ、プライバシー指針が審査可能 | 運用会社 |

中国国内でインターネット情報サービスを提供するミニプログラムはAPP备案の対象であり、未备案の
アプリを配信しないことが中国工業情報化部から求められている。正式な最終判断は、中国側主体の所在地を
管轄する通信管理局、接入商、および微信公众平台の备案画面で行う。

- 工業情報化部通知: <https://www.gov.cn/zhengce/zhengceku/202308/content_6897341.htm>
- 微信公式备案ガイド（ログインや地域により表示制限あり）:
  <https://developers.weixin.qq.com/miniprogram/product/record_guidelines.html>

### 海外法人だけで進める場合の注意

Tencent Cloud 中国の現行案内では、海外企業そのものはICP备案できず、中国国内に支店等を設立し、
中国で認められる主体証明を持つ必要がある。海外法人名義のミニプログラム登録が可能なケースと、
中国本土向けサービスの备案・決済・業種許可は別の判定である。

- Tencent Cloud「境外企業如何备案」:
  <https://cloud.tencent.com/document/faq/243/19628>

中国側主体を用意しない方針なら、クラウド契約前に微信公众平台サポートへ次を文書で確認する。

1. 海外主体の当該サービスカテゴリで、中国本土ユーザー向け正式公開が可能か。
2. 小程序备案を海外主体として完了できるか。
3. 中国国外APIドメインを `request/uploadFile/downloadFile/socket` 合法域名として登録できるか。
4. 越境WeChat Payが対象商品、通貨、精算国に対応するか。

1つでも不可なら、中国側主体を用意するか、中国本土正式公開を見送る。香港サーバーやCloudflareへの
変更だけでこの主体要件を回避しない。

## 3. ドメイン方針

### 推奨

- 中国向け専用ドメイン例: `example.cn` または `cn-example.com`
- ミニプログラムAPI: `mini-api.example.cn`
- 画像: 初期は同一ホスト、負荷増加後に `mini-static.example.cn`
- 管理画面: `admin.example.cn`（一般公開せず、VPN/IP制限/MFAを併用）

WeChatの合法域名は、実際に使用するホスト名を `request`、`uploadFile`、`downloadFile`、`socket` の
各欄へ登録する。リダイレクト先や画像CDNも漏らさない。

### Name.com 登録ドメイン

Name.com が中国国外の登録事業者である場合、そのままTencent CloudでICP备案することはできないという
現行案内がある。次のどちらかを選ぶ。

1. **推奨:** 中国向け専用ドメインをTencent Cloud等の中国国内認定登録事業者で新規取得し、
   中国側备案主体の名義で実名認証する。
2. 既存ドメインを中国国内の認定登録事業者へ移管し、登録者名義を备案主体と一致させる。

既存の海外サイトまで影響させないため、初回は新しい中国向け専用ドメインの方が安全である。

- Tencent Cloudの域名・备案条件:
  <https://cloud.tencent.com/document/faq/243/19631>

## 4. クラウド構成案

### A. 推奨: Tencent Cloud 中国本土 + Cloudflare併用

```text
中国のWeChat利用者
        |
        v
DNSPod / Tencent Cloud CDN・WAF
        |
        v
CLB または Nginx
        |
        +-- CRMEB PHP/API + Workerman
        +-- TencentDB for MySQL
        +-- TencentDB for Redis
        +-- COS（商品画像・添付）
        +-- CLS/監視/アラーム + 自動バックアップ

海外運用者 ---- VPN/許可IP/MFA ---- 管理画面
海外向けサイト ------------------- Cloudflare Global
```

初期は1台のCVMでアプリを動かせるが、DBとRedisはマネージドサービスへ分離する。注文・決済データを
守るため、DBをコンテナ内だけに保存しない。可用性が必要になったら、アプリを2台化してCLB配下へ置く。

**この案を推奨する理由**

- WeChat、备案、ドメイン、クラウド窓口をTencent系に寄せ、責任分界を単純にできる。
- 中国本土利用者へのAPI・画像・WebSocket経路を国内に置ける。
- Cloudflare Enterpriseを初期費用に含めず、小さく開始できる。
- 既存のCloudflareは海外向け資産で継続できる。

### B. 大規模向け: Cloudflare China Network + 中国本土オリジン

Cloudflare China NetworkはJD Cloudが中国本土で運用する別サービスで、通常のFree/Pro/Businessの
Cloudflareとは異なる。Cloudflare Enterpriseに加えてChina Network契約、各apexドメインの有効な
ICP备案/許可、JD Cloudによるコンテンツ審査が必要である。

次の場合に比較する。

- 中国と海外の双方に大きなトラフィックがある。
- WAF/DDoS/DNSポリシーをCloudflareへ統合する価値が高い。
- Enterprise契約とPoCの予算・調整期間を確保できる。

Cloudflare China Networkでも全Cloudflare製品が利用できるわけではない。CRMEBが使うWebSocket、
証明書、WAF、キャッシュ、ログを実トラフィックでPoCし、未対応機能の代替を決めてから採用する。
Turnstileは中国本土で利用不可と明記されているため、ログインや注文フローへ前提なく組み込まない。

- 概要・契約条件: <https://developers.cloudflare.com/china-network/>
- 導入条件: <https://developers.cloudflare.com/china-network/get-started/>
- 対応製品: <https://developers.cloudflare.com/china-network/reference/available-products/>

### C. 開発・市場検証のみ: 香港/日本 + Cloudflare Global

香港または日本のサーバー、Cloudflare Global、Cloudflare Tunnelは開発版・体験版の実機確認には
使用できる。ただし中国本土向けの通信品質、备案、正式公開、WeChat Pay審査を保証しない。
本番案A/Bの代替として扱わない。

## 5. 本番リリース手順

### Phase 0: 契約・主体・カテゴリを確定する

- [ ] 微信公众平台で海外主体/中国主体のどちらを使うか決定する。
- [ ] ミニプログラムのサービスカテゴリと必要資格を画面上で確認する。
- [ ] 中国側备案主体、ドメイン名義、クラウド契約、決済主体の対応表を承認する。
- [ ] 運用会社、開発会社、中国側主体の権限と責任を契約へ記載する。
- [ ] データ保管・越境移転・インシデント連絡先を法務確認する。

このPhaseが未完了なら、本番クラウドの長期契約を開始しない。

### Phase 1: ドメインと备案を準備する

1. 中国国内の認定登録事業者でドメインを取得または移管する。
2. 登録者を备案主体名義にし、実名認証を完了する。
3. Tencent Cloud中国アカウントを同じ主体で実名認証する。
4. 备案可能な中国本土CVM等を必要期間で契約する。
5. 接入商の手順でWebサイト/APPのICP备案を申請する。
6. 微信公众平台の「小程序备案」でミニプログラム备案を申請する。
7. 必要な前置審査、经营性ICP许可证、公安联网备案の要否を管轄当局・中国法務に確認する。
8. 発行された备案番号を、要求される画面とWebサイトへ表示する。

審査期間は地域・カテゴリ・資料差戻しで変わる。Cloudflareの案内はICP取得に4〜8週間の目安を示し、
中国当局通知は資料受領後の省級審査を20営業日以内としているが、プロジェクト日程には差戻しと
クラウド側初審の余裕を加える。

### Phase 2: Tencent Cloud本番基盤を構築する

1. VPCを作り、DB/Redisをプライベートサブネットへ置く。
2. CVMまたはコンテナ基盤へNginx、PHP、CRMEB、Workerman/queueを配置する。
3. TencentDB for MySQLを作成し、自動バックアップと復旧試験を設定する。
4. TencentDB for Redisを作成し、インターネットへ公開しない。
5. COSを作成し、商品画像・アップロードの公開/非公開範囲とCORSを設定する。
6. HTTPS証明書を設定し、TLS 1.2以上を有効にする。
7. WAF、DDoS保護、レート制限を有効化する。
8. `/install/` を遮断し、`/admin/` はVPN、固定IP、MFA等で制限する。
9. API、画像、アップロード、WebSocket、キュー、DB容量、証明書期限を監視する。
10. 本番DBのバックアップから別環境へ復元できることを確認する。

### Phase 3: CRMEBとミニプログラムを本番値へ設定する

現在のリポジトリで確認できた値:

| 項目 | 現在 | リリース前の対応 |
| --- | --- | --- |
| `template/uni-app/config/app.js` | `https://tianchibencao.goodworld.co.jp` | 中国向け本番API URLへ変更 |
| `template/uni-app/manifest.json` の `mp-weixin.appid` | CRMEBデモAppID | 本案件AppIDへ変更 |
| `mp-weixin.setting.urlCheck` | `false` | 本番確認時は `true` |
| `template/uni-app/package.json` | ビルドscriptなし | HBuilderXでビルド |
| CRMEB `site_url` | DB/管理画面設定 | 中国向けHTTPS URLへ変更 |
| `routine_appId` / `routine_appsecret` | DB/管理画面設定 | 本案件値をサーバー側だけに設定 |

具体的な作業順:

1. `manifest.json` の `mp-weixin.appid` を正式AppIDへ変更する。
2. `config/app.js` の `HTTP_REQUEST_URL` を `https://mini-api.example.cn` へ変更する。
3. CRMEB管理画面の `site_url` を同じ本番URLへ変更する。
4. CRMEB管理画面の `routine_appId`、`routine_appsecret` を設定する。
5. 微信公众平台へ `request/uploadFile/downloadFile/socket` 合法域名を登録する。
6. `manifest.json` の `urlCheck` を `true` にする。
7. DBやコンテンツに旧ドメインの絶対URLが残っていないか検索・置換する。
8. キャッシュを消し、Workermanとqueueを安全に再起動する。

`AppSecret`、WeChat Pay APIキー、秘密鍵、証明書秘密鍵をGit、`manifest.json`、
`project.config.json`、チャットへ保存しない。現状の `manifest.json` にはクライアント配布物へ置くべきでない
secret形式の値が含まれているため、値の用途を確認し、公開前に管理画面/Secret Managerへ移し、
既に実値なら発行元でローテーションする。

既存の準備スクリプト:

```powershell
cd D:\Local\apps\CRMEB\template\uni-app
.\scripts\check-env.ps1 -ExpectedSiteUrl "https://mini-api.example.cn"
.\scripts\build-miniprogram.ps1 -SiteUrl "https://mini-api.example.cn"
```

2本目は接続先同期と事前確認までを行う。実ビルドはHBuilderXで手動実行する。

### Phase 4: ビルド、体験版、実機試験

1. HBuilderXで `template/uni-app` を開く。
2. 「発行 → 微信小程序」でビルドする。
3. `unpackage/dist/build/mp-weixin` を微信开发者工具で開く。
4. 「合法域名のチェックを行わない」を無効にし、本番相当でコンパイルする。
5. バージョン番号、Git commit、APIドメイン、DB migration番号をリリース記録へ残す。
6. 「アップロード」後、微信公众平台で体験版に設定する。
7. 中国本土回線の実機2台以上で次を確認する。

- [ ] 初回起動、プライバシー同意、権限拒否時の動作
- [ ] 商品一覧、検索、商品詳細、画像
- [ ] 新規登録、WeChatログイン、再ログイン、ログアウト
- [ ] カート、住所、送料、在庫、注文作成
- [ ] WeChat Pay、失敗、キャンセル、返金
- [ ] 画像アップロード
- [ ] WebSocket通知/チャットと再接続
- [ ] 管理画面への注文・在庫反映
- [ ] 中国語表示と対象言語切替
- [ ] APIのP95応答時間、5xx、タイムアウト

### Phase 5: 審査提出と公開

1. 小程序备案が完了し、备案番号表示を確認する。
2. サービスカテゴリ、必要資格、プライバシー保護指針、ユーザー規約を設定する。
3. 審査用アカウント、操作説明、主要画面、決済確認方法を用意する。
4. 体験版の承認記録を取り、「提交审核」を実行する。
5. 差戻し理由を修正し、同一版で再試験する。
6. 審査通過後、事業責任者の承認を得て「发布」を実行する。
7. 公開後に新規ユーザーとして購入から管理画面反映まで再確認する。

審査通過だけでは公開完了ではない。`アップロード → 体験版 → 審査 → 審査通過 → 发布 → 本番確認`
までを1回のリリースとする。

## 6. Cloudflareを使う場合の設定境界

### 通常のCloudflare Globalを残す範囲

- 海外向けWebサイトと静的コンテンツ
- 海外の運用者向け入口
- 中国本番と分離した開発・ステージング
- 中国向け専用ドメイン以外のDNS/WAF/CDN

### Cloudflare China Network採用時

- Enterprise契約とChina Network追加契約を完了する。
- apexドメインのICP番号を提示する。
- JD Cloudのコンテンツ審査を完了する。
- 中国本土オリジンへの許可IP、TLS、Hostヘッダーを設定する。
- APIはキャッシュせず、画像/CSS/JSだけキャッシュする。
- `/api/*`、ログイン、カート、注文、決済コールバックをBypass Cacheにする。
- WebSocket、アップロード、Range、決済コールバックをPoCする。
- Cloudflare障害時のDNS切替先を事前に用意する。

Cloudflare China Networkは「备案取得後の配信・防御製品」であり、「海外ドメインを备案不要にする製品」
ではない。

## 7. ロールバック

公開前に次を揃える。

- 直前バージョンの微信代码包とGit tag/commit
- DB migrationの前後バックアップと復元手順
- 旧APIバージョンを一定期間動かせる互換性
- DNS/CDN/WAF変更の戻し値
- 機能停止時の注文・決済・返金の手動処理手順
- 中国側運用担当、海外運用会社、開発会社、決済会社の緊急連絡網

重大障害時は、微信公众平台のバージョン管理で利用可能な直前安定版へ戻し、API側も対応commitへ戻す。
DB破壊を伴う変更はコードだけ戻しても復旧しないため、後方互換migrationまたは復元手順を必須とする。

## 8. この環境で確認済み・未確認

確認済み:

- ミニプログラムソースは `template/uni-app`。
- API基点は `template/uni-app/config/app.js` の `HTTP_REQUEST_URL`。
- API呼出しは `HTTP_REQUEST_URL + '/api/'`。
- 画像アップロードも同じAPI基点を使用する。
- WebSocket URLはAPI応答から取得して接続するため、socket合法域名と経路試験が必要。
- `package.json` にビルドscriptがなく、既存手順はHBuilderXを使用する。

未確認:

- 実際のName.comドメイン名、登録者、TLD、移管ロック、実名認証可否。
- 海外運用会社の登記国、WeChatアカウント主体、対象サービスカテゴリ。
- 中国側主体、ICP/APP备案、前置許可、公安联网备案の取得状況。
- Tencent Cloud/Cloudflare China Networkの契約、構築、PoC。
- 本番AppID、WeChat Pay、合法域名、HBuilderX実ビルド、審査、正式公開。
- 現在設定されているAPI URLの中国本土からの到達性・本番適格性。

これらは本手順書を作成した時点では実行していない。契約・备案・審査条件は変更されるため、購入・申請時に
微信公众平台、接入商、中国法務、Cloudflare担当者の最新回答で更新する。
