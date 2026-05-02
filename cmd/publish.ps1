# Build frontend (Vue -> web/dist/) pro nasazeni.
#
#   cd web
#   pnpm install
#   pnpm build
#
# Pouziti:  .\cmd\publish.ps1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location (Join-Path $ProjectRoot 'web')

if (-not (Get-Command pnpm -ErrorAction SilentlyContinue)) {
    Write-Error "pnpm not found in PATH. Install: npm install -g pnpm"
}

Write-Host "==> pnpm install"
& pnpm install
if ($LASTEXITCODE -ne 0) { Write-Error "pnpm install failed" }

Write-Host ""
Write-Host "==> pnpm build"
& pnpm build
if ($LASTEXITCODE -ne 0) { Write-Error "pnpm build failed" }

Write-Host ""
Write-Host "==> Done. web/dist/ je pripraveny k nasazeni."
