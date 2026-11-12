# Builds everything to upload to shared hosting into dist-hosting\.
# Run on your own computer, which needs PHP, Composer, Node and the local database running:
#   powershell -ExecutionPolicy Bypass -File deploy\build-hosting-package.ps1 -Url https://pos.example.com
param(
    [Parameter(Mandatory = $true)][string]$Url,
    [string]$MysqlBin = 'C:\xampp\mysql\bin',
    [string]$DbUser = 'root',
    [string]$DbPassword = ''
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$out = Join-Path $root 'dist-hosting'
$app = Join-Path $out 'naturolls_app'
$web = Join-Path $out 'public_html'
$tempDb = 'naturolls_package_build'
$pwdArg = if ($DbPassword) { "-p$DbPassword" } else { $null }

function Random-Key {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    [Convert]::ToBase64String($bytes)
}
function Mysql([string]$sql) { & "$MysqlBin\mysql.exe" -u $DbUser $pwdArg -e $sql; if ($LASTEXITCODE) { throw 'mysql failed' } }

if (Test-Path $out) { Remove-Item -Recurse -Force $out }
New-Item -ItemType Directory -Force $app, $web | Out-Null

Write-Host 'Building the app...'
Push-Location (Join-Path $root 'frontend')
npm run build
if ($LASTEXITCODE) { throw 'Frontend build failed' }
Pop-Location
Copy-Item -Recurse (Join-Path $root 'frontend\dist\*') $web
Copy-Item -Force (Join-Path $PSScriptRoot 'hosting\public_html\index.php') $web
Copy-Item -Force (Join-Path $PSScriptRoot 'hosting\public_html\.htaccess') $web

Write-Host 'Copying the server code...'
robocopy (Join-Path $root 'backend') $app /E /NFL /NDL /NJH /NJS /NP `
    /XD vendor node_modules tests public .git .phpunit.cache `
    /XF .env .env.testing phpunit.xml .phpunit.result.cache *.log *.enc *.sqlite | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copy failed' }
# Files created while developing never go online.
foreach ($dir in 'storage\app\public', 'storage\app\private', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs') {
    $path = Join-Path $app $dir
    if (Test-Path $path) { Get-ChildItem $path -Force -Exclude .gitignore | Remove-Item -Recurse -Force }
    else { New-Item -ItemType Directory -Force $path | Out-Null }
}
Get-ChildItem (Join-Path $app 'bootstrap\cache') -Filter *.php | Remove-Item -Force
# A second lock, in case this folder is ever uploaded somewhere the web can reach.
[IO.File]::WriteAllText((Join-Path $app '.htaccess'), "Require all denied`n")

$appKey = 'base64:' + (Random-Key)
$backupKey = Random-Key
$callback = Random-Key
$envText = @"
APP_NAME="Naturolls POS"
APP_ENV=production
APP_KEY=$appKey
APP_DEBUG=false
APP_URL=$Url
APP_TIMEZONE=Asia/Kolkata

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CHANGE_ME
DB_USERNAME=CHANGE_ME
DB_PASSWORD="CHANGE_ME"

SESSION_DRIVER=file
QUEUE_CONNECTION=sync
CACHE_STORE=file
FILESYSTEM_DISK=local
MAIL_MAILER=log

BACKUP_DRIVER=auto
BACKUP_RETENTION_DAYS=30
BACKUP_KEY="$backupKey"
PAYMENT_CALLBACK_SECRET="$callback"
"@
[IO.File]::WriteAllText((Join-Path $app '.env'), $envText)

Write-Host 'Installing server libraries...'
Push-Location $app
composer install --no-dev --optimize-autoloader --no-interaction --quiet
if ($LASTEXITCODE) { throw 'composer install failed' }

Write-Host 'Preparing the starting database...'
Mysql "DROP DATABASE IF EXISTS $tempDb; CREATE DATABASE $tempDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# An empty password cannot be passed as a Windows variable, so the build database goes in .env for now.
$buildEnv = $envText -replace 'DB_HOST=localhost', 'DB_HOST=127.0.0.1' -replace 'DB_DATABASE=CHANGE_ME', "DB_DATABASE=$tempDb" -replace 'DB_USERNAME=CHANGE_ME', "DB_USERNAME=$DbUser" -replace 'DB_PASSWORD="CHANGE_ME"', "DB_PASSWORD=`"$DbPassword`""
[IO.File]::WriteAllText((Join-Path $app '.env'), $buildEnv)
try {
    php artisan migrate --seed --force --no-interaction | Out-Null
    if ($LASTEXITCODE) { throw 'migrate failed' }
    $logins = php (Join-Path $PSScriptRoot 'hosting\prepare_database.php') $app | ConvertFrom-Json
    & "$MysqlBin\mysqldump.exe" -u $DbUser $pwdArg --skip-comments --single-transaction --default-character-set=utf8mb4 "--result-file=$out\database-start.sql" $tempDb
    if ($LASTEXITCODE) { throw 'mysqldump failed' }
} finally {
    [IO.File]::WriteAllText((Join-Path $app '.env'), $envText)
    Mysql "DROP DATABASE IF EXISTS $tempDb;"
}
Get-ChildItem (Join-Path $app 'storage\logs') -Filter *.log -ErrorAction SilentlyContinue | Remove-Item -Force
Pop-Location

@"
NATUROLLS FIRST LOGIN - KEEP THIS PRIVATE. DO NOT UPLOAD THIS FILE.

Owner login:  admin / $($logins.admin_password)
Owner PIN:    $($logins.admin_pin)
Staff login:  staff / $($logins.staff_password)

Backup key (backups cannot be read without it; keep a copy away from the server):
$backupKey
"@ | Set-Content -Encoding utf8 (Join-Path $out 'FIRST_LOGIN.txt')

Write-Host 'Zipping...'
tar.exe -a -c -f (Join-Path $out 'naturolls_app.zip') -C $out naturolls_app
tar.exe -a -c -f (Join-Path $out 'public_html.zip') -C $web .
Write-Host "Done: $out"
