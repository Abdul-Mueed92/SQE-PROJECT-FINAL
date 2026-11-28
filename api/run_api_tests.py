"""Run generated HTTP cases against Laravel's fresh, in-memory SQL database."""

import argparse
import json
import os
import subprocess
from pathlib import Path

import yaml

from generate_cases import generate


def main():
    root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser()
    parser.add_argument('--spec', type=Path, default=root / 'api/openapi-sqe.yaml')
    parser.add_argument('--evidence', type=Path, default=root / 'sqe/evidence/api')
    args = parser.parse_args()
    evidence = args.evidence.resolve()
    evidence.mkdir(parents=True, exist_ok=True)

    cases_file = evidence / 'executed-cases.json'
    cases = generate(yaml.safe_load(args.spec.read_text(encoding='utf-8')))
    cases_file.write_text(json.dumps(cases, indent=2) + '\n', encoding='utf-8')
    print(f'Generated {len(cases)} cases from {args.spec}', flush=True)
    completed = subprocess.run(
        ['php', 'vendor/bin/phpunit', 'tests/Feature/Team/GeneratedInvoicePaymentApiTest.php',
         '--do-not-cache-result', '--log-junit', str(evidence / 'generated-junit.xml')],
        cwd=root,
        env={**os.environ, 'SQE_API_CASES': str(cases_file),
             'SQE_API_RESULTS': str(evidence / 'generated-results.json')},
        check=False,
    )
    raise SystemExit(completed.returncode)


if __name__ == '__main__':
    main()
