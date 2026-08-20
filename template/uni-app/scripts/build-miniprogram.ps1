<#
.SYNOPSIS
    WeChat ミニプログラムのビルド準備を自動化するモジュール。

.DESCRIPTION
    自動化できる範囲（config/app.js の接続先URL同期、事前チェック）はこのスクリプトが行う。
    HBuilderX での実ビルドは GUI 専用アプリのため、既定では手動での実行を前提とする。
    HBuilderX の CLI 自動ビルドはこの環境で未検証のため、既定では実行しない
    （-TryCliBuild を指定した場合のみ試行し、失敗しても致命的エラーにはしない）。

    設計・制約は次を参照:
      - docs/minipc-cloudflare-production-release.md（4章: フェーズ2として扱う理由）
      - docs/test-env-wechat-miniprogram.md（3〜4章: 手動ビルド・体験版配布の手順）

.PARAMETER SiteUrl
    ミニプログラムの接続先とする本番URL（例: https://shop.example.com）。
    config/app.js の HTTP_REQUEST_URL（MP/APP-PLUS ブロックのみ）を自動で書き換える。

.PARAMETER TryCliBuild
    HBuilderX CLI での自動ビルドを試みる（実験的・未検証）。指定しない場合は
    設定同期とチェックのみ行い、ビルド自体は手動の手順を案内して終了する。

.PARAMETER HBuilderXPath
    HBuilderX.exe のパス。-TryCliBuild 指定時のみ使用。

.EXAMPLE
    .\build-miniprogram.ps1 -SiteUrl "https://shop.example.com"

.EXAMPLE
    .\build-miniprogram.ps1 -SiteUrl "https://shop.example.com" -TryCliBuild -HBuilderXPath "C:\HBuilderX\HBuilderX.exe"
#>

