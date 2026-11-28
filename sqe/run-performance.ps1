param(
    [Parameter(Mandatory=$true)]
    [string]$K6,
    [int]$Port = 8765
)

$ErrorActionPreference = 'Stop'
$url = "http://127.0.0.1:$Port"
$authentication = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes('test@company.com:123456'))
$headers = @{ Authorization = "Basic $authentication"; Accept = 'application/json' }
$category = (Invoke-RestMethod "$url/api/categories" -Headers $headers).data | Where-Object type -eq 'income' | Select-Object -First 1
if (-not $category) { throw 'Seed the synthetic account before starting k6.' }

$invoice = @{
    type = 'invoice'; status = 'sent'; document_number = 'SQE-PERF-' + [guid]::NewGuid().ToString('N');
    issued_at = '2026-11-01 12:00:00'; due_at = '2026-11-30 12:00:00'; amount = 100;
    currency_code = 'USD'; currency_rate = 1; contact_id = 1; contact_name = 'Test Customer';
    category_id = $category.id; items = @(@{ name = 'SQE performance item'; quantity = '1'; price = 100 });
}
$created = Invoke-RestMethod "$url/api/documents?search=type%3Ainvoice" -Method Post -Headers $headers -ContentType 'application/json' -Body ($invoice | ConvertTo-Json -Depth 5)
$env:SQE_URL = $url
$env:SQE_INVOICE = [string]$created.data.id
$directory = Join-Path $PSScriptRoot 'evidence/performance'
New-Item -ItemType Directory -Force -Path $directory | Out-Null
$results = @()
foreach ($users in @(1, 5)) {
    foreach ($repetition in @(1, 2)) {
        $env:SQE_USERS = [string]$users
        $name = "vus-$users-run-$repetition"
        $env:SQE_K6_SUMMARY = Join-Path $directory "$name.json"
        & $K6 run --quiet (Join-Path $PSScriptRoot 'invoice-read.k6.js') |
            Out-File (Join-Path $directory "$name.log") -Encoding utf8
        $results += [pscustomobject]@{ Run = $name; ExitCode = $LASTEXITCODE }
    }
}
$results | ConvertTo-Json | Set-Content (Join-Path $directory 'exit-codes.json') -Encoding utf8
$results | Format-Table -AutoSize
if ($results.ExitCode | Where-Object { $_ -ne 0 }) { exit 1 }
