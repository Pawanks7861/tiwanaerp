# Session-only development environment for this project.
# Usage (PowerShell, from the project root):  . .\scripts\dev-env.ps1
# Nothing global is changed: PATH and cache locations apply to the current shell only.

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpDir = 'C:\wamp64\bin\php\php8.3.6'

if (-not (Test-Path "$phpDir\php.exe")) {
    throw "PHP 8.3 not found at $phpDir. Laravel 11 requires PHP 8.2+."
}

# Vite 8 needs Node ^20.19 || >=22.12; a portable Node lives in .tools/node so the global Node is untouched.
$nodeDir = Join-Path $projectRoot '.tools\node'
if (-not (Test-Path "$nodeDir\node.exe")) {
    throw "Portable Node not found at $nodeDir. See docs/BUILDIFY360_ARCHITECTURE.md (Local environment findings)."
}

$env:Path = "$phpDir;$nodeDir;" + (($env:Path -split ';' | Where-Object { $_ -and $_ -ne $phpDir -and $_ -ne $nodeDir }) -join ';')
$env:COMPOSER_CACHE_DIR = Join-Path $projectRoot '.cache\composer'
$env:COMPOSER_HOME = Join-Path $projectRoot '.cache\composer-home'
$env:npm_config_cache = Join-Path $projectRoot '.cache\npm'
# WAMP's php.ini loads Xdebug in "develop" mode, which slows CLI runs (tests, artisan) several-fold.
# Set $env:XDEBUG_MODE = 'debug' after sourcing this script when step debugging is needed.
if (-not $env:XDEBUG_MODE) { $env:XDEBUG_MODE = 'off' }

Set-Location $projectRoot
Write-Host ("tiwanaerp dev env: PHP " + (php -r 'echo PHP_VERSION;') + ", Node " + (node -v) + " @ " + (Get-Location).Path)
