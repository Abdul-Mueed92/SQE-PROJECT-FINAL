param(
    [int]$Port = 8900,
    [string]$ScannerPath,
    [switch]$InitializeAdmin,
    [string]$CoverageReport = 'sqe/evidence/static/coverage.xml',
    [string]$TestReport = 'sqe/evidence/static/junit.xml'
)

$ErrorActionPreference = 'Stop'
Push-Location (Split-Path -Parent $PSScriptRoot)
try {
    $root = (Get-Location).Path
    $base = "http://127.0.0.1:$Port"
    $evidence = Join-Path $PSScriptRoot 'evidence/static'
    New-Item -Force -ItemType Directory $evidence | Out-Null

    $system = Invoke-RestMethod "$base/api/system/status" -TimeoutSec 10
    if ($system.status -ne 'UP') { throw "SonarQube is $($system.status)." }
    $system | ConvertTo-Json | Set-Content -Encoding utf8 (Join-Path $evidence 'sonar-system.json')

    function Get-BasicHeader([string]$Password) {
        @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("admin:$Password")) }
    }

    $password = $env:SQE_SONAR_ADMIN_PASSWORD
    if (-not $password -or $InitializeAdmin) {
        if (-not $password) { $password = [guid]::NewGuid().ToString('N') + 'aA1!' }
        Invoke-RestMethod "$base/api/users/change_password" -Method Post -Headers (Get-BasicHeader 'admin') -Body @{
            login = 'admin'; previousPassword = 'admin'; password = $password
        } | Out-Null
    }
    $tokenName = 'sqe-' + [guid]::NewGuid().ToString('N')
    $auth = Get-BasicHeader $password
    $token = Invoke-RestMethod "$base/api/user_tokens/generate" -Method Post -Headers $auth -Body @{ name = $tokenName }
    $env:SONAR_TOKEN = $token.token
    $clover = (Resolve-Path -LiteralPath $CoverageReport).Path
    $junit = (Resolve-Path -LiteralPath $TestReport).Path
    $arguments = @('-Dsonar.scm.disabled=true', '-Dsonar.qualitygate.wait=true', '-Dsonar.qualitygate.timeout=900')
    $log = Join-Path $evidence 'sonar-scanner.log'
    if ($ScannerPath) {
        $env:SONAR_HOST_URL = $base
        & $ScannerPath @arguments "-Dsonar.php.coverage.reportPaths=$clover" "-Dsonar.php.tests.reportPath=$junit" | Tee-Object $log
    } else {
        $env:SONAR_HOST_URL = 'http://sqe-sonarqube:9000'
        foreach ($report in @(@{ Source = $clover; Target = 'coverage.sonar.xml' }, @{ Source = $junit; Target = 'junit.sonar.xml' })) {
            $path = $report.Source
            $content = (Get-Content -LiteralPath $path -Raw).Replace($root, '/usr/src').Replace($root.Replace('\', '/'), '/usr/src')
            $content = [regex]::Replace($content, '(?<path>/usr/src[^"<]+)', { param($match) $match.Value.Replace('\', '/') })
            [IO.File]::WriteAllText((Join-Path $evidence $report.Target), $content)
        }
        docker run --rm --network sqe-assignment -e SONAR_TOKEN -e SONAR_HOST_URL -v "${root}:/usr/src" sonarsource/sonar-scanner-cli `
            @arguments '-Dsonar.php.coverage.reportPaths=sqe/evidence/static/coverage.sonar.xml' `
            '-Dsonar.php.tests.reportPath=sqe/evidence/static/junit.sonar.xml' | Tee-Object $log
    }
    $scannerStatus = $LASTEXITCODE

    $metrics = 'ncloc,complexity,cognitive_complexity,duplicated_lines_density,code_smells,bugs,vulnerabilities,coverage,sqale_index,reliability_rating,security_rating,sqale_rating'
    Invoke-RestMethod "$base/api/measures/component?component=akaunting-sqe&metricKeys=$metrics" -Headers $auth |
        ConvertTo-Json -Depth 8 | Set-Content -Encoding utf8 (Join-Path $evidence 'sonar-measures.json')
    Invoke-RestMethod "$base/api/qualitygates/project_status?projectKey=akaunting-sqe" -Headers $auth |
        ConvertTo-Json -Depth 8 | Set-Content -Encoding utf8 (Join-Path $evidence 'sonar-quality-gate.json')
    Invoke-RestMethod "$base/api/measures/component?component=akaunting-sqe:app/Jobs/Banking/CreateBankingDocumentTransaction.php&metricKeys=complexity,cognitive_complexity" -Headers $auth |
        ConvertTo-Json -Depth 8 | Set-Content -Encoding utf8 (Join-Path $evidence 'sonar-payment-file.json')
    exit $scannerStatus
} finally {
    if ($token) {
        try { Invoke-RestMethod "$base/api/user_tokens/revoke" -Method Post -Headers $auth -Body @{ name = $tokenName } | Out-Null } catch { Write-Warning 'Could not revoke the temporary Sonar token.' }
    }
    Remove-Item Env:SONAR_TOKEN -ErrorAction SilentlyContinue
    Pop-Location
}
