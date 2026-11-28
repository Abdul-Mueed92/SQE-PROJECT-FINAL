# Akaunting software quality engineering project

The two workflows are invoice creation and payment recording. The tested Akaunting baseline is `085068f021d3e5e79f83a6f901ad948e6a4c8b6e`. The [upstream repository](https://github.com/akaunting/akaunting) uses the Business Source License 1.1 for this version. See [the report](sqe/report.html) for scope, assignment eligibility, results and open work.

## Local setup and reset

Use PHP 8.2 with the extensions declared in `composer.json`, Composer, Node.js, Python 3.12, SQLite and Xdebug for branch coverage. Windows PowerShell commands from the repository root:

```powershell
Copy-Item .env.testing .env -ErrorAction Stop  # Fresh checkout only
composer install --no-interaction --prefer-dist
npm.cmd ci
npm.cmd run production
python -m pip install PyYAML==6.0.3
npm.cmd ci --prefix sqe/browser
node sqe/browser/node_modules/@playwright/test/cli.js install chromium
```

For browser and load checks, start the loopback server from a separate shell. Each launch creates a fresh SQLite database in the OS temporary directory and runs the app migrations plus `Database\Seeds\TestCompany`. Restart it to reset the data. The synthetic staff and customer use `test@company.com` and `customer@company.com`, both with `123456`.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\sqe\start-test-server.ps1
```

On Linux use `bash sqe/start-test-server.sh`. Do not use these synthetic accounts on a deployed instance.

## Run checks

```powershell
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Feature/Team/InvoicePaymentApiTest.php
python api/run_api_tests.py
python api/verify_regeneration.py
node sqe/browser/node_modules/@playwright/test/cli.js test --config sqe/browser/playwright.config.cjs
node sqe/browser/check-roles.mjs
node sqe/browser/audit-accessibility.mjs before
```

The PHPUnit configuration resets the data with in-memory SQLite. `api/run_api_tests.py` generates cases from `api/openapi-sqe.yaml`, runs them through Laravel's HTTP kernel, and saves the original cases, per-case outcomes and JUnit XML. The older sample fixture JSON files are retained as historical examples only.

The [CI workflow](.github/workflows/sqe-quality.yml) shows the coverage, mutation and Sonar commands. Sonar requires an instance and configured `SONAR_HOST_URL` and `SONAR_TOKEN` secrets. The focused payment gate applies to `checkAmount()` in `CreateBankingDocumentTransaction.php`.

For k6 use a separate synthetic server to measure operation latency without the ordinary rate limit dominating the run:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\sqe\start-test-server.ps1 -Port 8766 -ApiRequestsPerMinute 2000
# In another shell, supply the path to the official k6 executable.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\sqe\run-performance.ps1 -K6 'C:\tools\k6.exe' -Port 8766
```

Find raw reports under [`sqe/evidence`](sqe/evidence/) and the earlier focused Xdebug and JUnit reports at the repository root. The report is explicit about whether evidence came from a fresh execution, a saved prior run, or work that remains open.
