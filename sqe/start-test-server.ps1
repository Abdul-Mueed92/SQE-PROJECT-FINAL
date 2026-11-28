param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8765,
    [ValidateRange(1, 3000)]
    [int]$ApiRequestsPerMinute = 60
)

$ErrorActionPreference = 'Stop'
Push-Location (Split-Path -Parent $PSScriptRoot)
try {
    $testDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ('akaunting-sqe-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $testDirectory | Out-Null
    $database = Join-Path $testDirectory 'database.sqlite'
    New-Item -ItemType File -Path $database | Out-Null

    $env:APP_ENV = 'testing'
    $env:APP_INSTALLED = 'true'
    $env:APP_URL = "http://127.0.0.1:$Port"
    $env:APP_THROTTLES_API = [string]$ApiRequestsPerMinute
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $database
    $env:MAIL_MAILER = 'array'
    $env:QUEUE_CONNECTION = 'sync'

    php artisan migrate --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'SQLite migration failed.' }
    php artisan db:seed --class='Database\Seeds\TestCompany' --force --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic fixture seeding failed.' }

    Write-Output "Test database $database"
    php -S "127.0.0.1:$Port" -t public server.php
} finally {
    Pop-Location
}