param(
    [Parameter(Mandatory = $true)][string]$SiteUrl,
    [switch]$TryCliBuild,
    [string]$HBuilderXPath = ""
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$AppJsPath = Join-Path $ProjectRoot "config\app.js"
$ManifestPath = Join-Path $ProjectRoot "manifest.json"
$DemoAppId = "wx3b82801238ca1b57"

function Write-Step($msg) { Write-Host "`n=== $msg ===" -ForegroundColor Yellow }
function Write-Ok($msg)   { Write-Host $msg -ForegroundColor Green }
function Write-Warn2($msg) { Write-Host $msg -ForegroundColor Yellow }
function Write-Err($msg)  { Write-Host $msg -ForegroundColor Red }

# 1. config/app.js の接続先URLを同期する（MP/APP-PLUS ブロックのみ、H5ブロックは触らない）
Write-Step "config/app.js の接続先を同期"
if (-not (Test-Path $AppJsPath)) {
    Write-Err "config/app.js が見つかりません: $AppJsPath"
    exit 1
}

$appJsContent = Get-Content $AppJsPath -Raw
$pattern = [regex]'(?s)(#ifdef MP \|\| APP-PLUS.*?HTTP_REQUEST_URL:\s*`)[^`]*(`,)'
$match = $pattern.Match($appJsContent)
if (-not $match.Success) {
    Write-Err "HTTP_REQUEST_URL (MP/APP-PLUS ブロック) が見つかりませんでした。ファイル構造が変わった可能性があります。"
    Write-Err "config/app.js を確認し、手動で書き換えてください。"
    exit 1
}

$currentUrl = [regex]::Match($appJsContent, '(?s)#ifdef MP \|\| APP-PLUS.*?HTTP_REQUEST_URL:\s*`([^`]*)`').Groups[1].Value
if ($currentUrl -eq $SiteUrl) {
    Write-Ok "既に同期済みです ($SiteUrl)"
} else {
    $evaluator = [System.Text.RegularExpressions.MatchEvaluator] {
        param($m)
        $m.Groups[1].Value + $SiteUrl + $m.Groups[2].Value
    }
    $newContent = $pattern.Replace($appJsContent, $evaluator)
    Set-Content -Path $AppJsPath -Value $newContent -NoNewline
    Write-Ok "接続先を更新しました: $currentUrl -> $SiteUrl"
    Write-Warn2 "変更内容は git 管理下です。意図した変更か 'git diff config/app.js' で確認してください。"
}

# 2. manifest.json の AppID チェック（自動変更はしない。AppIDは意図的な設定値のため）
# manifest.json はコメント付きJSON（JSON5相当）で文字列値に "//" を含むため、
# ConvertFrom-Json ではなく "mp-weixin" ブロックを対象にした正規表現で抽出する。
Write-Step "manifest.json の AppID を確認"
if (Test-Path $ManifestPath) {
    $manifestRaw = Get-Content $ManifestPath -Raw
    $mpWeixinMatch = [regex]::Match($manifestRaw, '(?s)"mp-weixin"\s*:\s*\{.*?"appid"\s*:\s*"([^"]*)"')
    if (-not $mpWeixinMatch.Success) {
        Write-Err "manifest.json の mp-weixin.appid を検出できませんでした。ファイル構造を確認してください。"
        exit 1
    }
    if ($mpWeixinMatch.Groups[1].Value -eq $DemoAppId) {
        Write-Warn2 "mp-weixin.appid が CRMEB のデモ用AppIDのままです。ビルド前に手動で差し替えてください。"
    } else {
        Write-Ok "AppID はデモ用から変更済みです"
    }
} else {
    Write-Err "manifest.json が見つかりません: $ManifestPath"
    exit 1
}

# 3. HBuilderX CLI ビルド（実験的・既定ではスキップ）
if ($TryCliBuild) {
    Write-Step "HBuilderX CLI ビルド（実験的・未検証）"
    Write-Warn2 "この機能は現在の自宅PC環境で動作検証されていません。"
    Write-Warn2 "HBuilderX の公式CLI仕様（https://hx.dcloud.net.cn/CLI/README）を確認のうえ、"
    Write-Warn2 "このスクリプト内の Invoke-HBuilderXCliBuild 関数を環境に合わせて実装してください。"

    function Invoke-HBuilderXCliBuild {
        param([string]$HBuilderXExe, [string]$ProjectPath)
        # TODO: DCloud公式CLI手順に沿ってここを実装する（この環境では未検証のため未実装）。
        throw "未実装: HBuilderX CLI ビルドはこの環境でまだ検証・実装されていません。"
    }

    try {
        if ($HBuilderXPath -eq "" -or -not (Test-Path $HBuilderXPath)) {
            throw "HBuilderXPath が未指定、または存在しません: '$HBuilderXPath'"
        }
        Invoke-HBuilderXCliBuild -HBuilderXExe $HBuilderXPath -ProjectPath $ProjectRoot
        Write-Ok "CLIビルドに成功しました"
    } catch {
        Write-Err "CLIビルドに失敗、またはスキップしました: $($_.Exception.Message)"
        Write-Warn2 "手動ビルドにフォールバックしてください（下記チェックリスト参照）。"
    }
} else {
    Write-Step "HBuilderX CLI ビルドはスキップ（-TryCliBuild 未指定）"
}

# 4. 残りの手動手順を案内
Write-Step "残りの手動手順"
Write-Host @"
  1. HBuilderX で template/uni-app を開き、「発行 → 微信小程序」を実行
     -> unpackage/dist/build/mp-weixin が生成されます
  2. 微信开发者工具で unpackage/dist/build/mp-weixin を開く
     -> 「ローカル設定 → 合法域名のチェックを行わない」にチェック
  3. コンパイルして動作確認（トップページ・ログイン・画像表示・言語切替・WebSocket）
  4. 体験版として配布する場合:
     開発者ツールで「アップロード」→ 微信公众平台「バージョン管理」で体験版に設定
     → 「メンバー管理」でテスターを追加 → 実機では「調試モードON」が必要

  詳細: docs/test-env-wechat-miniprogram.md の 3〜4章
"@

Write-Ok "`nミニプログラムのビルド準備が完了しました（設定同期のみ自動化・ビルド自体は手動）"
