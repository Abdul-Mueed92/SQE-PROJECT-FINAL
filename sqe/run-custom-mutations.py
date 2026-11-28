"""Run two payment-rule mutations in separate PHP processes and preserve the results."""

import json
import os
import subprocess
from pathlib import Path
from xml.etree import ElementTree


root = Path(__file__).resolve().parents[1]
evidence = root / 'sqe/evidence/mutation/custom'
evidence.mkdir(parents=True, exist_ok=True)
results = []
for operator in ('reject-exact-balance', 'ignore-previous-payments'):
    junit = evidence / f'{operator}.xml'
    junit.unlink(missing_ok=True)
    completed = subprocess.run(
        ['php', 'vendor/bin/phpunit', '-c', 'phpunit.payment-limit-coverage.xml',
         '--bootstrap', 'sqe/mutation-bootstrap.php', '--do-not-cache-result', '--log-junit', str(junit)],
        cwd=root, env={**os.environ, 'SQE_MUTATION_OPERATOR': operator},
        capture_output=True, text=True, encoding='utf-8', errors='replace', check=False,
    )
    (evidence / f'{operator}.log').write_text(completed.stdout + completed.stderr, encoding='utf-8')
    outcome = 'error'
    failures = []
    if junit.is_file() and junit.stat().st_size:
        report = ElementTree.parse(junit)
        failures = [case.attrib['name'] for case in report.iter('testcase') if case.find('failure') is not None]
        errors = []
        for case in report.iter('testcase'):
            error = case.find('error')
            if error is None:
                continue
            if error.get('type') == 'App\\Exceptions\\Job\\PaymentExceedsBalance':
                failures.append(case.attrib['name'])
            else:
                errors.append(case.attrib['name'])
        if completed.returncode != 0 and failures and not errors:
            outcome = 'killed'
        elif completed.returncode == 0:
            outcome = 'survived'
    results.append({'operator': operator, 'outcome': outcome, 'failed_tests': failures})

(evidence / 'summary.json').write_text(json.dumps(results, indent=2) + '\n', encoding='utf-8')
print(json.dumps(results, indent=2))
if any(result['outcome'] != 'killed' for result in results):
    raise SystemExit(1)
