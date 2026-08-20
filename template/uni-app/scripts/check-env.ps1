<#
.SYNOPSIS
    WeChat ミニプログラム（template/uni-app）のビルド環境をチェックする。

.DESCRIPTION
    HBuilderX / 微信开发者工具のインストール有無、manifest.json の AppID がデモ用の
    ままになっていないか、config/app.js の接続先URLが期待値と一致しているかを確認し、
    チェックリストとして表示する。ファイルは変更しない（確認専用）。

    設計は ../../../docs/minipc-cloudflare-production-release.md（4章）を参照。
    HBuilderX での実ビルド自体は手動（[test-env-wechat-miniprogram.md](../../../docs/test-env-wechat-miniprogram.md) 参照）。

.PARAMETER ExpectedSiteUrl
    本番で使う予定のURL（例: https://shop.example.com）。指定すると config/app.js との
    差分を警告する。省略時はURLチェックをスキップする。

.PARAMETER HBuilderXPath
    HBuilderX.exe のパス。省略時は既知のインストール先を自動探索する。

.EXAMPLE
    .\check-env.ps1 -ExpectedSiteUrl "https://shop.example.com"
#>

param(
    [string]$ExpectedSiteUrl = "",
    [string]$HBuilderXPath = ""
)

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$ManifestPath = Join-Path $ProjectRoot "manifest.json"
$AppJsPath = Join-Path $ProjectRoot "config\app.js"
$DemoAppId = "wx3b82801238ca1b57"

function Write-Ready($msg)   { Write-Host "  [OK]   $msg" -ForegroundColor Green }
function Write-Warn2($msg)   { Write-Host "  [要確認] $msg" -ForegroundColor Yellow }
function Write-Missing($msg) { Write-Host "  [未検出] $msg" -ForegroundColor Red }

Write-Host "=== WeChat ミニプログラム ビルド環境チェック ===" -ForegroundColor Cyan

# 1. HBuilderX の検出
Write-Host "`n--- HBuilderX ---"
if ($HBuilderXPath -eq "") {
    $candidates = @(
        "$env:LOCALAPPDATA\HBuilderX\HBuilderX.exe",
        "C:\Program Files\HBuilderX\HBuilderX.exe",
        "C:\HBuilderX\HBuilderX.exe",
        "D:\HBuilderX\HBuilderX.exe"
    )
    $found = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
    if ($found) {
        Write-Ready "HBuilderX を検出: $found"
    } else {
        Write-Missing "既知のパスに HBuilderX が見つかりません。-HBuilderXPath で指定するか、"
        Write-Missing "         https://dcloud.io/hbuilderx.html からインストールしてください。"
    }
} elseif (Test-Path $HBuilderXPath) {
    Write-Ready "HBuilderX を検出: $HBuilderXPath"
} else {
    Write-Missing "指定されたパスに HBuilderX が見つかりません: $HBuilderXPath"
}

# 2. 微信开发者工具（WeChat DevTools）の検出
Write-Host "`n--- 微信开发者工具 (WeChat DevTools) ---"
$devtoolCandidates = @(
    "C:\Program Files (x86)\Tencent\微信web开发者工具\微信开发者工具.exe",
    "$env:LOCALAPPDATA\微信web开发者工具\微信开发者工具.exe"
)
$devtoolFound = $devtoolCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if ($devtoolFound) {
    Write-Ready "微信开发者工具 を検出: $devtoolFound"
} else {
    Write-Missing "既知のパスに微信开发者工具が見つかりません。実機/体験版確認に必要です。"
    Write-Missing "         https://developers.weixin.qq.com/miniprogram/dev/devtools/download.html からインストールしてください。"
}

# 3. manifest.json の AppID
# manifest.json は HBuilderX 独自のコメント付きJSON（JSON5相当）で、
# 文字列値に "https://..." のような "//" を含むため ConvertFrom-Json は使わず、
# "mp-weixin" ブロック内を対象にした正規表現で該当フィールドのみを抽出する。
Write-Host "`n--- manifest.json ---"
if (Test-Path $ManifestPath) {
    $manifestRaw = Get-Content $ManifestPath -Raw
    $mpWeixinMatch = [regex]::Match($manifestRaw, '(?s)"mp-weixin"\s*:\s*\{.*?"appid"\s*:\s*"([^"]*)".*?"urlCheck"\s*:\s*(true|false)')

    if ($mpWeixinMatch.Success) {
        $currentAppId = $mpWeixinMatch.Groups[1].Value
        if ($currentAppId -eq $DemoAppId) {
            Write-Warn2 "mp-weixin.appid が CRMEB のデモ用AppID ($DemoAppId) のままです。"
            Write-Warn2 "         自分のAppIDに差し替えてください（微信公众平台で取得）。"
        } else {
            Write-Ready "mp-weixin.appid はデモ用から変更済みです ($currentAppId)"
        }

        $urlCheck = $mpWeixinMatch.Groups[2].Value
        if ($urlCheck -eq "false") {
            Write-Ready "urlCheck は false（開発版・体験版で未登録ドメインでも通信可）"
        } else {
            Write-Warn2 "urlCheck が false になっていません。開発版でのテストに支障が出ます。"
        }
    } else {
        Write-Missing "manifest.json の mp-weixin.appid / urlCheck を検出できませんでした。ファイル構造を確認してください。"
    }
} else {
    Write-Missing "manifest.json が見つかりません: $ManifestPath"
}

# 4. config/app.js の接続先
Write-Host "`n--- config/app.js ---"
if (Test-Path $AppJsPath) {
    $appJsContent = Get-Content $AppJsPath -Raw
    $match = [regex]::Match($appJsContent, '(?s)#ifdef MP \|\| APP-PLUS.*?HTTP_REQUEST_URL:\s*`([^`]*)`')
    if ($match.Success) {
        $currentUrl = $match.Groups[1].Value
        Write-Host "  現在の接続先 (MP/APP): $currentUrl"
        if ($ExpectedSiteUrl -ne "") {
            if ($currentUrl -eq $ExpectedSiteUrl) {
                Write-Ready "期待値と一致しています ($ExpectedSiteUrl)"
            } else {
                Write-Warn2 "期待値と異なります。期待値: $ExpectedSiteUrl"
                Write-Warn2 "         build-miniprogram.ps1 -SiteUrl `"$ExpectedSiteUrl`" で同期できます。"
            }
        }
    } else {
        Write-Missing "HTTP_REQUEST_URL (MP/APP-PLUS ブロック) が見つかりませんでした。ファイル構造が変わった可能性があります。"
    }
} else {
    Write-Missing "config/app.js が見つかりません: $AppJsPath"
}

Write-Host "`n=== チェック完了 ===" -ForegroundColor Cyan
Write-Host "ミニプログラムの実ビルド・アップロードは引き続き手動（HBuilderX / 微信开发者工具）です。"
Write-Host "手順: ../../../docs/test-env-wechat-miniprogram.md の 3〜4 章を参照してください。"
