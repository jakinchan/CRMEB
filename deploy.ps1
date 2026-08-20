<#
.SYNOPSIS
    CRMEB 本番リリーススクリプト（自宅PC/ミニPC上での手動実行を想定）

.DESCRIPTION
    git pull → admin フロントエンドのビルド → workerman/queue の再起動 → スモークテスト
    を1回の実行で行う。設計は docs/minipc-cloudflare-production-release.md を参照。

    前提: help/docker/docker-compose.yml のコンテナが起動済みで、リポジトリの working copy が
    そのまま /var/www にバインドマウントされていること。

.PARAMETER SiteUrl
    スモークテストの疎通確認に使う本番URL（例: https://shop.example.com）。
    未指定の場合はスモークテストをスキップする。

.PARAMETER PhpContainer
    workerman/queue の再起動対象となる PHP コンテナ名。既定は docker-compose.yml の crmeb_php。

.PARAMETER SkipAdminBuild
    admin フロントエンドのビルドをスキップする（PHP側の変更のみ反映したい場合）。

.PARAMETER SkipSmokeTest
    SiteUrl を指定していてもスモークテストを行わない。

.EXAMPLE
    .\deploy.ps1 -SiteUrl "https://shop.example.com"

.EXAMPLE
    .\deploy.ps1 -SkipAdminBuild
#>

param(
    [string]$SiteUrl = "",
    [string]$PhpContainer = "crmeb_php",
    [switch]$SkipAdminBuild,
    [switch]$SkipSmokeTest
)

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $RepoRoot

function Write-Step($msg) { Write-Host "`n=== $msg ===" -ForegroundColor Yellow }
function Write-Ok($msg)   { Write-Host $msg -ForegroundColor Green }
function Write-Err($msg)  { Write-Host $msg -ForegroundColor Red }

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$Description,
        [Parameter(Mandatory = $true)][scriptblock]$Command
    )
    & $Command
    if ($LASTEXITCODE -ne 0) {
        Write-Err "失敗: $Description (exit code $LASTEXITCODE)"
        exit 1
    }
}

# 1. 最新コードを取得
# リポジトリの working copy がそのまま本番配信ディレクトリ（bind mount）なので、
# pull が完了した時点で PHP 側の変更は反映済み。
Write-Step "git pull (master)"
Invoke-Checked "git pull" { git pull origin master }
Write-Ok "git pull 完了"

# 2. admin フロントエンドをビルド
if (-not $SkipAdminBuild) {
    Write-Step "admin フロントエンドをビルド"
    Push-Location (Join-Path $RepoRoot "template\admin")
    try {
        Invoke-Checked "npm ci" { npm ci }
        Invoke-Checked "npm run build" { npm run build }

        $distDir = Join-Path (Get-Location) "dist"
        if (-not (Test-Path $distDir)) {
            Write-Err "ビルド成果物 (dist) が見つかりません: $distDir"
            exit 1
        }

        $targetDir = Join-Path $RepoRoot "crmeb\public\admin"
        Copy-Item -Path (Join-Path $distDir "*") -Destination $targetDir -Recurse -Force
        Write-Ok "admin ビルドを $targetDir に配置しました"
    } finally {
        Pop-Location
    }
} else {
    Write-Step "admin フロントエンドのビルドをスキップ (-SkipAdminBuild)"
}

# 3. サーバー側 (PHP) の反映: workerman / queue を再起動
# コンテナは再作成しない（DBデータ消失リスクを避けるため）。ファイルは bind mount で
# 既に反映済みなので、常駐プロセスの再起動のみ行う。
Write-Step "workerman / queue を再起動 ($PhpContainer)"
Invoke-Checked "workerman restart" { docker exec $PhpContainer php think workerman restart }
Invoke-Checked "queue restart"     { docker exec $PhpContainer php think queue:restart }
Write-Ok "再起動完了"

# 4. スモークテスト
if ($SkipSmokeTest) {
    Write-Step "スモークテストをスキップ (-SkipSmokeTest)"
} elseif ($SiteUrl -eq "") {
    Write-Step "スモークテストをスキップ (-SiteUrl 未指定)"
} else {
    Write-Step "スモークテスト ($SiteUrl)"
    $checks = @(
        "$SiteUrl/",
        "$SiteUrl/api/get_lang_type_list",
        "$SiteUrl/admin/"
    )
    foreach ($url in $checks) {
        try {
            $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) {
                Write-Ok "OK ($($response.StatusCode)): $url"
            } else {
                Write-Err "異常なステータス ($($response.StatusCode)): $url"
                exit 1
            }
        } catch {
            Write-Err "到達失敗: $url ($($_.Exception.Message))"
            exit 1
        }
    }
}

Write-Ok "`nデプロイ完了"
